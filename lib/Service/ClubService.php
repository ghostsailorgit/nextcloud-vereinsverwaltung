<?php
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubAccount;
use OCA\Verein\Db\ClubAccountMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Db\RoleMapper;
use OCA\Verein\Db\UserRoleMapper;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Clubs (Vereine) and their bank accounts. Every club is unique (by name),
 * can have several accounts and is the top of the hierarchy: members,
 * fees, role assignments and the calendar all hang off a club.
 */
class ClubService {
    public function __construct(
        private ClubMapper $clubMapper,
        private ClubAccountMapper $accountMapper,
        private MembershipMapper $membershipMapper,
        private UserRoleMapper $userRoleMapper,
        private RoleService $roleService,
        private ValidationService $validation,
        private MemberCalendarService $calendar,
        private RoleMapper $roleMapper
    ) {
    }

    /**
     * The clubs the user may see (all for Nextcloud admins, otherwise those
     * they hold a role in), each with the permissions they hold there.
     * Bank accounts are only included where the user could actually use
     * them (SEPA export or club management).
     */
    public function listForUser(string $userId): array {
        $accessible = $this->roleService->getAccessibleClubIds($userId);
        $clubs = [];
        foreach ($this->clubMapper->findAll() as $club) {
            if ($accessible !== null && !in_array($club->getId(), $accessible, true)) {
                continue;
            }
            $permissions = $this->roleService->getPermissionsForClub($userId, $club->getId());
            $entry = $club->jsonSerialize();
            $entry['permissions'] = $permissions;
            $canSeeAccounts = in_array('verein.sepa.export', $permissions, true)
                || in_array('verein.club.manage', $permissions, true);
            $entry['accounts'] = $canSeeAccounts
                ? array_map(fn (ClubAccount $a) => $a->jsonSerialize(), $this->accountMapper->findByClub($club->getId()))
                : [];
            $clubs[] = $entry;
        }
        return $clubs;
    }

    /** @throws DoesNotExistException */
    public function find(int $id): Club {
        return $this->clubMapper->find($id);
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data): Club {
        $club = new Club();
        $this->applyData($club, $data);
        $club->setCreatedAt(date('Y-m-d H:i:s'));
        $club = $this->clubMapper->insert($club);
        $this->calendar->syncClubCalendar($club);
        return $club;
    }

    /**
     * @throws ValidationException
     */
    public function update(int $id, array $data): Club {
        $club = $this->clubMapper->find($id);
        $this->applyData($club, $data, $id);
        $club->setUpdatedAt(date('Y-m-d H:i:s'));
        $club = $this->clubMapper->update($club);
        $this->calendar->syncClubCalendar($club);
        return $club;
    }

    /**
     * Only an empty club (no members) can be deleted - deleting one with
     * members would silently destroy their membership data.
     *
     * @throws ValidationException
     */
    public function delete(int $id): void {
        $club = $this->clubMapper->find($id);
        if ($this->membershipMapper->countByClub($id) > 0) {
            throw new ValidationException('Der Verein hat noch Mitglieder und kann nicht gelöscht werden');
        }
        $this->calendar->deleteClubCalendar($club);
        $this->accountMapper->deleteByClub($id);
        $this->userRoleMapper->deleteByClub($id);
        $this->clubMapper->delete($club);
    }

    /**
     * Sets which app role each membership role gets automatically (members
     * with a linked Nextcloud account; see RoleService::derivedRoles()).
     * A missing/empty entry means "no automatic role" for that membership role.
     *
     * @param array<string, mixed> $mapping membership role => role id or ''
     * @throws ValidationException
     */
    public function setRoleMapping(int $clubId, array $mapping): Club {
        $club = $this->clubMapper->find($clubId);

        $clean = [];
        foreach (['member', 'treasurer', 'admin'] as $membershipRole) {
            $roleId = $mapping[$membershipRole] ?? '';
            if ($roleId === '' || $roleId === null || (int)$roleId === 0) {
                continue;
            }
            try {
                $this->roleMapper->find((int)$roleId);
            } catch (DoesNotExistException $e) {
                throw new ValidationException('Die gewählte Rolle existiert nicht');
            }
            $clean[$membershipRole] = (int)$roleId;
        }

        $club->setRoleMapping($clean === [] ? null : json_encode($clean));
        $club->setUpdatedAt(date('Y-m-d H:i:s'));
        return $this->clubMapper->update($club);
    }

    /**
     * The account to use for a SEPA export: the given one (which must belong
     * to the club) or the club's default account.
     *
     * @throws ValidationException
     */
    public function resolveAccount(int $clubId, ?int $accountId): ClubAccount {
        $accounts = $this->accountMapper->findByClub($clubId);
        if ($accounts === []) {
            throw new ValidationException('Für diesen Verein ist noch kein Bankkonto hinterlegt');
        }
        if ($accountId === null || $accountId <= 0) {
            return $accounts[0];
        }
        foreach ($accounts as $account) {
            if ($account->getId() === $accountId) {
                return $account;
            }
        }
        throw new ValidationException('Das Bankkonto gehört nicht zu diesem Verein');
    }

    /**
     * @throws ValidationException
     */
    public function createAccount(int $clubId, array $data): ClubAccount {
        $this->clubMapper->find($clubId);
        $account = new ClubAccount();
        $account->setClubId($clubId);
        $this->applyAccountData($account, $data);
        $account->setCreatedAt(date('Y-m-d H:i:s'));
        $isFirst = $this->accountMapper->findByClub($clubId) === [];
        $account->setIsDefault($isFirst || $this->toBool($data['isDefault'] ?? false));
        $account = $this->accountMapper->insert($account);
        if ($account->getIsDefault()) {
            $this->makeOnlyDefault($account);
        }
        return $account;
    }

    /**
     * @throws ValidationException
     */
    public function updateAccount(int $clubId, int $accountId, array $data): ClubAccount {
        $account = $this->accountMapper->find($accountId);
        if ($account->getClubId() !== $clubId) {
            throw new DoesNotExistException('Bankkonto nicht gefunden');
        }
        $this->applyAccountData($account, $data);
        if ($this->toBool($data['isDefault'] ?? false)) {
            $account->setIsDefault(true);
        }
        $account = $this->accountMapper->update($account);
        if ($account->getIsDefault()) {
            $this->makeOnlyDefault($account);
        }
        return $account;
    }

    /**
     * @throws ValidationException
     */
    public function deleteAccount(int $clubId, int $accountId): void {
        $account = $this->accountMapper->find($accountId);
        if ($account->getClubId() !== $clubId) {
            throw new DoesNotExistException('Bankkonto nicht gefunden');
        }
        $wasDefault = $account->getIsDefault();
        $this->accountMapper->delete($account);
        if ($wasDefault) {
            // Keep exactly one default while any account is left
            $remaining = $this->accountMapper->findByClub($clubId);
            if ($remaining !== []) {
                $remaining[0]->setIsDefault(true);
                $this->accountMapper->update($remaining[0]);
            }
        }
    }

    private function makeOnlyDefault(ClubAccount $default): void {
        foreach ($this->accountMapper->findByClub($default->getClubId()) as $other) {
            if ($other->getId() !== $default->getId() && $other->getIsDefault()) {
                $other->setIsDefault(false);
                $this->accountMapper->update($other);
            }
        }
    }

    /**
     * @throws ValidationException
     */
    private function applyData(Club $club, array $data, ?int $existingId = null): void {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('Name des Vereins ist erforderlich');
        }
        foreach ($this->clubMapper->findAll() as $other) {
            if ($other->getId() !== $existingId && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new ValidationException('Ein Verein mit diesem Namen existiert bereits');
            }
        }
        $club->setName($name);
        $club->setStreet($this->nullIfEmpty($data['street'] ?? null));
        $club->setPostalCode($this->nullIfEmpty($data['postalCode'] ?? null));
        $club->setCity($this->nullIfEmpty($data['city'] ?? null));
        $club->setDocumentsPath($this->nullIfEmpty($data['documentsPath'] ?? null));

        $groups = $data['calendarGroups'] ?? [];
        if (is_string($groups)) {
            $groups = preg_split('/[\s,;]+/', $groups, -1, PREG_SPLIT_NO_EMPTY);
        }
        $club->setCalendarGroups(json_encode(array_values(array_unique(array_map('strval', (array)$groups)))));
    }

    /**
     * @throws ValidationException
     */
    private function applyAccountData(ClubAccount $account, array $data): void {
        $iban = strtoupper(str_replace(' ', '', (string)($data['iban'] ?? '')));
        if ($iban === '' || !$this->validation->validateIBAN($iban)) {
            throw new ValidationException('IBAN ist ungültig (z.B. DE89370400440532013000)');
        }
        $creditorId = str_replace(' ', '', (string)($data['creditorId'] ?? ''));
        if ($creditorId !== '' && !$this->validation->validateCreditorId($creditorId)) {
            throw new ValidationException('Gläubiger-ID ist ungültig (z.B. DE98ZZZ09999999999)');
        }
        $bic = strtoupper(str_replace(' ', '', (string)($data['bic'] ?? '')));
        if ($bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
            throw new ValidationException('BIC ist ungültig');
        }
        $account->setLabel(trim((string)($data['label'] ?? '')));
        $account->setIban($iban);
        $account->setBic($bic);
        $account->setCreditorId($creditorId);
    }

    private function nullIfEmpty(mixed $value): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function toBool(mixed $value): bool {
        return is_bool($value) ? $value : in_array($value, ['1', 1, 'true', true], true);
    }
}
