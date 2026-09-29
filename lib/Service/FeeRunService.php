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
 * The annual fee run: creates one membership fee per active member of a club
 * according to their fee category. Idempotent - a member who already has a
 * (non-cancelled) fee for that year is skipped - so a run can be repeated
 * after new members joined, and previewed before anything is written.
 *
 * With $prorata, a member who joined during the fee year pays only for the
 * months from the join month (inclusive) to December, rounded to cents.
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
    public function plan(int $clubId, int $year, string $dueDate, ?string $description = null, bool $prorata = false): array {
        $this->clubs->find($clubId);
        if ($year < 2000 || $year > 2100) {
            throw new ValidationException($this->l->t('Year is invalid'));
        }
        $due = \DateTime::createFromFormat('Y-m-d', $dueDate);
        if ($due === false || $due->format('Y-m-d') !== $dueDate) {
            throw new ValidationException($this->l->t('Due date is invalid'));
        }
        $description = trim((string)$description);
        if ($description === '') {
            $description = $this->doc->t('Membership fee %s', [$year]);
        }
        if (mb_strlen($description) > 500) {
            throw new ValidationException($this->l->t('Comment is too long'));
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
                $skip($this->l->t('left or deceased'));
                continue;
            }
            if ($member->getDeactivated()) {
                $skip($this->l->t('deactivated'));
                continue;
            }
            $join = $member->getJoinDate();
            if (!empty($join) && (int)substr($join, 0, 4) > $year) {
                $skip($this->l->t('joins after %s', [$year]));
                continue;
            }
            if (isset($alreadyBilled[$member->getId()])) {
                $skip($this->l->t('fee %s already exists', [$year]));
                continue;
            }

            $rate = $this->rateFor($member->getMembership()?->getFeeRateId(), $ratesById, $default);
            if ($rate === null) {
                $skip($this->l->t('no fee category'));
                continue;
            }
            if ((float)$rate->getAmount() <= 0) {
                $skip($this->l->t('fee-exempt (%s)', [$rate->getName()]));
                continue;
            }

            $fullAmount = (float)$rate->getAmount();
            $months = $prorata ? $this->monthsInYear($join, $year) : 12;
            $amount = $months === 12 ? $fullAmount : round($fullAmount * $months / 12, 2);
            if ($amount <= 0) {
                $skip($this->l->t('pro rata fee would be 0'));
                continue;
            }

            $entry = [
                'memberId' => $member->getId(),
                'name' => $member->getFullName(),
                'category' => $rate->getName(),
                'amount' => $amount,
            ];
            if ($months !== 12) {
                $entry['fullAmount'] = $fullAmount;
                $entry['months'] = $months;
            }
            $included[] = $entry;
            $total += $amount;
        }

        return [
            'year' => $year,
            'dueDate' => $dueDate,
            'description' => $description,
            'prorata' => $prorata,
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
    public function run(int $clubId, int $year, string $dueDate, ?string $description = null, bool $prorata = false): array {
        $plan = $this->plan($clubId, $year, $dueDate, $description, $prorata);

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
            'prorata' => $prorata,
        ]);
        return $plan;
    }

    /**
     * Months of $year the membership is billable for: 12, or - if it began during
     * $year - the join month and every month after it. A missing or unreadable
     * join date counts as a full year (the member is not charged less by accident
     * of bad data, and the preview shows the full amount).
     */
    private function monthsInYear(?string $joinDate, int $year): int {
        if (empty($joinDate) || !preg_match('/^(\d{4})-(\d{2})/', $joinDate, $m)) {
            return 12;
        }
        $joinYear = (int)$m[1];
        $joinMonth = (int)$m[2];
        if ($joinYear !== $year || $joinMonth < 1 || $joinMonth > 12) {
            return 12;
        }
        return 13 - $joinMonth;
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
