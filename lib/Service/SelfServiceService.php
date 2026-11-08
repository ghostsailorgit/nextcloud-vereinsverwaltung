<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * Selbstauskunft: what the club register holds about one person. forUser() is self-service
 * (whoever is linked may see their own record, across all their clubs, no role needed);
 * forMemberId() is the same data for an admin exporting someone else's record on request
 * (Art. 15 GDPR), restricted to one club via $onlyClubId - neither method checks permissions
 * itself, that is the caller's job (MeController vs. MemberController::export(), which must
 * confirm club membership first - see the club-scoping note on forMemberId()).
 */
class SelfServiceService {
    private IL10N $l;

    public function __construct(
        private MemberMapper $members,
        private MembershipMapper $memberships,
        private ClubMapper $clubs,
        private FeeMapper $fees,
        ?IL10N $l10n = null,
        private ?\OCA\Verein\Db\ClubGroupMemberMapper $groupPeriods = null,
        private ?\OCA\Verein\Db\ClubGroupMapper $groups = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
    }

    /**
     * @return array{linked: false}|array<string, mixed>
     */
    public function forUser(string $userId): array {
        $person = $this->members->findByUserId($userId);
        if ($person === null) {
            return ['linked' => false];
        }
        return $this->buildExport($person, $userId);
    }

    /**
     * @param int|null $onlyClubId Restricts memberships and fees to this club - the caller
     *   (MemberController::export()) must already have confirmed the person is a member of it;
     *   this method does not check permissions itself, same as forUser().
     * @param bool $includeFees false leaves the fees out (the caller may see the member but not the finances)
     * @throws NotFoundException
     */
    public function forMemberId(int $memberId, ?int $onlyClubId = null, bool $includeFees = true): array {
        try {
            $person = $this->members->find($memberId);
        } catch (DoesNotExistException $e) {
            throw new NotFoundException($this->l->t('Member not found'));
        }
        $data = $this->buildExport($person, $person->getUserId(), $onlyClubId);
        if (!$includeFees) {
            unset($data['fees']);
            $data['feesOmitted'] = true;
        }
        return $data;
    }

    /** @return array<int, array{name: string, joinedOn: string, leftOn: ?string}> */
    private function groupsOf(int $memberId, int $clubId): array {
        if ($this->groupPeriods === null || $this->groups === null) {
            return [];
        }
        $names = [];
        foreach ($this->groups->findByClub($clubId) as $group) {
            $names[$group->getId()] = $group->getName();
        }
        $rows = [];
        foreach ($this->groupPeriods->findByMember($memberId) as $period) {
            if (isset($names[$period->getGroupId()])) {
                $rows[] = ['name' => $names[$period->getGroupId()], 'joinedOn' => $period->getJoinedOn(), 'leftOn' => $period->getLeftOn()];
            }
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExport(Member $person, ?string $userId, ?int $onlyClubId = null): array {
        $clubNames = [];
        $memberships = [];
        foreach ($this->memberships->findByMember($person->getId()) as $membership) {
            if ($onlyClubId !== null && $membership->getClubId() !== $onlyClubId) {
                continue;
            }
            try {
                $club = $this->clubs->find($membership->getClubId());
            } catch (DoesNotExistException $e) {
                continue;
            }
            $clubNames[$club->getId()] = $club->getName();

            // the person as seen through this membership (age, years, status)
            $view = clone $person;
            $view->setMembership($membership);

            $memberships[] = [
                'club' => ['id' => $club->getId(), 'name' => $club->getName()],
                'role' => $membership->getRole(),
                'joinDate' => $membership->getJoinDate(),
                'leaveDate' => $membership->getLeaveDate(),
                'foundingMember' => $membership->getFoundingMember(),
                'feeExemptJoinYear' => $membership->getFeeExemptJoinYear(),
                'honoraryMember' => $membership->getHonoraryMember(),
                'honoraryPaysFee' => $membership->getHonoraryPaysFee(),
                'isFormer' => $view->isFormer(),
                'groups' => $this->groupsOf($person->getId(), $club->getId()),
                'membershipYears' => $view->getMembershipYears(),
                'mandate' => [
                    'reference' => $membership->getEffectiveMandateReference(),
                    'date' => $membership->getMandateDate(),
                    'signedCopyOnFile' => !empty($membership->getMandateFile()) || $membership->getMandateOnPaper(),
                ],
            ];
        }

        $fees = [];
        foreach ($this->fees->findByMember($person->getId()) as $fee) {
            if ($onlyClubId !== null && $fee->getClubId() !== $onlyClubId) {
                continue;
            }
            $fees[] = [
                'club' => $clubNames[$fee->getClubId()] ?? '',
                'amount' => $fee->getAmount(),
                'status' => $fee->getStatus(),
                'dueDate' => $fee->getDueDate(),
                'paidDate' => $fee->getPaidDate(),
                'description' => $fee->getDescription(),
            ];
        }
        usort($fees, fn (array $a, array $b) => strcmp((string)$b['dueDate'], (string)$a['dueDate']));

        return [
            'linked' => $userId !== null && $userId !== '',
            'nextcloudAccount' => $userId,
            'person' => [
                'salutation' => $person->getSalutation(),
                'firstName' => $person->getFirstName(),
                'name' => $person->getName(),
                'fullName' => $person->getFullName(),
                'birthDate' => $person->getBirthDate(),
                'street' => $person->getStreet(),
                'postalCode' => $person->getPostalCode(),
                'city' => $person->getCity(),
                'email' => $person->getEmail(),
                'phone' => $person->getPhone(),
                'iban' => $person->getIban(),
                'bic' => $person->getBic(),
                'deceased' => $person->getDeceased(),
            ],
            'memberships' => $memberships,
            'fees' => $fees,
        ];
    }
}
