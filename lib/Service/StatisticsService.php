<?php

namespace OCA\Verein\Service;

use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\FeeMapper;

class StatisticsService {
    private MemberMapper $memberMapper;
    private FeeMapper $feeMapper;

    public function __construct(
        MemberMapper $memberMapper,
        FeeMapper $feeMapper
    ) {
        $this->memberMapper = $memberMapper;
        $this->feeMapper = $feeMapper;
    }

    public function getMemberStatistics(): array {
        $members = $this->memberMapper->findAll();
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
            'active' => $total, // Assuming all are active for now
            'newThisMonth' => $this->countNewMembersThisMonth($members),
            'growthByMonth' => $this->computeMemberGrowth($members)
        ];
    }

    /**
     * Cumulative member count at the end of each of the last 6 months, based on
     * each member's actual createdAt date (members without a parseable date are
     * excluded from the curve, but still counted in the overall total above).
     */
    private function computeMemberGrowth(array $members): array {
        $createdDates = [];
        foreach ($members as $member) {
            $createdAt = $member->getCreatedAt();
            if (!$createdAt) {
                continue;
            }
            try {
                $createdDates[] = new \DateTime($createdAt);
            } catch (\Exception $e) {
                // Invalid date format, skip
            }
        }

        $labels = [];
        $data = [];
        $monthFormatter = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

        for ($i = 5; $i >= 0; $i--) {
            $monthEnd = new \DateTime('first day of this month');
            $monthEnd->modify("-$i months");
            $monthEnd->modify('last day of this month')->setTime(23, 59, 59);

            $labels[] = $monthFormatter[(int)$monthEnd->format('n') - 1];
            $count = 0;
            foreach ($createdDates as $createdDate) {
                if ($createdDate <= $monthEnd) {
                    $count++;
                }
            }
            $data[] = $count;
        }

        return ['labels' => $labels, 'data' => $data];
    }

    public function getFeeStatistics(): array {
        $fees = $this->feeMapper->findAll();
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
        $count = 0;
        $currentMonth = date('Y-m');
        
        foreach ($members as $member) {
            $createdAt = $member->getCreatedAt();
            if ($createdAt && strpos($createdAt, $currentMonth) === 0) {
                $count++;
            }
        }
        
        return $count;
    }
}
