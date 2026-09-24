<?php
namespace OCA\Verein\Service;

use Exception;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Db\MembershipMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * Members are plain persons (MemberMapper); what ties one to a club -
 * join/leave date, role, founding member, SEPA mandate - is a Membership.
 * Every operation here works in the context of one club: a person is only
 * visible/editable through a club they belong to.
 */
class MemberService {
    public function __construct(
        private MemberMapper $mapper,
        private MembershipMapper $membershipMapper,
        private FeeMapper $feeMapper,
        private ClubMapper $clubMapper,
        private IUserManager $userManager,
        private FeeRateMapper $feeRates,
        private ?MemberCalendarService $calendarService = null,
        private ?AuditLogService $auditLog = null
    ) {
    }

    /** @return Member[] members of the club, each with their membership */
    public function findAll(int $clubId): array {
        return $this->mapper->findByClub($clubId);
    }

    /**
     * Search members of a club by query (name, first name, email, or exact
     * member id), remote-friendly for autocomplete.
     */
    public function search(int $clubId, string $query, int $limit = 50): array {
        if (trim($query) === '') {
            return [];
        }
        return $this->mapper->searchInClub($query, $clubId, $limit);
    }

    /**
     * @throws Exception if the person is not a member of the club
     */
    public function find(int $clubId, int $id): Member {
        try {
            return $this->mapper->findInClub($id, $clubId);
        } catch (DoesNotExistException $e) {
            throw new Exception('Member not found');
        }
    }

    /**
     * The person linked to a Nextcloud account (null if none).
     */
    public function findByLinkedUser(string $userId): ?Member {
        return $this->mapper->findByUserId($userId);
    }

    /**
     * Persons from other clubs that could be added to this one - restricted
     * to the clubs the caller may look into (see MemberController::lookup()).
     *
     * @param int[] $visibleClubIds
     * @return Member[]
     */
    public function lookupInOtherClubs(int $clubId, string $query, array $visibleClubIds): array {
        if (trim($query) === '') {
            return [];
        }
        $visibleClubIds = array_values(array_diff($visibleClubIds, [$clubId]));
        return $this->mapper->searchInOtherClubs($query, $visibleClubIds, $clubId);
    }

    /**
     * Whether the person belongs to at least one of the given clubs.
     *
     * @param int[] $clubIds
     */
    public function isMemberOfAny(int $memberId, array $clubIds): bool {
        foreach ($this->membershipMapper->findByMember($memberId) as $membership) {
            if (in_array($membership->getClubId(), $clubIds, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Creates a new person and their membership in the club.
     *
     * @param array $data Person keys: name, firstName, salutation, address,
     *   street, postalCode, city, email, iban, bic, birthDate, deceased.
     *   Membership keys: role, joinDate, leaveDate, foundingMember,
     *   mandateReference, mandateDate, mandateFile
     */
    public function create(int $clubId, array $data): Member {
        $this->clubMapper->find($clubId);
        $member = new Member();
        $this->applyPersonData($member, $data);
        $member->setCreatedAt(date('Y-m-d H:i:s'));
        $member->setUpdatedAt(date('Y-m-d H:i:s'));
        $member = $this->mapper->insert($member);

        $membership = new Membership();
        $membership->setMemberId($member->getId());
        $membership->setClubId($clubId);
        $this->applyMembershipData($membership, $data);
        $membership->setCreatedAt(date('Y-m-d H:i:s'));
        $membership->setUpdatedAt(date('Y-m-d H:i:s'));
        $member->setMembership($this->membershipMapper->insert($membership));

        $this->auditLog?->record($clubId, 'member', $member->getId(), 'create', $member->jsonSerialize());
        $this->syncCalendar($clubId, $member);
        return $member;
    }

    /**
     * Adds an existing person to this club (membership only - the personal
     * data is shared and stays as it is).
     *
     * @throws Exception if the person is already a member
     */
    public function attachExisting(int $clubId, int $memberId, array $data): Member {
        $this->clubMapper->find($clubId);
        $member = $this->mapper->find($memberId);

        try {
            $this->membershipMapper->findByMemberAndClub($memberId, $clubId);
            throw new Exception('Die Person ist bereits Mitglied in diesem Verein');
        } catch (DoesNotExistException $e) {
            // expected
        }

        $membership = new Membership();
        $membership->setMemberId($memberId);
        $membership->setClubId($clubId);
        $this->applyMembershipData($membership, $data);
        $membership->setCreatedAt(date('Y-m-d H:i:s'));
        $membership->setUpdatedAt(date('Y-m-d H:i:s'));
        $member->setMembership($this->membershipMapper->insert($membership));

        $this->syncCalendar($clubId, $member);
        return $member;
    }

    public function update(int $clubId, int $id, array $data): Member {
        $member = $this->find($clubId, $id);
        $before = $member->jsonSerialize();

        $this->applyPersonData($member, $data);
        $member->setUpdatedAt(date('Y-m-d H:i:s'));
        $member = $this->mapper->update($member);

        $membership = $this->membershipMapper->findByMemberAndClub($id, $clubId);
        $this->applyMembershipData($membership, $data);
        $membership->setUpdatedAt(date('Y-m-d H:i:s'));
        $member->setMembership($this->membershipMapper->update($membership));

        $this->logMemberChange($clubId, $member, $before);

        // The person's name/birth date show up in the calendars of every
        // club they belong to
        foreach ($this->membershipMapper->findByMember($id) as $other) {
            if ($other->getClubId() === $clubId) {
                $this->syncCalendar($clubId, $member);
                continue;
            }
            $inOther = clone $member;
            $inOther->setMembership($other);
            $this->syncCalendar($other->getClubId(), $inOther);
        }

        return $member;
    }

    /**
     * Removes the person from the club: their membership and this club's
     * fees for them. The person record itself is deleted only when no other
     * club still has them as a member.
     */
    public function remove(int $clubId, int $id): void {
        $member = $this->find($clubId, $id);

        $club = $this->clubMapper->find($clubId);
        $this->calendarService?->removeMember($club, $member);

        $this->feeMapper->deleteByMemberInClub($id, $clubId);
        $membershipId = $member->getMembership()->getId();
        $this->membershipMapper->delete($member->getMembership());
        $this->auditLog?->record($clubId, 'membership', $membershipId, 'delete');

        if ($this->membershipMapper->findByMember($id) === []) {
            $this->mapper->delete($member);
            $this->auditLog?->record($clubId, 'member', $id, 'delete');
        }
    }

    /**
     * Deactivates the member in this club. From then on the club no longer
     * collects money from them (fee run, SEPA export, new fees), the birthday
     * and anniversary reminders leave the calendar, and their linked
     * Nextcloud account loses the roles derived from this membership
     * (RoleService::derivedRoles()); explicit role assignments stay.
     * Nothing is deleted, activate() reverses it.
     */
    public function deactivate(int $clubId, int $id): Member {
        return $this->setDeactivated($clubId, $id, true);
    }

    public function activate(int $clubId, int $id): Member {
        return $this->setDeactivated($clubId, $id, false);
    }

    private function setDeactivated(int $clubId, int $id, bool $deactivated): Member {
        $member = $this->find($clubId, $id);
        $membership = $member->getMembership();
        if ($membership->getDeactivated() !== $deactivated) {
            $membership->setDeactivated($deactivated);
            $membership->setUpdatedAt(date('Y-m-d H:i:s'));
            $member->setMembership($this->membershipMapper->update($membership));
            $this->auditLog?->record($clubId, 'membership', $membership->getId(), $deactivated ? 'deactivate' : 'activate', ['memberId' => $id]);
            $this->syncCalendar($clubId, $member);
        }
        return $member;
    }
    private function logMemberChange(int $clubId, Member $member, array $before): void {
        if ($this->auditLog === null) {
            return;
        }
        $changes = $this->auditLog->diff($before, $member->jsonSerialize());
        if ($changes !== []) {
            $this->auditLog->record($clubId, 'member', $member->getId(), 'update', $changes);
        }
    }

    private function syncCalendar(int $clubId, Member $member): void {
        if ($this->calendarService === null) {
            return;
        }
        $this->calendarService->syncMember($this->clubMapper->find($clubId), $member);
    }

    /**
     * Links or unlinks a Nextcloud account. Only touched when the request
     * carries a userId at all: a value links, an empty string unlinks, no
     * value (null) leaves the current link as it is. An account can belong
     * to only one person.
     *
     * @throws Exception
     */
    private function applyUserLink(Member $member, array $data): void {
        if (!array_key_exists('userId', $data) || $data['userId'] === null) {
            return;
        }
        $userId = trim((string)$data['userId']);
        if ($userId === '') {
            $member->setUserId(null);
            return;
        }
        if ($userId === $member->getUserId()) {
            return;
        }
        if (!$this->userManager->userExists($userId)) {
            throw new Exception('Das Nextcloud-Konto existiert nicht');
        }
        $other = $this->mapper->findByUserId($userId);
        if ($other !== null && $other->getId() !== $member->getId()) {
            throw new Exception('Das Nextcloud-Konto ist bereits mit ' . $other->getFullName() . ' verknüpft');
        }
        $member->setUserId($userId);
    }

    private function applyPersonData(Member $member, array $data): void {
        $this->applyUserLink($member, $data);
        $member->setName((string)($data['name'] ?? ''));
        $member->setFirstName($this->nullIfEmpty($data['firstName'] ?? null));
        $member->setSalutation($this->nullIfEmpty($data['salutation'] ?? null));
        $member->setAddress($this->nullIfEmpty($data['address'] ?? null));
        $member->setStreet($this->nullIfEmpty($data['street'] ?? null));
        $member->setPostalCode($this->nullIfEmpty($data['postalCode'] ?? null));
        $member->setCity($this->nullIfEmpty($data['city'] ?? null));
        $member->setEmail((string)($data['email'] ?? ''));
        $member->setIban($this->nullIfEmpty($data['iban'] ?? null));
        $member->setBic($this->nullIfEmpty($data['bic'] ?? null));
        $member->setBirthDate($this->nullIfEmpty($data['birthDate'] ?? null));
        $member->setDeceased($this->toBool($data['deceased'] ?? false));
    }

    private function applyMembershipData(Membership $membership, array $data): void {
        $membership->setRole((string)($data['role'] ?? 'member'));
        $membership->setJoinDate($this->nullIfEmpty($data['joinDate'] ?? null));
        $membership->setLeaveDate($this->nullIfEmpty($data['leaveDate'] ?? null));
        $membership->setFoundingMember($this->toBool($data['foundingMember'] ?? false));
        $membership->setMandateReference($this->nullIfEmpty($data['mandateReference'] ?? null));
        $membership->setMandateDate($this->nullIfEmpty($data['mandateDate'] ?? null));
        $membership->setMandateFile($this->nullIfEmpty($data['mandateFile'] ?? null));
        $this->applyFeeRate($membership, $data);
    }

    /**
     * The fee category of the membership: an id sets it (must belong to the
     * same club), an empty string clears it, no value leaves it as it is.
     *
     * @throws Exception
     */
    private function applyFeeRate(Membership $membership, array $data): void {
        if (!array_key_exists('feeRateId', $data) || $data['feeRateId'] === null) {
            return;
        }
        $raw = trim((string)$data['feeRateId']);
        if ($raw === '' || $raw === '0') {
            $membership->setFeeRateId(null);
            return;
        }
        try {
            $rate = $this->feeRates->find((int)$raw);
        } catch (DoesNotExistException $e) {
            throw new Exception('Die Beitragskategorie existiert nicht');
        }
        if ($rate->getClubId() !== $membership->getClubId()) {
            throw new Exception('Die Beitragskategorie gehört zu einem anderen Verein');
        }
        $membership->setFeeRateId($rate->getId());
    }

    private function nullIfEmpty(mixed $value): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function toBool(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        return in_array($value, ['1', 1, 'true', true], true);
    }
}
