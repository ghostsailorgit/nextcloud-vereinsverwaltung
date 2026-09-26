<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
/**
 * RoleService.php - Multi-Role RBAC Service
 * 
 * Verwaltet Rollen, Permissions und Zuweisungen für Musik- und Sportvereine.
 * Unterstützt 10+ vordefinierte Rollen mit granularen Permissions.
 * 
 * v0.2.0 Feature: Multi-Role RBAC mit GUI
 * 
 * @category Service
 * @package Verein\Service\RBAC
 * @author Stefan Schulz <stefan@example.com>
 * @license AGPL-3.0
 */

namespace OCA\Verein\Service\RBAC;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Db\Role;
use OCA\Verein\Db\RoleMapper;
use OCA\Verein\Db\UserRole;
use OCA\Verein\Db\UserRoleMapper;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Service\AuditLogService;
use OCA\Verein\Exception\PermissionDeniedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

class RoleService {
    private IL10N $l;

            
    /**
     * The permissions actually enforced by #[RequirePermission] across the app's
     * controllers - the only ones that matter for userHasPermission(). The
     * templates above additionally reference some aspirational, not-yet-built
     * categories (score.*, training.*, athlete.assign, musician.assign) which
     * are harmless to keep as preset suggestions but grant nothing real.
     */
    private const ENFORCED_PERMISSIONS = [
        'verein.member.view',
        'verein.member.manage',
        'verein.finance.read',
        'verein.finance.write',
        'verein.finance.delete',
        'verein.finance.export',
        'verein.role.manage',
        'verein.sepa.export',
        'verein.club.manage',
        'verein.audit.view',
    ];

    private const ENFORCED_PERMISSION_DESCRIPTIONS = [
        'verein.member.view' => 'Mitglieder einsehen',
        'verein.member.manage' => 'Mitglieder anlegen, bearbeiten, löschen',
        'verein.finance.read' => 'Finanzdaten einsehen',
        'verein.finance.write' => 'Finanzdaten anlegen und bearbeiten',
        'verein.finance.delete' => 'Finanzdaten löschen',
        'verein.finance.export' => 'Finanzdaten exportieren',
        'verein.role.manage' => 'Rollen und Berechtigungen verwalten',
        'verein.sepa.export' => 'SEPA-Export erstellen',
        'verein.club.manage' => 'Vereinsdaten, Bankkonten und Kalender verwalten',
        'verein.audit.view' => 'Änderungsprotokoll einsehen',
    ];

    private IUserSession $userSession;
    private RoleMapper $roleMapper;
    private UserRoleMapper $userRoleMapper;
    private IGroupManager $groupManager;
    private LoggerInterface $logger;
    private MemberMapper $memberMapper;
    private MembershipMapper $membershipMapper;
    private ClubMapper $clubMapper;

    public function __construct(
        RoleMapper $roleMapper,
        UserRoleMapper $userRoleMapper,
        IGroupManager $groupManager,
        IUserSession $userSession,
        LoggerInterface $logger,
        MemberMapper $memberMapper,
        MembershipMapper $membershipMapper,
        ClubMapper $clubMapper,
        private ?AuditLogService $auditLog = null,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        $this->memberMapper = $memberMapper;
        $this->membershipMapper = $membershipMapper;
        $this->clubMapper = $clubMapper;
        $this->roleMapper = $roleMapper;
        $this->userRoleMapper = $userRoleMapper;
        $this->groupManager = $groupManager;
        $this->userSession = $userSession;
        $this->logger = $logger;
    }

    /**
     * Checks whether a user holds a role granting the given permission.
     * Nextcloud admins always pass, since they administer the whole instance
     * anyway; everyone else needs a role - either assigned explicitly (see
     * assignRole()) or derived automatically from their membership (see
     * derivedRoles()). Roles are per club: with a $clubId only that club's
     * roles count, with null the permission may be held in any club.
     */
    public function userHasPermission(string $userId, string $permission, ?int $clubId = null): bool {
        if ($this->groupManager->isAdmin($userId)) {
            return true;
        }

        foreach ($this->effectiveRoles($userId, $clubId) as $role) {
            if (in_array($permission, $role->getPermissionsArray(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The clubs in which the user holds the permission, or null if that is
     * every club (Nextcloud admins).
     *
     * @return int[]|null
     */
    public function getClubIdsWithPermission(string $userId, string $permission): ?array {
        if ($this->groupManager->isAdmin($userId)) {
            return null;
        }

        $clubIds = [];
        foreach ($this->candidateClubIds($userId) as $clubId) {
            foreach ($this->effectiveRoles($userId, $clubId) as $role) {
                if (in_array($permission, $role->getPermissionsArray(), true)) {
                    $clubIds[] = $clubId;
                    break;
                }
            }
        }

        return $clubIds;
    }

    /**
     * Every club the user has any role in, or null for Nextcloud admins
     * (all clubs).
     *
     * @return int[]|null
     */
    public function getAccessibleClubIds(string $userId): ?array {
        if ($this->groupManager->isAdmin($userId)) {
            return null;
        }
        $ids = [];
        foreach ($this->candidateClubIds($userId) as $clubId) {
            if ($this->effectiveRoles($userId, $clubId) !== []) {
                $ids[] = $clubId;
            }
        }
        return $ids;
    }

    /**
     * All permissions the user holds in one club (everything for Nextcloud
     * admins) - lets the frontend hide what the user couldn't use anyway.
     *
     * @return string[]
     */
    public function getPermissionsForClub(string $userId, int $clubId): array {
        if ($this->groupManager->isAdmin($userId)) {
            return self::ENFORCED_PERMISSIONS;
        }

        $permissions = [];
        foreach ($this->effectiveRoles($userId, $clubId) as $role) {
            foreach ($role->getPermissionsArray() as $permission) {
                $permissions[$permission] = $permission;
            }
        }
        return array_values($permissions);
    }

    public function isNextcloudAdmin(string $userId): bool {
        return $this->groupManager->isAdmin($userId);
    }

    /**
     * The roles a user effectively holds: explicit assignments plus the
     * roles derived from their membership. Null club = across all clubs.
     *
     * @return Role[]
     */
    private function effectiveRoles(string $userId, ?int $clubId): array {
        $roles = [];

        $assignments = $clubId === null
            ? $this->userRoleMapper->findByUserId($userId)
            : $this->userRoleMapper->findByUserAndClub($userId, $clubId);
        foreach ($assignments as $userRole) {
            try {
                $role = $this->roleMapper->find($userRole->getRoleId());
            } catch (DoesNotExistException $e) {
                continue;
            }
            $roles[$role->getId()] = $role;
        }

        foreach ($this->derivedRoles($userId, $clubId) as $role) {
            $roles[$role->getId()] = $role;
        }

        return array_values($roles);
    }

    /**
     * Roles granted automatically by being an active member of a club with a
     * Nextcloud account linked to the person: each club can map its
     * membership roles (Mitglied/Kassierer/Vorstand) to an app role (see
     * Club::getRoleMappingArray()). Nothing is derived unless a club has
     * configured such a mapping, and nothing once the person has left,
     * passed away, or the membership was deactivated (MemberService::deactivate())
     * - the rights disappear together with the membership.
     *
     * @return Role[]
     */
    private function derivedRoles(string $userId, ?int $clubId): array {
        $person = $this->memberMapper->findByUserId($userId);
        if ($person === null || $person->getDeceased()) {
            return [];
        }

        $roles = [];
        foreach ($this->membershipMapper->findByMember($person->getId()) as $membership) {
            if ($clubId !== null && $membership->getClubId() !== $clubId) {
                continue;
            }
            if (!empty($membership->getLeaveDate()) || $membership->getDeactivated()) {
                continue;
            }
            $role = $this->mappedRole($membership->getClubId(), $membership->getRole());
            if ($role !== null) {
                $roles[$role->getId()] = $role;
            }
        }
        return array_values($roles);
    }

    private function mappedRole(int $clubId, string $membershipRole): ?Role {
        try {
            $club = $this->clubMapper->find($clubId);
        } catch (DoesNotExistException $e) {
            return null;
        }
        $roleId = $club->getRoleMappingArray()[$membershipRole] ?? null;
        if ($roleId === null) {
            return null;
        }
        try {
            return $this->roleMapper->find((int)$roleId);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Clubs in which the user could hold a role at all (assigned or via
     * their linked person's memberships) - each is then checked in detail.
     *
     * @return int[]
     */
    private function candidateClubIds(string $userId): array {
        $ids = [];
        foreach ($this->userRoleMapper->findByUserId($userId) as $userRole) {
            $ids[(int)$userRole->getClubId()] = (int)$userRole->getClubId();
        }
        $person = $this->memberMapper->findByUserId($userId);
        if ($person !== null) {
            foreach ($this->membershipMapper->findByMember($person->getId()) as $membership) {
                $ids[$membership->getClubId()] = $membership->getClubId();
            }
        }
        return array_values($ids);
    }

    /**
     * @return string[]
     */
    public function getAvailablePermissions(): array {
        return self::ENFORCED_PERMISSIONS;
    }


    /**
     * @param string[] $permissions
     * @throws ValidationException
     */
    public function createRole(string $name, string $clubType, ?string $description, array $permissions): Role {
        if (trim($name) === '') {
            throw new ValidationException($this->l->t('Name is required'));
        }

        $role = new Role();
        $role->setName($name);
        $role->setDescription($description ?? '');
        $role->setClubType($clubType);
        $role->setPermissionsArray($permissions);
        $now = date('Y-m-d H:i:s');
        $role->setCreatedAt($now);
        $role->setUpdatedAt($now);

        $role = $this->roleMapper->insert($role);
        $this->auditLog?->record(null, 'role', $role->getId(), 'create', $role->jsonSerialize());
        return $role;
    }

    /**
     * @param string[]|null $permissions
     * @throws ValidationException
     */
    public function updateRole(int $id, ?string $name, ?string $description, ?array $permissions): Role {
        $role = $this->roleMapper->find($id);
        $before = $role->jsonSerialize();

        if ($name !== null && trim($name) !== '') {
            $role->setName($name);
        }
        if ($description !== null) {
            $role->setDescription($description);
        }
        if ($permissions !== null) {
            $role->setPermissionsArray($permissions);
        }
        $role->setUpdatedAt(date('Y-m-d H:i:s'));

        $role = $this->roleMapper->update($role);
        if ($this->auditLog !== null) {
            $changes = $this->auditLog->diff($before, $role->jsonSerialize());
            if ($changes !== []) {
                $this->auditLog->record(null, 'role', $role->getId(), 'update', $changes);
            }
        }
        return $role;
    }

    public function deleteRole(int $id): void {
        $role = $this->roleMapper->find($id);
        $this->roleMapper->delete($role);
        $this->auditLog?->record(null, 'role', $id, 'delete');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUserRoles(string $userId, int $clubId = 0): array {
        $userRoles = $clubId > 0
            ? $this->userRoleMapper->findByUserAndClub($userId, $clubId)
            : $this->userRoleMapper->findByUserId($userId);

        $result = [];
        foreach ($userRoles as $userRole) {
            try {
                $role = $this->roleMapper->find($userRole->getRoleId());
            } catch (DoesNotExistException $e) {
                $this->logger->warning('RBAC: user role references missing role', [
                    'userId' => $userId,
                    'roleId' => $userRole->getRoleId(),
                ]);
                continue;
            }
            $result[] = array_merge($userRole->jsonSerialize(), ['role' => $role->jsonSerialize()]);
        }

        return $result;
    }

    /**
     * Everyone holding a role in the club, one entry per user: the names of
     * the explicitly assigned roles ('roles', removable) and of the roles
     * derived automatically from their membership ('automaticRoles').
     *
     * @return array<int, array{userId: string, roles: string[], automaticRoles: string[]}>
     */
    public function getClubAssignments(int $clubId): array {
        $byUser = [];
        foreach ($this->userRoleMapper->findByClubId($clubId) as $userRole) {
            try {
                $role = $this->roleMapper->find($userRole->getRoleId());
            } catch (DoesNotExistException $e) {
                continue;
            }
            $byUser[$userRole->getUserId()]['roles'][] = $role->getName();
        }

        foreach ($this->memberMapper->findByClub($clubId) as $member) {
            $uid = $member->getUserId();
            if ($uid === null || $uid === '' || $member->isFormer()) {
                continue;
            }
            $role = $this->mappedRole($clubId, $member->getRole());
            if ($role !== null) {
                $byUser[$uid]['automaticRoles'][] = $role->getName();
            }
        }

        $result = [];
        foreach ($byUser as $userId => $entry) {
            $result[] = [
                'userId' => (string)$userId,
                'roles' => $entry['roles'] ?? [],
                'automaticRoles' => $entry['automaticRoles'] ?? [],
            ];
        }
        return $result;
    }
    /**
     * @throws ValidationException
     */
    public function assignRole(string $userId, int $roleId, int $clubId): UserRole {
        if ($clubId <= 0) {
            throw new ValidationException($this->l->t('Club is required'));
        }
        // Confirm the role actually exists before assigning it
        $this->roleMapper->find($roleId);

        if ($this->userRoleMapper->existsForUserAndRole($userId, $roleId, $clubId)) {
            throw new ValidationException($this->l->t('The role is already assigned to this user'));
        }

        $userRole = new UserRole();
        $userRole->setUserId($userId);
        $userRole->setRoleId($roleId);
        $userRole->setClubId($clubId);
        $currentUser = $this->userSession->getUser();
        $userRole->setGrantedBy($currentUser !== null ? $currentUser->getUID() : 'system');

        $userRole = $this->userRoleMapper->insert($userRole);
        $this->auditLog?->record($clubId, 'user_role', $userRole->getId(), 'create', $userRole->jsonSerialize());
        return $userRole;
    }

    public function removeUserRoles(string $userId, int $clubId): void {
        $this->auditLog?->record($clubId, 'user_role', 0, 'delete', ['userId' => $userId]);
        $this->userRoleMapper->deleteByUserAndClub($userId, $clubId);
    }
    
    
    
    
}
