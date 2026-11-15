<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Exception\PermissionDeniedException;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\IL10N;

/**
 * Who may see, download and restore backups. A backup holds every club of the installation, so restoring it also
 * replaces clubs the person has no rights in. Nextcloud administrators therefore always may; holders of the
 * permission 'verein.backup.restore' only while the installation has exactly one club - as soon as a second club
 * exists, the permission grants nothing here.
 */
class BackupAccess {
    public const PERMISSION = 'verein.backup.restore';

    private IL10N $l;

    public function __construct(
        private RoleService $roles,
        private ClubMapper $clubs,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
    }

    public function allows(string $userId): bool {
        if ($userId === '') {
            return false;
        }
        if ($this->roles->isNextcloudAdmin($userId)) {
            return true;
        }
        $clubs = $this->clubs->findAll();
        return count($clubs) === 1 && $this->roles->userHasPermission($userId, self::PERMISSION, $clubs[0]->getId());
    }

    /** @throws PermissionDeniedException */
    public function check(string $userId): void {
        if (!$this->allows($userId)) {
            throw new PermissionDeniedException($this->l->t('Backups cover all clubs. With more than one club only Nextcloud administrators may use them.'));
        }
    }
}
