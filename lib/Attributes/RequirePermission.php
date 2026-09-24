<?php

namespace OCA\Verein\Attributes;

use Attribute;

/**
 * PHP Attribute for declaring required permissions on controllers or methods.
 *
 * By default the permission must be held for the club named by the request's
 * `clubId` parameter (a role assignment is always for one specific club).
 * Pass clubScoped: false for actions that aren't about one club (e.g.
 * maintaining the shared role definitions); the permission is then accepted
 * if the user holds it in any club.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class RequirePermission {
    private string $permission;
    private bool $clubScoped;

    public function __construct(string $permission, bool $clubScoped = true) {
        $this->permission = $permission;
        $this->clubScoped = $clubScoped;
    }

    public function getPermission(): string {
        return $this->permission;
    }

    public function isClubScoped(): bool {
        return $this->clubScoped;
    }
}
