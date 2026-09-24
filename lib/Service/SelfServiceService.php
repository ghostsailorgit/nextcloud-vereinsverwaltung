<?php
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\MembershipMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Self-service ("Selbstauskunft"): what the club register holds about the
 * person linked to a Nextcloud account - their own data only, across all
 * their clubs. Not tied to any role: whoever is linked may see their own
 * record, nothing else.
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
