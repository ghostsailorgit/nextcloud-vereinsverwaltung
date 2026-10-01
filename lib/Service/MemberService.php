<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Service;

use OCA\Verein\Exception\NotFoundException;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Db\MembershipMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * Members are plain persons (MemberMapper); what ties one to a club -
 * join/leave date, role, founding member, SEPA mandate - is a Membership.
 * Every operation here works in the context of one club: a person is only
 * visible/editable through a club they belong to.
 */
class MemberService {
    private IL10N $l;

    public function __construct(
        private MemberMapper $mapper,
        private MembershipMapper $membershipMapper,
        private FeeMapper $feeMapper,
        private ClubMapper $clubMapper,
        private IUserManager $userManager,
        private FeeRateMapper $feeRates,
        private ?AuditLogService $auditLog = null,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
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
     * @throws NotFoundException if the person is not a member of the club
     */
    public function find(int $clubId, int $id): Member {
        try {
            return $this->mapper->findInClub($id, $clubId);
        } catch (DoesNotExistException $e) {
            throw new NotFoundException($this->l->t('Member not found'));
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
     *   Membership keys: role, joinDate, leaveDate, foundingMember, feeExemptJoinYear,
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
        return $member;
    }

    /**
     * Adds an existing person to this club (membership only - the personal
     * data is shared and stays as it is).
     *
     * @throws ValidationException if the person is already a member
     */
    public function attachExisting(int $clubId, int $memberId, array $data): Member {
        $this->clubMapper->find($clubId);
        $member = $this->mapper->find($memberId);

        try {
            $this->membershipMapper->findByMemberAndClub($memberId, $clubId);
            throw new ValidationException($this->l->t('The person is already a member of this club'));
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
                continue;
            }
            $inOther = clone $member;
            $inOther->setMembership($other);
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
        }
        return $member;
    }

    /**
     * Replaces a person's personal data with placeholders (GDPR erasure), keeping the row itself so
     * fees and SEPA history stay attributable to it - a hard delete would break bookkeeping retention.
     * Only allowed once the person has left (or is deceased in) every club they belong to - an active
     * member's data is still needed to run the club. Does not touch the membership's mandateReference
     * or mandateFile (may contain the name, e.g. in the file path); mandates have their own retention
     * duty and the signed file itself stays in Nextcloud Files regardless.
     *
     * The audit log keeps knowing *that* something changed but not what: past entries about this person
     * (and their memberships) are scrubbed too (see AuditLogService::scrubEntity()), and this action
     * itself is recorded without the erased values.
     *
     * The caller (MemberController::anonymize()) must confirm the person belongs to the requesting
     * club before calling this - it looks the person up globally and does not check that itself.
     *
     * @throws ValidationException if already anonymized or still an active member somewhere
     */
    public function anonymize(int $id): Member {
        $member = $this->mapper->find($id);
        if ($member->getAnonymizedAt() !== null) {
            throw new ValidationException($this->l->t('The person is already anonymized'));
        }
        $memberships = $this->membershipMapper->findByMember($id);
        if (!$member->getDeceased()) {
            foreach ($memberships as $membership) {
                if (empty($membership->getLeaveDate())) {
                    throw new ValidationException(
                        $this->l->t('The person is still an active member of at least one club and cannot be anonymized')
                    );
                }
            }
        }

        $member->setSalutation(null);
        $member->setName('Anonymisiert');
        $member->setFirstName(null);
        $member->setAddress(null);
        $member->setStreet(null);
        $member->setPostalCode(null);
        $member->setCity(null);
        $member->setEmail('');
        $member->setIban(null);
        $member->setBic(null);
        $member->setBirthDate(null);
        $member->setUserId(null);
        $member->setAnonymizedAt(date('Y-m-d H:i:s'));
        $member->setUpdatedAt(date('Y-m-d H:i:s'));
        $member = $this->mapper->update($member);

        // one entry per club, so it shows up in each club's "Protokoll" tab (null would be visible nowhere)
        $clubIds = array_values(array_unique(array_map(fn ($m) => $m->getClubId(), $memberships)));
        foreach ($clubIds ?: [null] as $clubId) {
            $this->auditLog?->record($clubId, 'member', $id, 'anonymize');
        }
        $this->auditLog?->scrubEntity('member', $id);
        foreach ($memberships as $membership) {
            $this->auditLog?->scrubEntity('membership', $membership->getId());
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

    /**
     * Links or unlinks a Nextcloud account. Only touched when the request
     * carries a userId at all: a value links, an empty string unlinks, no
     * value (null) leaves the current link as it is. An account can belong
     * to only one person.
     *
     * @throws ValidationException
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
            throw new ValidationException($this->l->t('The Nextcloud account does not exist'));
        }
        $other = $this->mapper->findByUserId($userId);
        if ($other !== null && $other->getId() !== $member->getId()) {
            throw new ValidationException($this->l->t('The Nextcloud account is already linked to %s', [$other->getFullName()]));
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
        $membership->setFeeExemptJoinYear($this->toBool($data['feeExemptJoinYear'] ?? false));
        $membership->setMandateReference($this->nullIfEmpty($data['mandateReference'] ?? null));
        $membership->setMandateDate($this->nullIfEmpty($data['mandateDate'] ?? null));
        $membership->setMandateFile($this->nullIfEmpty($data['mandateFile'] ?? null));
        $this->applyFeeRate($membership, $data);
    }

    /**
     * The fee category of the membership: an id sets it (must belong to the
     * same club), an empty string clears it, no value leaves it as it is.
     *
     * @throws ValidationException
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
            throw new ValidationException($this->l->t('The fee category does not exist'));
        }
        if ($rate->getClubId() !== $membership->getClubId()) {
            throw new ValidationException($this->l->t('The fee category belongs to another club'));
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
