<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;
use OCA\Verein\L10n\DocumentL10n;

/**
 * The fee run: creates one membership fee per active member of a club according to
 * their fee category, for the period of the category's payment interval that
 * contains the chosen month (yearly categories: the whole year). The amount of the
 * category is the amount of one payment. Idempotent - a member with a (non-cancelled)
 * fee covering any month of that period is skipped, also after a change of interval -
 * so a run can be repeated after new members joined, and previewed before anything
 * is written.
 *
 * With $prorata, a member who joined during the period pays only for the months from
 * the join month (inclusive) to its end, rounded to cents. A
 * membership with Membership::$feeExemptJoinYear pays nothing at all for the
 * year it was joined in (and this takes precedence over $prorata); every
 * year after that is billed normally.
 *
 * A category with age tiers charges the tier of the age on its cut-off day of the
 * fee year. Without a birth date the member is reported, not charged a guessed amount.
 */
class FeeRunService {
    private IL10N $l;
    private IL10N $doc;

    public function __construct(
        private FeeRateMapper $rates,
        private MemberMapper $members,
        private FeeMapper $fees,
        private ClubMapper $clubs,
        private IDBConnection $db,
        private ?AuditLogService $auditLog = null,
        ?IL10N $l10n = null,
        ?DocumentL10n $documentL10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        $this->doc = $documentL10n?->get() ?? $this->l;
    }

    /**
     * What a run would do, without writing anything.
     *
     * @return array{year: int, dueDate: string, description: string, prorata: bool, included: array, skipped: array, total: float}
     * @throws ValidationException
     */
    public function plan(int $clubId, int $year, string $dueDate, ?string $description = null, bool $prorata = false, ?int $month = null, ?array $intervals = null): array {
        $this->clubs->find($clubId);
        if ($year < 2000 || $year > 2100) {
            throw new ValidationException($this->l->t('Year is invalid'));
        }
        $due = \DateTime::createFromFormat('Y-m-d', $dueDate);
        if ($due === false || $due->format('Y-m-d') !== $dueDate) {
            throw new ValidationException($this->l->t('Due date is invalid'));
        }
        $month ??= (int)$due->format('n');
        if ($month < 1 || $month > 12) {
            throw new ValidationException($this->l->t('Month is invalid'));
        }
        if ($intervals !== null && array_diff($intervals, array_keys(FeePeriod::INTERVALS)) !== []) {
            throw new ValidationException($this->l->t('The payment interval is invalid'));
        }
        $customDescription = trim((string)$description);
        if (mb_strlen($customDescription) > 500) {
            throw new ValidationException($this->l->t('Comment is too long'));
        }
        $description = $customDescription !== '' ? $customDescription : $this->doc->t('Membership fee %s', [$year]);

        $ratesById = [];
        $default = null;
        foreach ($this->rates->findByClub($clubId) as $rate) {
            $ratesById[$rate->getId()] = $rate;
            if ($rate->getIsDefault()) {
                $default = $rate;
            }
        }

        // the months of this year each member already has a fee for (cancelled ones don't count)
        $billedMonths = [];
        foreach ($this->fees->findByClub($clubId) as $fee) {
            $covered = FeePeriod::covered($fee->getPeriod());
            if ($covered !== null && $covered['year'] === $year && $fee->getStatus() !== 'cancelled') {
                foreach (range($covered['first'], $covered['last']) as $coveredMonth) {
                    $billedMonths[$fee->getMemberId()][$coveredMonth] = true;
                }
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
                $skip($this->l->t('left or deceased'));
                continue;
            }
            if ($member->getDeactivated()) {
                $skip($this->l->t('deactivated'));
                continue;
            }
            if ($member->getHonoraryMember() && !$member->getHonoraryPaysFee()) {
                $skip($this->l->t('honorary member'));
                continue;
            }
            $join = $member->getJoinDate();
            if (!empty($join) && (int)substr($join, 0, 4) > $year) {
                $skip($this->l->t('joins after %s', [$year]));
                continue;
            }
            if ($member->getFeeExemptJoinYear() && !empty($join) && (int)substr($join, 0, 4) === $year) {
                $skip($this->l->t('fee-exempt in the year joined'));
                continue;
            }

            $rate = $this->rateFor($member->getMembership()?->getFeeRateId(), $ratesById, $default);
            if ($rate === null) {
                $skip($this->l->t('no fee category'));
                continue;
            }
            $interval = $rate->getBillingInterval();
            if ($intervals !== null && !in_array($interval, $intervals, true)) {
                $skip($this->l->t('payment interval not part of this run'));
                continue;
            }
            $period = FeePeriod::of($interval, $year, $month);
            if (!empty($join) && substr($join, 0, 7) > sprintf('%d-%02d', $year, $period['lastMonth'])) {
                $skip($this->l->t('joins after %s', [$period['key']]));
                continue;
            }
            if (array_intersect_key($billedMonths[$member->getId()] ?? [], array_flip(range($period['firstMonth'], $period['lastMonth']))) !== []) {
                $skip($this->l->t('fee %s already exists', [$period['key']]));
                continue;
            }
            $fullAmount = (float)$rate->getAmount();
            $tierInfo = [];
            $tiers = $rate->getAgeTierList();
            if ($tiers !== []) {
                $tier = AgeGrading::tierFor($tiers, $member->getBirthDate(), $year, $rate->getAgeCutoff());
                if ($tier === null) {
                    $skip($this->l->t('no date of birth (fee category by age)'));
                    continue;
                }
                $fullAmount = $tier['amount'];
                $tierInfo = $this->tierInfo($tier, $tiers, $member->getBirthDate(), $year, $rate->getAgeCutoff(), $join);
            }
            if ($fullAmount <= 0) {
                $skip($this->l->t('fee-exempt (%s)', [$rate->getName()]));
                continue;
            }

            $months = $prorata ? $this->monthsInPeriod($join, $year, $period) : $period['length'];
            $amount = $months === $period['length'] ? $fullAmount : round($fullAmount * $months / $period['length'], 2);
            if ($amount <= 0) {
                $skip($this->l->t('pro rata fee would be 0'));
                continue;
            }

            $entry = [
                'memberId' => $member->getId(),
                'name' => $member->getFullName(),
                'category' => $rate->getName(),
                'amount' => $amount,
                'period' => $period['key'],
                'interval' => $interval,
                'description' => $customDescription !== '' ? $customDescription : $this->doc->t('Membership fee %s', [$this->periodLabel($interval, $year, $period)]),
            ] + $tierInfo;
            if ($months !== $period['length']) {
                $entry['fullAmount'] = $fullAmount;
                $entry['months'] = $months;
                $entry['periodMonths'] = $period['length'];
            }
            $included[] = $entry;
            $total += $amount;
        }

        return [
            'year' => $year,
            'dueDate' => $dueDate,
            'description' => $description,
            'prorata' => $prorata,
            'month' => $month,
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
    public function run(int $clubId, int $year, string $dueDate, ?string $description = null, bool $prorata = false, ?int $month = null, ?array $intervals = null): array {
        $plan = $this->plan($clubId, $year, $dueDate, $description, $prorata, $month, $intervals);

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
                $fee->setDescription($entry['description']);
                $fee->setPeriod($entry['period']);
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
            'month' => $plan['month'],
            'dueDate' => $dueDate,
            'created' => $plan['created'],
            'total' => $plan['total'],
            'prorata' => $prorata,
        ]);
        return $plan;
    }

    /**
     * For the preview: the tier, and the previous one if the member moved up since last year. Somebody who only joined
     * this year did not change tier.
     *
     * @param array{fromAge: int, amount: float} $tier
     * @param array<int, array{fromAge: int, amount: float}> $tiers
     */
    private function tierInfo(array $tier, array $tiers, ?string $birthDate, int $year, ?string $cutoff, ?string $joinDate): array {
        $info = ['ageFrom' => $tier['fromAge']];
        $previous = AgeGrading::tierFor($tiers, $birthDate, $year - 1, $cutoff);
        $joinedBefore = empty($joinDate) || (int)substr($joinDate, 0, 4) < $year;
        if ($joinedBefore && $previous !== null && $previous['fromAge'] !== $tier['fromAge']) {
            $info['previousAgeFrom'] = $previous['fromAge'];
            $info['previousAmount'] = $previous['amount'];
        }
        return $info;
    }

    /**
     * Months of the period the membership is billable for: all of them, or - if it
     * began during the period - the join month and every month after it. A missing or
     * unreadable join date counts as the full period (the member is not charged less by
     * accident of bad data, and the preview shows the full amount).
     *
     * @param array{firstMonth: int, lastMonth: int, length: int} $period
     */
    private function monthsInPeriod(?string $joinDate, int $year, array $period): int {
        if (empty($joinDate) || !preg_match('/^(\d{4})-(\d{2})/', $joinDate, $m)) {
            return $period['length'];
        }
        $joinMonth = (int)$m[2];
        // somebody who joins after the period does not get here (plan() skips them)
        if ((int)$m[1] !== $year || $joinMonth <= $period['firstMonth']) {
            return $period['length'];
        }
        return $period['lastMonth'] - $joinMonth + 1;
    }

    /** @param array{number: int} $period */
    private function periodLabel(string $interval, int $year, array $period): string {
        return match ($interval) {
            'half_yearly' => $this->doc->t('half-year %1$s of %2$s', [$period['number'], $year]),
            'quarterly' => $this->doc->t('quarter %1$s of %2$s', [$period['number'], $year]),
            'monthly' => sprintf('%02d/%d', $period['number'], $year),
            default => (string)$year,
        };
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
