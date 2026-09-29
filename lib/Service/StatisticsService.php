<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\Verein\Service;

use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

class StatisticsService {
    private IL10N $l;

    private MemberMapper $memberMapper;
    private FeeMapper $feeMapper;

    public function __construct(
        MemberMapper $memberMapper,
        FeeMapper $feeMapper,
        private ?Clock $clock = null,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        $this->memberMapper = $memberMapper;
        $this->feeMapper = $feeMapper;
    }

    public function getMemberStatistics(int $clubId): array {
        $members = $this->memberMapper->findByClub($clubId);
        $total = count($members);

        // Group by role
        $byRole = [];
        foreach ($members as $member) {
            $role = $member->getRole();
            if (!isset($byRole[$role])) {
                $byRole[$role] = 0;
            }
            $byRole[$role]++;
        }

        return [
            'total' => $total,
            'byRole' => $byRole,
            // what the member list calls "active": not left, not deceased (the dashboard shows this one)
            'active' => count(array_filter($members, fn ($m) => !$m->isFormer())),
            'newThisMonth' => $this->countNewMembersThisMonth($members),
            'growthByMonth' => $this->computeMemberGrowth($members),
            'upcomingBirthdays' => $this->getUpcomingBirthdays($members),
            'upcomingAnniversaries' => $this->getUpcomingAnniversaries($members)
        ];
    }

    /**
     * The next N birthdays among active (non-former) members with a
     * birthDate set, soonest first.
     */
    private function getUpcomingBirthdays(array $members, int $limit = 5): array {
        $today = new \DateTime('today');
        $upcoming = [];

        foreach ($members as $member) {
            if ($member->isFormer() || empty($member->getBirthDate())) {
                continue;
            }
            try {
                $birthDate = new \DateTime($member->getBirthDate());
            } catch (\Exception $e) {
                continue;
            }
            $nextDate = $this->nextOccurrence($today, (int)$birthDate->format('n'), (int)$birthDate->format('j'));

            $upcoming[] = [
                'memberId' => $member->getId(),
                'name' => $member->getFullName(),
                'nextDate' => $nextDate->format('Y-m-d'),
                'turningAge' => (int)$nextDate->format('Y') - (int)$birthDate->format('Y')
            ];
        }

        usort($upcoming, fn($a, $b) => $a['nextDate'] <=> $b['nextDate']);
        return array_slice($upcoming, 0, $limit);
    }

    /**
     * The next N membership anniversaries among active (non-former) members
     * with a joinDate set, soonest first.
     */
    private function getUpcomingAnniversaries(array $members, int $limit = 5): array {
        $today = new \DateTime('today');
        $upcoming = [];

        foreach ($members as $member) {
            if ($member->isFormer() || empty($member->getJoinDate())) {
                continue;
            }
            try {
                $joinDate = new \DateTime($member->getJoinDate());
            } catch (\Exception $e) {
                continue;
            }
            $nextDate = $this->nextOccurrence($today, (int)$joinDate->format('n'), (int)$joinDate->format('j'));
            $years = (int)$nextDate->format('Y') - (int)$joinDate->format('Y');
            if ($years <= 0) {
                // Joined this year - no anniversary to reach yet
                continue;
            }

            $upcoming[] = [
                'memberId' => $member->getId(),
                'name' => $member->getFullName(),
                'nextDate' => $nextDate->format('Y-m-d'),
                'years' => $years
            ];
        }

        usort($upcoming, fn($a, $b) => $a['nextDate'] <=> $b['nextDate']);
        return array_slice($upcoming, 0, $limit);
    }

    /**
     * The next occurrence of a given month/day on or after $today (this
     * year if it hasn't passed yet, otherwise next year).
     */
    private function nextOccurrence(\DateTime $today, int $month, int $day): \DateTime {
        $year = (int)$today->format('Y');
        $candidate = new \DateTime();
        $candidate->setDate($year, $month, $day);
        $candidate->setTime(0, 0, 0);
        if ($candidate < $today) {
            $candidate->setDate($year + 1, $month, $day);
        }
        return $candidate;
    }

    /**
     * When the person became a member of the club: the join date, or - if none was entered - the day the
     * record was created. The creation date alone made the curve jump from 0 to everyone in the month a club
     * entered or imported its members.
     */
    private function memberSince(Member $member): ?string {
        foreach ([$member->getJoinDate(), $member->getCreatedAt()] as $date) {
            $day = $this->day($date);
            if ($day !== null) {
                return $day;
            }
        }
        return null;
    }

    /** A stored date or timestamp as its calendar day "Y-m-d" (compared as text: no time zone can shift it). */
    private function day(?string $date): ?string {
        $day = substr((string)$date, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : null;
    }

    /**
     * Number of members at the end of each of the last 6 months: joined by then (see memberSince()) and not
     * left by then. A deceased person without a leave date is not counted (the date of death is unknown).
     */
    private function computeMemberGrowth(array $members): array {
        $periods = [];
        foreach ($members as $member) {
            $since = $this->memberSince($member);
            if ($since === null || ($member->getDeceased() && empty($member->getLeaveDate()))) {
                continue;
            }
            $periods[] = [$since, $this->day($member->getLeaveDate())];
        }

        $labels = [];
        $data = [];
        $monthFormatter = [$this->l->t('Jan'), $this->l->t('Feb'), $this->l->t('Mar'), $this->l->t('Apr'), $this->l->t('May'), $this->l->t('Jun'), $this->l->t('Jul'), $this->l->t('Aug'), $this->l->t('Sep'), $this->l->t('Oct'), $this->l->t('Nov'), $this->l->t('Dec')];
        $firstOfThisMonth = Clock::nowOf($this->clock)->modify('first day of this month')->setTime(0, 0);

        for ($i = 5; $i >= 0; $i--) {
            $monthEndDate = $firstOfThisMonth->modify("-$i months")->modify('last day of this month');
            $monthEnd = $monthEndDate->format('Y-m-d');
            $labels[] = $monthFormatter[(int)$monthEndDate->format('n') - 1];
            $count = 0;
            foreach ($periods as [$since, $until]) {
                // the leave date is the last day of the membership
                if ($since <= $monthEnd && ($until === null || $until >= $monthEnd)) {
                    $count++;
                }
            }
            $data[] = $count;
        }

        return ['labels' => $labels, 'data' => $data];
    }

    public function getFeeStatistics(int $clubId): array {
        $fees = $this->feeMapper->findByClub($clubId);
        $totalAmount = 0.0;
        $paidAmount = 0.0;
        $pendingAmount = 0.0;
        $overdueAmount = 0.0;
        $dueAmount = 0.0;

        $paidCount = 0;
        $pendingCount = 0;
        $overdueCount = 0;
        $dueCount = 0;

        $now = new \DateTime();

        foreach ($fees as $fee) {
            $amount = (float)$fee->getAmount();
            $totalAmount += $amount;
            $dueDate = $fee->getDueDate();

            switch ($fee->getStatus()) {
                case 'paid':
                    $paidAmount += $amount;
                    $paidCount++;
                    break;
                case 'pending':
                    $pendingAmount += $amount;
                    $pendingCount++;
                    // Check if due date has passed
                    if ($dueDate) {
                        try {
                            $dueDateObj = new \DateTime($dueDate);
                            if ($dueDateObj < $now) {
                                $dueAmount += $amount;
                                $dueCount++;
                            }
                        } catch (\Exception $e) {
                            // Invalid date format, skip
                        }
                    }
                    break;
                case 'overdue':
                    $overdueAmount += $amount;
                    $overdueCount++;
                    break;
            }
        }

        return [
            'totalAmount' => $totalAmount,
            'paidAmount' => $paidAmount,
            'pendingAmount' => $pendingAmount,
            'overdueAmount' => $overdueAmount,
            'dueAmount' => $dueAmount,
            'counts' => [
                'total' => count($fees),
                'paid' => $paidCount,
                'pending' => $pendingCount,
                'overdue' => $overdueCount,
                'due' => $dueCount
            ]
        ];
    }

    private function countNewMembersThisMonth(array $members): int {
        $currentMonth = Clock::nowOf($this->clock)->format('Y-m');
        $count = 0;
        foreach ($members as $member) {
            if (substr((string)$this->memberSince($member), 0, 7) === $currentMonth) {
                $count++;
            }
        }
        return $count;
    }
}
