<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Service\CustomFieldService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The fields a club defines for its members. Reading needs the permission to see members (the form and the list
 * show them), defining them the one to manage the club. The values are written through the member endpoints.
 */
class CustomFieldController extends Controller {
    use RespondsWithErrors;

    public function __construct(
        $AppName,
        IRequest $request,
        private CustomFieldService $fields
    ) {
        parent::__construct($AppName, $request);
    }

    private function clubId(): int {
        return (int)$this->request->getParam('clubId', 0);
    }

    private function params(): array {
        $out = [];
        foreach (['label', 'type', 'options', 'required', 'showInList', 'sortOrder'] as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private function respond(callable $action, int $status = 200): JSONResponse {
        try {
            return new JSONResponse(['status' => 'ok', 'data' => $action()], $status);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.view')]
    public function index(): JSONResponse {
        return $this->respond(fn () => array_map(fn ($f) => $f->jsonSerialize(), $this->fields->fields($this->clubId())));
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function create(): JSONResponse {
        return $this->respond(fn () => $this->fields->create($this->clubId(), $this->params()), 201);
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function update(int $id): JSONResponse {
        return $this->respond(fn () => $this->fields->update($this->clubId(), $id, $this->params()));
    }

    /**
     * Removes the field together with every member's value of it.
     *
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function destroy(int $id): JSONResponse {
        return $this->respond(function () use ($id) {
            $this->fields->delete($this->clubId(), $id);
            return null;
        });
    }
}
