<?php
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

use OCA\Verein\Db\Role;
use OCA\Verein\Db\RoleMapper;
use OCA\Verein\Db\UserRole;
use OCA\Verein\Db\UserRoleMapper;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Exception\PermissionDeniedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class RoleService {
    
    /**
     * Vordefinierte Rollen für Musikvereine (6 Rollen)
     */
    private const MUSIC_CLUB_ROLES = [
        'admin' => [
            'label' => 'Administrator',
            'description' => 'Vollständiger Zugriff auf alle Funktionen',
            'permissions' => [
                'member.create', 'member.read', 'member.update', 'member.delete',
                'finance.create', 'finance.read', 'finance.update', 'finance.delete',
                'export.sepa', 'export.pdf',
                'role.assign', 'role.delete',
                'settings.manage',
                'audit.view'
            ]
        ],
        'treasurer' => [
            'label' => 'Kassier',
            'description' => 'Finanzverwaltung und Exports',
            'permissions' => [
                'member.read',
                'finance.create', 'finance.read', 'finance.update',
                'export.sepa', 'export.pdf',
                'audit.view'
            ]
        ],
        'musician' => [
            'label' => 'Musiker',
            'description' => 'Lesezugriff auf Mitglieder und Noten',
            'permissions' => [
                'member.read',
                'finance.read',
                'score.read',
                'score.update'
            ]
        ],
        'conductor' => [
            'label' => 'Dirigent',
            'description' => 'Verwaltung von Musikern und Noten',
            'permissions' => [
                'member.read',
                'finance.read',
                'score.create', 'score.read', 'score.update',
                'musician.assign'
            ]
        ],
        'secretary' => [
            'label' => 'Sekretär',
            'description' => 'Mitgliederverwaltung',
            'permissions' => [
                'member.create', 'member.read', 'member.update',
                'finance.read',
                'export.pdf'
            ]
        ],
        'viewer' => [
            'label' => 'Betrachter',
            'description' => 'Schreibgeschützter Zugriff',
            'permissions' => [
                'member.read',
                'finance.read'
            ]
        ]
    ];
    
    /**
     * Vordefinierte Rollen für Sportvereine (4 Rollen)
     */
    private const SPORT_CLUB_ROLES = [
        'admin' => [
            'label' => 'Administrator',
            'description' => 'Vollständiger Zugriff auf alle Funktionen',
            'permissions' => [
                'member.create', 'member.read', 'member.update', 'member.delete',
                'finance.create', 'finance.read', 'finance.update', 'finance.delete',
                'export.sepa', 'export.pdf',
                'role.assign', 'role.delete',
                'settings.manage',
                'audit.view'
            ]
        ],
        'coach' => [
            'label' => 'Trainer',
            'description' => 'Verwaltung von Athleten und Training',
            'permissions' => [
                'member.read', 'member.update',
                'finance.read',
                'athlete.assign',
                'training.create', 'training.read', 'training.update'
            ]
        ],
        'treasurer' => [
            'label' => 'Kassier',
            'description' => 'Finanzverwaltung und Exports',
            'permissions' => [
                'member.read',
                'finance.create', 'finance.read', 'finance.update',
                'export.sepa', 'export.pdf',
                'audit.view'
            ]
        ],
        'member' => [
            'label' => 'Mitglied',
            'description' => 'Lesezugriff auf eigene Daten',
            'permissions' => [
                'member.read_self',
                'finance.read_self'
            ]
        ]
    ];
    
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
    ];

    private IUserSession $userSession;
    private RoleMapper $roleMapper;
    private UserRoleMapper $userRoleMapper;
    private IGroupManager $groupManager;
    private LoggerInterface $logger;

    public function __construct(
        RoleMapper $roleMapper,
        UserRoleMapper $userRoleMapper,
        IGroupManager $groupManager,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->roleMapper = $roleMapper;
        $this->userRoleMapper = $userRoleMapper;
        $this->groupManager = $groupManager;
        $this->userSession = $userSession;
        $this->logger = $logger;
    }

    /**
     * Checks whether a user holds a role granting the given permission.
     * Nextcloud admins always pass, since they administer the whole instance
     * anyway; everyone else needs an explicit Role assignment (see assignRole()).
     * Role assignments are per club: with a $clubId only assignments for that
     * club count, with null the permission may be held in any club.
     */
    public function userHasPermission(string $userId, string $permission, ?int $clubId = null): bool {
        if ($this->groupManager->isAdmin($userId)) {
            return true;
        }

        $userRoles = $clubId === null
            ? $this->userRoleMapper->findByUserId($userId)
            : $this->userRoleMapper->findByUserAndClub($userId, $clubId);

        foreach ($userRoles as $userRole) {
            try {
                $role = $this->roleMapper->find($userRole->getRoleId());
            } catch (DoesNotExistException $e) {
                continue;
            }
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
        foreach ($this->userRoleMapper->findByUserId($userId) as $userRole) {
            $clubId = (int)$userRole->getClubId();
            if (isset($clubIds[$clubId])) {
                continue;
            }
            try {
                $role = $this->roleMapper->find($userRole->getRoleId());
            } catch (DoesNotExistException $e) {
                continue;
            }
            if (in_array($permission, $role->getPermissionsArray(), true)) {
                $clubIds[$clubId] = $clubId;
            }
        }

        return array_values($clubIds);
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
        foreach ($this->userRoleMapper->findByUserId($userId) as $userRole) {
            $ids[(int)$userRole->getClubId()] = (int)$userRole->getClubId();
        }
        return array_values($ids);
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
        foreach ($this->userRoleMapper->findByUserAndClub($userId, $clubId) as $userRole) {
            try {
                $role = $this->roleMapper->find($userRole->getRoleId());
            } catch (DoesNotExistException $e) {
                continue;
            }
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
     * @return string[]
     */
    public function getAvailablePermissions(): array {
        return self::ENFORCED_PERMISSIONS;
    }

    /**
     * @return array<string, array{label: string, description: string, permissions: string[]}>
     */
    public function getDefaultRoleTemplates(): array {
        return [
            'music' => self::MUSIC_CLUB_ROLES,
            'sport' => self::SPORT_CLUB_ROLES,
        ];
    }

    /**
     * @param string[] $permissions
     * @throws ValidationException
     */
    public function createRole(string $name, string $clubType, ?string $description, array $permissions): Role {
        if (trim($name) === '') {
            throw new ValidationException('Name erforderlich');
        }

        $role = new Role();
        $role->setName($name);
        $role->setDescription($description ?? '');
        $role->setClubType($clubType);
        $role->setPermissionsArray($permissions);
        $now = date('Y-m-d H:i:s');
        $role->setCreatedAt($now);
        $role->setUpdatedAt($now);

        return $this->roleMapper->insert($role);
    }

    /**
     * @param string[]|null $permissions
     * @throws ValidationException
     */
    public function updateRole(int $id, ?string $name, ?string $description, ?array $permissions): Role {
        $role = $this->roleMapper->find($id);

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

        return $this->roleMapper->update($role);
    }

    public function deleteRole(int $id): void {
        $role = $this->roleMapper->find($id);
        $this->roleMapper->delete($role);
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
     * Everyone holding a role in the club, one entry per user with the
     * names of their roles.
     *
     * @return array<int, array{userId: string, roles: string[]}>
     */
    public function getClubAssignments(int $clubId): array {
        $byUser = [];
        foreach ($this->userRoleMapper->findByClubId($clubId) as $userRole) {
            try {
                $role = $this->roleMapper->find($userRole->getRoleId());
            } catch (DoesNotExistException $e) {
                continue;
            }
            $byUser[$userRole->getUserId()][] = $role->getName();
        }

        $result = [];
        foreach ($byUser as $userId => $roles) {
            $result[] = ['userId' => (string)$userId, 'roles' => $roles];
        }
        return $result;
    }

    /**
     * @throws ValidationException
     */
    public function assignRole(string $userId, int $roleId, int $clubId): UserRole {
        if ($clubId <= 0) {
            throw new ValidationException('Verein erforderlich');
        }
        // Confirm the role actually exists before assigning it
        $this->roleMapper->find($roleId);

        if ($this->userRoleMapper->existsForUserAndRole($userId, $roleId, $clubId)) {
            throw new ValidationException('Rolle ist diesem Benutzer bereits zugewiesen');
        }

        $userRole = new UserRole();
        $userRole->setUserId($userId);
        $userRole->setRoleId($roleId);
        $userRole->setClubId($clubId);
        $currentUser = $this->userSession->getUser();
        $userRole->setGrantedBy($currentUser !== null ? $currentUser->getUID() : 'system');

        return $this->userRoleMapper->insert($userRole);
    }

    public function removeUserRoles(string $userId, int $clubId): void {
        $this->userRoleMapper->deleteByUserAndClub($userId, $clubId);
    }
    
    /**
     * Gibt alle Rollen für einen Vereinstyp zurück
     * 
     * @param string $clubType 'music' oder 'sport'
     * @return array Array von Rollen mit Labels und Permissions
     * @throws ValidationException
     */
    public function getRolesForClubType(string $clubType): array {
        $clubType = strtolower($clubType);
        
        if ($clubType === 'music') {
            return self::MUSIC_CLUB_ROLES;
        } elseif ($clubType === 'sport') {
            return self::SPORT_CLUB_ROLES;
        }
        
        throw new ValidationException("Ungültiger Vereinstyp: $clubType");
    }
    
    /**
     * Prüft, ob der aktuelle Nutzer eine bestimmte Permission hat
     * 
     * @param string $permission z.B. 'member.create', 'export.sepa'
     * @return bool
     */
    public function hasPermission(string $permission): bool {
        $user = $this->userSession->getUser();
        
        if ($user === null) {
            return false;
        }
        
        // TODO: DB-Abfrage für Nutzer-Rollen und deren Permissions
        // Placeholder für jetzt
        return true;
    }
    
    /**
     * Validiert eine Permission
     * 
     * @param string $permission
     * @return bool
     */
    public function isValidPermission(string $permission): bool {
        $allPermissions = [
            // Member Permissions
            'member.create', 'member.read', 'member.update', 'member.delete', 'member.read_self',
            // Finance Permissions
            'finance.create', 'finance.read', 'finance.update', 'finance.delete', 'finance.read_self',
            // Export Permissions
            'export.sepa', 'export.pdf',
            // Role Permissions
            'role.assign', 'role.delete',
            // Settings
            'settings.manage',
            // Audit
            'audit.view',
            // Score/Music
            'score.create', 'score.read', 'score.update',
            'musician.assign',
            // Sport
            'athlete.assign', 'training.create', 'training.read', 'training.update'
        ];
        
        return in_array($permission, $allPermissions);
    }
    
    /**
     * Holt die Beschreibung einer Permission
     * 
     * @param string $permission
     * @return string
     */
    public function getPermissionDescription(string $permission): string {
        $descriptions = [
            'member.create' => 'Neue Mitglieder anlegen',
            'member.read' => 'Alle Mitglieder einsehen',
            'member.update' => 'Mitgliederdaten bearbeiten',
            'member.delete' => 'Mitglieder löschen',
            'member.read_self' => 'Nur eigene Daten einsehen',
            'finance.create' => 'Gebühren und Transaktionen anlegen',
            'finance.read' => 'Alle Finanzdaten einsehen',
            'finance.update' => 'Finanzdaten bearbeiten',
            'finance.delete' => 'Finanzdaten löschen',
            'finance.read_self' => 'Nur eigene Finanzinfo einsehen',
            'export.sepa' => 'SEPA XML Export (pain.001)',
            'export.pdf' => 'PDF Export (Rechnungen, Listen)',
            'role.assign' => 'Rollen zuweisen',
            'role.delete' => 'Rollen löschen',
            'settings.manage' => 'App-Einstellungen verwalten',
            'audit.view' => 'Audit-Log einsehen',
            'score.create' => 'Noten anlegen',
            'score.read' => 'Noten einsehen',
            'score.update' => 'Noten bearbeiten',
            'musician.assign' => 'Musiker zuweisen',
            'athlete.assign' => 'Athleten zuweisen',
            'training.create' => 'Training anlegen',
            'training.read' => 'Training einsehen',
            'training.update' => 'Training bearbeiten'
        ];
        
        return $descriptions[$permission] ?? $permission;
    }
}
