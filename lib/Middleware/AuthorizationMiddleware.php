<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\Verein\Middleware;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Exception\PermissionDeniedException;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class AuthorizationMiddleware extends Middleware {
    private RoleService $roleService;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private IRequest $request;

    public function __construct(
        RoleService $roleService,
        IUserSession $userSession,
        LoggerInterface $logger,
        IRequest $request
    ) {
        $this->request = $request;
        $this->roleService = $roleService;
        $this->userSession = $userSession;
        $this->logger = $logger;
    }

    /**
     * Nextcloud ignores what beforeController() returns - the only way to stop
     * a request here is to throw, which afterException() turns into the JSON
     * error response. (Returning a response from here silently let every
     * request through.)
     *
     * @param object $controller
     * @throws PermissionDeniedException
     * @throws ValidationException
     */
    public function beforeController($controller, string $methodName) {
        $requirements = $this->collectPermissionAttributes($controller, $methodName);
        if ($requirements === []) {
            return null;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            $this->logPermissionViolation('unauthenticated', 'N/A', $controller, $methodName);
            throw new PermissionDeniedException('Authentication required');
        }

        $userId = $user->getUID();
        foreach ($requirements as $requirement) {
            $permission = $requirement->getPermission();
            $clubId = null;
            if ($requirement->isClubScoped()) {
                $clubId = (int)$this->request->getParam('clubId', 0);
                if ($clubId <= 0) {
                    throw new ValidationException('Verein (clubId) fehlt');
                }
            }
            if (!$this->roleService->userHasPermission($userId, $permission, $clubId)) {
                $this->logPermissionViolation($userId, $permission, $controller, $methodName);
                throw new PermissionDeniedException(sprintf('Missing permission: %s', $permission));
            }
        }

        return null;
    }

    /**
     * @return RequirePermission[]
     */
    private function collectPermissionAttributes($controller, string $methodName): array {
        $attributes = [];

        try {
            $method = new ReflectionMethod($controller, $methodName);
            foreach ($method->getAttributes(RequirePermission::class) as $attribute) {
                $attributes[] = $attribute->newInstance();
            }
        } catch (ReflectionException $e) {
            $this->logger->debug('RBAC: unable to read method attributes', [
                'controller' => get_class($controller),
                'method' => $methodName,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $class = new ReflectionClass($controller);
            foreach ($class->getAttributes(RequirePermission::class) as $attribute) {
                $attributes[] = $attribute->newInstance();
            }
        } catch (ReflectionException $e) {
            $this->logger->debug('RBAC: unable to read class attributes', [
                'controller' => get_class($controller),
                'error' => $e->getMessage(),
            ]);
        }

        return $attributes;
    }

    /**
     * Turns the exceptions thrown by beforeController() into JSON error
     * responses; anything else is left to Nextcloud.
     *
     * @param object $controller
     */
    public function afterException($controller, string $methodName, \Exception $exception) {
        if ($exception instanceof PermissionDeniedException || $exception instanceof ValidationException) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        }
        throw $exception;
    }

    private function logPermissionViolation(
        string $userId,
        string $permission,
        object $controller,
        string $methodName
    ): void {
        $controllerName = get_class($controller);
        $timestamp = date('Y-m-d H:i:s');
        
        $logEntry = [
            'timestamp' => $timestamp,
            'user' => $userId,
            'permission' => $permission,
            'controller' => $controllerName,
            'method' => $methodName,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'event_type' => 'permission_denied',
        ];

        $this->logger->warning('RBAC: Permission denied', $logEntry);
    }
}
