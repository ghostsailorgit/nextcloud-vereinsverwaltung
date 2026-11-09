<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubGroupMapper;
use OCA\Verein\Db\ClubGroupMember;
use OCA\Verein\Db\ClubGroupMemberMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Fee;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\FeeRate;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\Member;
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
 * A category can also have a one-time admission fee: a separate fee (period "join", same due date, so
 * SEPA collects it with the membership fee in one debit) for members who joined in the fee year,
 * once per member. Bestandsmitglieder never get it.
 *
 * With $prorata, a member who joined during the period pays only for the months from
 * the join month (inclusive) to its end, rounded to cents. A
 * membership with Membership::$feeExemptJoinYear pays nothing at all for the
 * year it was joined in (and this takes precedence over $prorata); every
 * year after that is billed normally.
 *
 * A category with age tiers charges the tier of the age on its cut-off day of the
 * fee year. Without a birth date the member is reported, not charged a guessed amount.
 *
 * A group with a fee category of its own (a department) adds a separate fee for each of its members, with its own
 * idempotency: the member's own fee and those of other groups do not count against it.
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
        ?DocumentL10n $documentL10n = null,
        private ?ClubGroupMapper $groups = null,
        private ?ClubGroupMemberMapper $groupPeriods = null
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
        $groupBilled = [];
        $joinFeeBilled = [];
        foreach ($this->fees->findByClub($clubId) as $fee) {
            if ($fee->getPeriod() === FeePeriod::JOIN && $fee->getStatus() !== 'cancelled') {
                $joinFeeBilled[$fee->getMemberId()] = true;
            }
            $covered = FeePeriod::covered($fee->getPeriod());
            if ($covered !== null && $covered['year'] === $year && $fee->getStatus() !== 'cancelled') {
                foreach (range($covered['first'], $covered['last']) as $coveredMonth) {
                    if ($fee->getGroupId() !== null) {
                        $groupBilled[$fee->getMemberId()][$fee->getGroupId()][$coveredMonth] = true;
                    } else {
                        $billedMonths[$fee->getMemberId()][$coveredMonth] = true;
                    }
                }
            }
        }

        [$linkedGroups, $groupStays] = $this->groupFeeSetup($clubId, $ratesById);

        $members = $this->members->findByClub($clubId);
        usort($members, fn ($a, $b) => strcasecmp($a->getName() . $a->getFirstName(), $b->getName() . $b->getFirstName()));

        $included = [];
        $skipped = [];
        $upcoming = [];
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

            // before the checks of the member's own category: a problem there says nothing about the groups
            $groupResult = $this->groupFees($member, $groupStays[$member->getId()] ?? [], $linkedGroups, $groupBilled[$member->getId()] ?? [], $year, $month, $prorata, $intervals, $customDescription);
            foreach ($groupResult['included'] as $entry) {
                $included[] = $entry;
                $total += $entry['amount'];
            }
            foreach ($groupResult['skipped'] as $reason) {
                $skip($reason);
            }
            array_push($upcoming, ...$groupResult['upcoming']);

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
            if ((float)$rate->getJoinFee() > 0 && !empty($join) && (int)substr($join, 0, 4) === $year && !isset($joinFeeBilled[$member->getId()])) {
                $included[] = [
                    'memberId' => $member->getId(),
                    'name' => $member->getFullName(),
                    'category' => $rate->getName(),
                    'amount' => (float)$rate->getJoinFee(),
                    'period' => FeePeriod::JOIN,
                    'interval' => 'once',
                    'description' => $this->doc->t('Admission fee'),
                ];
                $total += (float)$rate->getJoinFee();
            }
            $change = $this->upcomingTierChange($rate, $member->getBirthDate(), $year);
            if ($change !== null) {
                $upcoming[] = ['memberId' => $member->getId(), 'name' => $member->getFullName(), 'category' => $rate->getName()] + $change;
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
                'description' => $this->feeDescription($customDescription, $rate, $this->periodLabel($interval, $year, $period)),
            ] + $tierInfo;
            if ($months !== $period['length']) {
                $entry['fullAmount'] = $fullAmount;
                $entry['months'] = $months;
                $entry['periodMonths'] = $period['length'];
            }
            $included[] = $entry;
            $total += $amount;
        }

        // each member's own fees first, then those of the groups
        $position = array_flip(array_map(fn ($m) => $m->getId(), $members));
        usort($included, fn (array $a, array $b) => [$position[$a['memberId']], isset($a['groupId'])] <=> [$position[$b['memberId']], isset($b['groupId'])]);

        return [
            'year' => $year,
            'dueDate' => $dueDate,
            'description' => $description,
            'prorata' => $prorata,
            'month' => $month,
            'included' => $included,
            'skipped' => $skipped,
            'upcomingTierChanges' => $upcoming,
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
                $fee->setGroupId($entry['groupId'] ?? null);
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

    /** The comment of this run, else the category's own text, else the standard text. */
    private function feeDescription(string $custom, FeeRate $rate, string $label): string {
        if ($custom !== '') {
            return $custom;
        }
        $own = trim((string)$rate->getFeeText());
        if ($own !== '') {
            return str_ireplace(['{period}', '{zeitraum}'], $label, $own);
        }
        return $this->doc->t('Membership fee %s', [$label]);
    }

    /**
     * The groups that have a fee of their own and the stays in them.
     *
     * @param array<int, FeeRate> $ratesById
     * @return array{0: array<int, array{name: string, rate: FeeRate}>, 1: array<int, array<int, ClubGroupMember[]>>} group id => group, member id => group id => stays
     */
    private function groupFeeSetup(int $clubId, array $ratesById): array {
        $linked = [];
        foreach ($this->groups?->findByClub($clubId) ?? [] as $group) {
            $rate = $ratesById[(int)$group->getFeeRateId()] ?? null;
            if ($rate !== null) {
                $linked[$group->getId()] = ['name' => $group->getName(), 'rate' => $rate];
            }
        }
        $stays = [];
        if ($linked !== []) {
            foreach ($this->groupPeriods?->findByClub($clubId) ?? [] as $stay) {
                if (isset($linked[$stay->getGroupId()])) {
                    $stays[$stay->getMemberId()][$stay->getGroupId()][] = $stay;
                }
            }
        }
        return [$linked, $stays];
    }

    /**
     * The fees of the groups a member belongs to, in addition to the member's own. Whoever is in the group at the end of the
     * billing period pays; the rules of the category apply (interval, age tiers), but an admission fee is the club's alone.
     *
     * @param array<int, ClubGroupMember[]> $staysByGroup
     * @param array<int, array{name: string, rate: FeeRate}> $linked
     * @param array<int, array<int, bool>> $billed group id => months with a fee already
     * @return array{included: array, skipped: string[], upcoming: array}
     */
    private function groupFees(Member $member, array $staysByGroup, array $linked, array $billed, int $year, int $month, bool $prorata, ?array $intervals, string $custom): array {
        $out = ['included' => [], 'skipped' => [], 'upcoming' => []];
        foreach ($staysByGroup as $groupId => $stays) {
            $name = $linked[$groupId]['name'];
            $rate = $linked[$groupId]['rate'];
            $skip = function (string $reason) use (&$out, $name): void {
                $out['skipped'][] = $this->l->t('Group %1$s: %2$s', [$name, $reason]);
            };
            $interval = $rate->getBillingInterval();
            $period = FeePeriod::of($interval, $year, $month);
            $stay = $this->stayAtEndOf($stays, sprintf('%d-%02d', $year, $period['lastMonth']));
            if ($stay === null) {
                continue;
            }
            if ($intervals !== null && !in_array($interval, $intervals, true)) {
                $skip($this->l->t('payment interval not part of this run'));
                continue;
            }
            $change = $this->upcomingTierChange($rate, $member->getBirthDate(), $year);
            if ($change !== null) {
                $out['upcoming'][] = ['memberId' => $member->getId(), 'name' => $member->getFullName(), 'category' => $rate->getName()] + $change;
            }
            if (array_intersect_key($billed[$groupId] ?? [], array_flip(range($period['firstMonth'], $period['lastMonth']))) !== []) {
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
                $tierInfo = $this->tierInfo($tier, $tiers, $member->getBirthDate(), $year, $rate->getAgeCutoff(), $stay->getJoinedOn());
            }
            if ($fullAmount <= 0) {
                continue;
            }
            $months = $prorata ? $this->monthsInPeriod($stay->getJoinedOn(), $year, $period) : $period['length'];
            $amount = $months === $period['length'] ? $fullAmount : round($fullAmount * $months / $period['length'], 2);
            if ($amount <= 0) {
                continue;
            }
            $entry = [
                'memberId' => $member->getId(),
                'name' => $member->getFullName(),
                'category' => $rate->getName(),
                'amount' => $amount,
                'period' => $period['key'],
                'interval' => $interval,
                'description' => $this->groupFeeDescription($custom, $rate, $this->periodLabel($interval, $year, $period), $name),
                'groupId' => $groupId,
                'groupName' => $name,
            ] + $tierInfo;
            if ($months !== $period['length']) {
                $entry['fullAmount'] = $fullAmount;
                $entry['months'] = $months;
                $entry['periodMonths'] = $period['length'];
            }
            $out['included'][] = $entry;
        }
        return $out;
    }

    /**
     * The latest stay that has begun by the given month and does not end before it.
     *
     * @param ClubGroupMember[] $stays
     */
    private function stayAtEndOf(array $stays, string $lastMonth): ?ClubGroupMember {
        $found = null;
        foreach ($stays as $stay) {
            $left = $stay->getLeftOn();
            if (substr($stay->getJoinedOn(), 0, 7) <= $lastMonth && ($left === null || substr($left, 0, 7) >= $lastMonth)
                && ($found === null || $stay->getJoinedOn() > $found->getJoinedOn())) {
                $found = $stay;
            }
        }
        return $found;
    }

    /** Names the group, so that the remittance text of a combined debit tells the fees apart. */
    private function groupFeeDescription(string $custom, FeeRate $rate, string $label, string $group): string {
        if ($custom !== '') {
            return mb_substr($custom . ', ' . $group, 0, 500);
        }
        if (trim((string)$rate->getFeeText()) !== '') {
            return $this->feeDescription('', $rate, $label);
        }
        return $this->doc->t('Fee %1$s %2$s', [$group, $label]);
    }

    /**
     * Whether the member reaches another age tier next year, for the preview.
     *
     * @return array{amount: float, nextAmount: float, nextAgeFrom: int}|null
     */
    private function upcomingTierChange(FeeRate $rate, ?string $birthDate, int $year): ?array {
        $tiers = $rate->getAgeTierList();
        if ($tiers === []) {
            return null;
        }
        $now = AgeGrading::tierFor($tiers, $birthDate, $year, $rate->getAgeCutoff());
        $next = AgeGrading::tierFor($tiers, $birthDate, $year + 1, $rate->getAgeCutoff());
        if ($now === null || $next === null || $now['fromAge'] === $next['fromAge']) {
            return null;
        }
        return ['amount' => $now['amount'], 'nextAmount' => $next['amount'], 'nextAgeFrom' => $next['fromAge']];
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
