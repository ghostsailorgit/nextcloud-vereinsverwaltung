<?php
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Selbstauskunft: what the club register holds about one person, across all
 * their clubs. forUser() is self-service (whoever is linked may see their
 * own record, no role needed); forMemberId() is the same data for an admin
 * exporting someone else's record on request (Art. 15 GDPR) - the caller
 * decides who may call which (MeController vs. MemberController::export()).
 */
class SelfServiceService {
    public function __construct(
        private MemberMapper $members,
        private MembershipMapper $memberships,
        private ClubMapper $clubs,
        private FeeMapper $fees
    ) {
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
     * @throws NotFoundException
     */
    public function forMemberId(int $memberId): array {
        try {
            $person = $this->members->find($memberId);
        } catch (DoesNotExistException $e) {
            throw new NotFoundException('Mitglied nicht gefunden');
        }
        return $this->buildExport($person, $person->getUserId());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExport(Member $person, ?string $userId): array {
        $clubNames = [];
        $memberships = [];
        foreach ($this->memberships->findByMember($person->getId()) as $membership) {
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
                'isFormer' => $view->isFormer(),
                'membershipYears' => $view->getMembershipYears(),
                'mandate' => [
                    'reference' => $membership->getEffectiveMandateReference(),
                    'date' => $membership->getMandateDate(),
                    'signedCopyOnFile' => !empty($membership->getMandateFile()),
                ],
            ];
        }

        $fees = [];
        foreach ($this->fees->findByMember($person->getId()) as $fee) {
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
            'linked' => true,
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
                'iban' => $person->getIban(),
                'bic' => $person->getBic(),
                'deceased' => $person->getDeceased(),
            ],
            'memberships' => $memberships,
            'fees' => $fees,
        ];
    }
}
