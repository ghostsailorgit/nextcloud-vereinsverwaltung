<?php
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Fee;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\FeeRate;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * The annual fee run: creates one membership fee per active member of a club
 * according to their fee category. Idempotent - a member who already has a
 * (non-cancelled) fee for that year is skipped - so a run can be repeated
 * after new members joined, and previewed before anything is written.
 */
class FeeRunService {
    public function __construct(
        private FeeRateMapper $rates,
        private MemberMapper $members,
        private FeeMapper $fees,
        private ClubMapper $clubs,
        private IDBConnection $db,
        private ?AuditLogService $auditLog = null
    ) {
    }

    /**
     * What a run would do, without writing anything.
     *
     * @return array{year: int, dueDate: string, description: string, included: array, skipped: array, total: float}
     * @throws ValidationException
     */
    public function plan(int $clubId, int $year, string $dueDate, ?string $description = null): array {
        $this->clubs->find($clubId);
        if ($year < 2000 || $year > 2100) {
            throw new ValidationException('Jahr ist ungültig');
        }
        $due = \DateTime::createFromFormat('Y-m-d', $dueDate);
        if ($due === false || $due->format('Y-m-d') !== $dueDate) {
            throw new ValidationException('Fälligkeitsdatum ist ungültig');
        }
        $description = trim((string)$description);
        if ($description === '') {
            $description = 'Mitgliedsbeitrag ' . $year;
        }
        if (mb_strlen($description) > 500) {
            throw new ValidationException('Bemerkung ist zu lang');
        }

        $ratesById = [];
        $default = null;
        foreach ($this->rates->findByClub($clubId) as $rate) {
            $ratesById[$rate->getId()] = $rate;
            if ($rate->getIsDefault()) {
                $default = $rate;
            }
        }

        // members that already have this year's fee (cancelled ones don't count)
        $alreadyBilled = [];
        foreach ($this->fees->findByClub($clubId) as $fee) {
            if ($fee->getPeriod() === (string)$year && $fee->getStatus() !== 'cancelled') {
                $alreadyBilled[$fee->getMemberId()] = true;
            }
        }

        $members = $this->members->findByClub($clubId);
        usort($members, fn ($a, $b) => strcasecmp($a->getName() . $a->getFirstName(), $b->getName() . $b->getFirstName()));

        $included = [];
        $skipped = [];
        $total = 0.0;
        foreach ($members as $member) {
            $skip = function (string $reason) use (&$skipped, $member): void {
                $skipped[] = ['memberId' => $member->getId(), 'name' => $member->getFullName(), 'reason' => $reason];
            };

            if ($member->isFormer()) {
                $skip('ausgetreten oder verstorben');
                continue;
            }
            $join = $member->getJoinDate();
            if (!empty($join) && (int)substr($join, 0, 4) > $year) {
                $skip('Eintritt erst nach ' . $year);
                continue;
            }
            if (isset($alreadyBilled[$member->getId()])) {
                $skip('Beitrag ' . $year . ' schon vorhanden');
                continue;
            }

            $rate = $this->rateFor($member->getMembership()?->getFeeRateId(), $ratesById, $default);
            if ($rate === null) {
                $skip('keine Beitragskategorie');
                continue;
            }
            if ((float)$rate->getAmount() <= 0) {
                $skip('beitragsfrei (' . $rate->getName() . ')');
                continue;
            }

            $included[] = [
                'memberId' => $member->getId(),
                'name' => $member->getFullName(),
                'category' => $rate->getName(),
                'amount' => (float)$rate->getAmount(),
            ];
            $total += (float)$rate->getAmount();
        }

        return [
            'year' => $year,
            'dueDate' => $dueDate,
            'description' => $description,
            'included' => $included,
            'skipped' => $skipped,
            'total' => round($total, 2),
        ];
    }

    /**
     * Creates the fees the plan lists - all or none.
     *
     * @return array the plan, plus 'created' (number of fees written)
     * @throws ValidationException
     */
    public function run(int $clubId, int $year, string $dueDate, ?string $description = null): array {
        $plan = $this->plan($clubId, $year, $dueDate, $description);

        $this->db->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            foreach ($plan['included'] as $entry) {
                $fee = new Fee();
                $fee->setClubId($clubId);
                $fee->setMemberId($entry['memberId']);
                $fee->setAmount($entry['amount']);
                $fee->setStatus('open');
                $fee->setDueDate($dueDate . ' 00:00:00');
                $fee->setDescription($plan['description']);
                $fee->setPeriod((string)$year);
                $fee->setCreatedAt($now);
                $fee->setUpdatedAt($now);
                $this->fees->insert($fee);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $plan['created'] = count($plan['included']);
        $this->auditLog?->record($clubId, 'fee_run', 0, 'create', [
            'year' => $year,
            'dueDate' => $dueDate,
            'created' => $plan['created'],
            'total' => $plan['total'],
        ]);
        return $plan;
    }

    /**
     * @param array<int, FeeRate> $ratesById
     */
    private function rateFor(?int $rateId, array $ratesById, ?FeeRate $default): ?FeeRate {
        if ($rateId !== null && isset($ratesById[$rateId])) {
            return $ratesById[$rateId];
        }
        return $default;
    }
}
