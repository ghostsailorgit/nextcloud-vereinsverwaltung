<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\Member;

/**
 * Who pays a member's fees: someone else in the club (Membership::$payerMemberId, e.g. a parent) or the member.
 * A payer who has left, is deactivated or anonymized no longer counts, the member is then on their own again.
 */
final class FeePayer {
    /**
     * @param array<int, Member> $membersById the club's members
     * @return int the member id the fees of $memberId are billed to
     */
    public static function of(int $memberId, array $membersById): int {
        $member = $membersById[$memberId] ?? null;
        $payerId = $member?->getPayerMemberId();
        if ($member === null || $payerId === null || $member->getDeactivated()) {
            return $memberId;
        }
        $payer = $membersById[$payerId] ?? null;
        if ($payer === null || $payer->isFormer() || $payer->getDeactivated() || $payer->getAnonymizedAt() !== null) {
            return $memberId;
        }
        return $payerId;
    }
}
