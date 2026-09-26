<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Attributes\RequirePermission;
use PHPUnit\Framework\TestCase;

/**
 * Guard against forgetting access control on a new endpoint: every route in
 * appinfo/routes.php must either carry #[RequirePermission] or be on the
 * short, explicit list below of endpoints that are safe without one.
 */
class RoutePermissionsTest extends TestCase {
    /**
     * Endpoints that need no RequirePermission, with the reason.
     */
    private const OPEN = [
        'page#index' => 'only renders the app shell, needs a login but no data',
        'club#index' => 'returns only the clubs the current user has a role in',
        'me#index' => 'returns only the record of the person linked to the current account (self-service)',
        'me#export' => 'downloads only the record of the person linked to the current account',
    ];

    /**
     * Endpoints restricted to Nextcloud administrators: they must NOT be
     * marked @NoAdminRequired, so Nextcloud itself rejects non-admins.
     */
    private const ADMIN_ONLY = [
        'club#create',
        'club#destroy',
        'role#store',
        'role#update',
        'role#destroy',
        'backup#index',
        'backup#create',
        'backup#download',
    ];

    /** @return array<string, array{string, string}> */
    public static function routes(): array {
        $config = require __DIR__ . '/../../appinfo/routes.php';
        $result = [];
        foreach ($config['routes'] as $route) {
            [$controller, $method] = explode('#', $route['name']);
            $result[$route['verb'] . ' ' . $route['url'] . ' (' . $route['name'] . ')'] = [$controller, $method];
        }
        return $result;
    }

    private function reflect(string $controller, string $method): \ReflectionMethod {
        $class = 'OCA\\Verein\\Controller\\' . ucfirst($controller) . 'Controller';
        $this->assertTrue(class_exists($class), "Route points to missing controller $class");
        $this->assertTrue(method_exists($class, $method), "Route points to missing method $class::$method");
        return new \ReflectionMethod($class, $method);
    }

    /**
     * @dataProvider routes
     */
    public function testEveryRouteIsProtected(string $controller, string $method): void {
        $key = $controller . '#' . $method;
        $reflection = $this->reflect($controller, $method);
        $attributes = $reflection->getAttributes(RequirePermission::class);

        if (isset(self::OPEN[$key])) {
            $this->assertCount(0, $attributes, "$key is listed as open but has a permission attribute - update the list");
            return;
        }
        if (in_array($key, self::ADMIN_ONLY, true)) {
            // the annotation as its own docblock line (prose mentioning it doesn't count)
            $doc = (string)$reflection->getDocComment();
            $this->assertSame(0, preg_match('/^\s*\*\s*@NoAdminRequired\b/m', $doc), "$key must stay admin-only");
            $this->assertEmpty($reflection->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class), "$key must stay admin-only");
            return;
        }

        $this->assertNotEmpty($attributes, "$key has no #[RequirePermission] - add one, or list it in RoutePermissionsTest with a reason");
    }

    public function testOpenAndAdminOnlyListsOnlyContainExistingRoutes(): void {
        $known = [];
        foreach (self::routes() as [$controller, $method]) {
            $known[] = $controller . '#' . $method;
        }
        foreach (array_merge(array_keys(self::OPEN), self::ADMIN_ONLY) as $key) {
            $this->assertContains($key, $known, "$key is on an exception list but is not a route anymore");
        }
    }

    /**
     * @dataProvider routes
     */
    public function testStateChangingRoutesKeepTheCsrfCheck(string $controller, string $method): void {
        $config = require __DIR__ . '/../../appinfo/routes.php';
        foreach ($config['routes'] as $route) {
            if ($route['name'] !== $controller . '#' . $method) {
                continue;
            }
            if (strtoupper($route['verb']) === 'GET') {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $doc = (string)$this->reflect($controller, $method)->getDocComment();
        $this->assertSame(
            0,
            preg_match('/^\s*\*\s*@NoCSRFRequired\b/m', $doc),
            "$controller#$method changes data, so it must not be marked @NoCSRFRequired (a sibling site could forge requests)"
        );
    }

    public function testClubScopedRoutesCanGetTheirClubId(): void {
        // Club-scoped permissions read `clubId` from the request; routes that
        // carry it in the URL must name the placeholder exactly that.
        $config = require __DIR__ . '/../../appinfo/routes.php';
        foreach ($config['routes'] as $route) {
            if (str_starts_with($route['url'], '/clubs/')) {
                $this->assertStringContainsString('{clubId}', $route['url'], $route['name']);
            }
        }
    }
}
