<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubAccountMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Fee;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Exception\ValidationException;
use OCP\IDBConnection;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * Dunning (Mahnwesen): finds fees that are overdue, groups them into one letter per person and raises
 * their dunning level - reminder, first and final dunning letter. Like the fee run it is previewed
 * first, and it is safe to repeat: a person dunned less than $intervalDays ago is skipped, so running
 * it twice in a row does not send a second letter.
 *
 * Nothing is sent by the app: the letters are a PDF to print or mail (DunningService::letters()).
 */
class DunningService {
    private IL10N $l;

    public const LEVELS = [
        1 => 'Zahlungserinnerung',
        2 => '1. Mahnung',
        3 => '2. und letzte Mahnung',
    ];
    public const MAX_LEVEL = 3;

    public function __construct(
        private FeeMapper $fees,
        private MemberMapper $members,
        private ClubMapper $clubs,
        private ClubAccountMapper $accounts,
        private IDBConnection $db,
        private ?AuditLogService $auditLog = null,
        private ?Clock $clock = null,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
    }

    /**
     * Who would get which letter, without writing anything.
     *
     * @param int $overdueDays a fee counts once its due date is at least this many days in the past
     * @param int $intervalDays minimum days between two letters to the same person
     * @return array{included: array, skipped: array, total: float, overdueDays: int, intervalDays: int, hasAccount: bool}
     * @throws ValidationException
     */
    public function plan(int $clubId, int $overdueDays = 14, int $intervalDays = 14, ?string $today = null): array {
        $this->clubs->find($clubId);
        if ($overdueDays < 0 || $overdueDays > 365 || $intervalDays < 0 || $intervalDays > 365) {
            throw new ValidationException($this->l->t('Days must be between 0 and 365'));
        }
        $today = $today ?? Clock::todayOf($this->clock);
        $dueBefore = date('Y-m-d', strtotime($today . ' -' . $overdueDays . ' days'));
        $lastLetterBefore = date('Y-m-d', strtotime($today . ' -' . $intervalDays . ' days'));

        /** @var array<int, Fee[]> $dueByMember */
        $dueByMember = [];
        foreach ($this->fees->findByClub($clubId) as $fee) {
            if (!in_array($fee->getStatus(), ['open', 'overdue'], true)) {
                continue;
            }
            if (substr((string)$fee->getDueDate(), 0, 10) > $dueBefore || substr((string)$fee->getDueDate(), 0, 10) === '') {
                continue;
            }
            $dueByMember[$fee->getMemberId()][] = $fee;
        }

        $membersById = [];
        foreach ($this->members->findByClub($clubId) as $member) {
            $membersById[$member->getId()] = $member;
        }

        $included = [];
        $skipped = [];
        $total = 0.0;
        foreach ($dueByMember as $memberId => $fees) {
            $member = $membersById[$memberId] ?? null;
            $name = $member !== null ? $member->getFullName() : $this->l->t('Person #%s', [$memberId]);
            $skip = function (string $reason) use (&$skipped, $memberId, $name): void {
                $skipped[] = ['memberId' => $memberId, 'name' => $name, 'reason' => $reason];
            };

            if ($member === null) {
                $skip($this->l->t('no longer a member of this club'));
                continue;
            }
            if ($member->getAnonymizedAt() !== null) {
                $skip($this->l->t('anonymized'));
                continue;
            }
            if ($member->getDeactivated()) {
                $skip($this->l->t('deactivated (no payment transactions)'));
                continue;
            }

            $currentLevel = 0;
            $lastDunned = null;
            foreach ($fees as $fee) {
                $currentLevel = max($currentLevel, (int)$fee->getDunningLevel());
                $d = $fee->getLastDunnedAt() !== null ? substr($fee->getLastDunnedAt(), 0, 10) : null;
                if ($d !== null && ($lastDunned === null || $d > $lastDunned)) {
                    $lastDunned = $d;
                }
            }
            $allAtMax = array_reduce($fees, fn ($carry, Fee $f) => $carry && (int)$f->getDunningLevel() >= self::MAX_LEVEL, true);
            if ($allAtMax) {
                $skip($this->l->t('highest dunning level reached (%s)', [$this->l->t('Second and final dunning letter')]));
                continue;
            }
            if ($lastDunned !== null && $lastDunned > $lastLetterBefore) {
                $skip($this->l->t('last dunned on %s', [$this->formatDate($lastDunned)]));
                continue;
            }

            $level = min(self::MAX_LEVEL, $currentLevel + 1);
            $sum = 0.0;
            $feeRows = [];
            foreach ($fees as $fee) {
                $sum += (float)$fee->getAmount();
                $feeRows[] = [
                    'id' => $fee->getId(),
                    'description' => $fee->getDescription(),
                    'period' => $fee->getPeriod(),
                    'amount' => (float)$fee->getAmount(),
                    'dueDate' => substr((string)$fee->getDueDate(), 0, 10),
                    'dunningLevel' => (int)$fee->getDunningLevel(),
                ];
            }
            $included[] = [
                'memberId' => $memberId,
                'name' => $name,
                'level' => $level,
                'levelLabel' => self::LEVELS[$level],
                'fees' => $feeRows,
                'total' => round($sum, 2),
                'hasAddress' => $this->hasPostalAddress($member),
            ];
            $total += $sum;
        }

        usort($included, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($skipped, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return [
            'included' => $included,
            'skipped' => $skipped,
            'total' => round($total, 2),
            'overdueDays' => $overdueDays,
            'intervalDays' => $intervalDays,
            'hasAccount' => $this->defaultAccount($clubId) !== null,
        ];
    }

    /**
     * Raises the dunning level of every fee the plan lists - all or none - and returns the plan plus
     * the ids of the fees dunned (for the letters).
     *
     * @return array the plan, plus 'dunned' (letters) and 'feeIds'
     * @throws ValidationException
     */
    public function run(int $clubId, int $overdueDays = 14, int $intervalDays = 14, ?string $today = null): array {
        $plan = $this->plan($clubId, $overdueDays, $intervalDays, $today);
        $now = ($today ?? Clock::todayOf($this->clock)) . ' ' . Clock::nowOf($this->clock)->format('H:i:s');

        $idsByLevel = [];
        foreach ($plan['included'] as $letter) {
            foreach ($letter['fees'] as $fee) {
                $idsByLevel[$letter['level']][] = $fee['id'];
            }
        }

        $this->db->beginTransaction();
        try {
            foreach ($idsByLevel as $level => $ids) {
                $this->fees->markDunnedInClub($clubId, $ids, (int)$level, $now);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $feeIds = array_merge([], ...array_values($idsByLevel));
        $plan['dunned'] = count($plan['included']);
        $plan['feeIds'] = $feeIds;
        if ($feeIds !== []) {
            $perLevel = [];
            foreach ($plan['included'] as $letter) {
                $perLevel[$letter['levelLabel']] = ($perLevel[$letter['levelLabel']] ?? 0) + 1;
            }
            $this->auditLog?->record($clubId, 'fee', 0, 'dunning', [
                'letters' => $plan['dunned'],
                'count' => count($feeIds),
                'total' => $plan['total'],
                'levels' => $perLevel,
            ]);
        }
        return $plan;
    }

    /**
     * The data for one letter per person for the given fees of the club, at each fee's current dunning
     * level (so a letter can be printed again later). Fees of other clubs, paid/cancelled or never dunned
     * fees are ignored.
     *
     * @param int[] $feeIds
     * @return array{club: array, account: ?array, letters: array}
     * @throws ValidationException
     */
    public function letters(int $clubId, array $feeIds, int $deadlineDays = 14, ?string $today = null): array {
        $club = $this->clubs->find($clubId);
        if ($deadlineDays < 1 || $deadlineDays > 90) {
            throw new ValidationException($this->l->t('The payment deadline must be between 1 and 90 days'));
        }
        $wanted = array_flip(array_map('intval', $feeIds));
        if ($wanted === []) {
            throw new ValidationException($this->l->t('No fees selected'));
        }
        $today = $today ?? Clock::todayOf($this->clock);

        $membersById = [];
        foreach ($this->members->findByClub($clubId) as $member) {
            $membersById[$member->getId()] = $member;
        }

        $byMember = [];
        foreach ($this->fees->findByClub($clubId) as $fee) {
            if (!isset($wanted[$fee->getId()]) || (int)$fee->getDunningLevel() < 1
                || !in_array($fee->getStatus(), ['open', 'overdue'], true)) {
                continue;
            }
            $byMember[$fee->getMemberId()][] = $fee;
        }

        $letters = [];
        foreach ($byMember as $memberId => $fees) {
            $member = $membersById[$memberId] ?? null;
            if ($member === null || $member->getAnonymizedAt() !== null) {
                continue;
            }
            $level = max(array_map(fn (Fee $f) => (int)$f->getDunningLevel(), $fees));
            $level = min(self::MAX_LEVEL, $level);
            usort($fees, fn (Fee $a, Fee $b) => strcmp((string)$a->getDueDate(), (string)$b->getDueDate()));
            $rows = [];
            $sum = 0.0;
            foreach ($fees as $fee) {
                $sum += (float)$fee->getAmount();
                $rows[] = [
                    'text' => $fee->getDescription() ?: ('Beitrag ' . ($fee->getPeriod() ?? '')),
                    'dueDate' => $this->formatDate(substr((string)$fee->getDueDate(), 0, 10)),
                    'amount' => (float)$fee->getAmount(),
                ];
            }
            $letters[] = [
                'memberId' => $memberId,
                'level' => $level,
                'title' => self::LEVELS[$level],
                'address' => $this->addressLines($member),
                'greeting' => $this->greeting($member),
                'fees' => $rows,
                'total' => round($sum, 2),
                'reference' => $this->reference($memberId, $fees),
                'sortKey' => mb_strtolower($member->getName() . ' ' . ($member->getFirstName() ?? '')),
            ];
        }
        // by last name, then first name - the order in which the letters are put into envelopes
        usort($letters, fn ($a, $b) => strcmp($a['sortKey'], $b['sortKey']));

        $account = $this->defaultAccount($clubId);
        return [
            'club' => [
                'name' => $club->getName(),
                'address' => array_values(array_filter([$club->getStreet(), trim(($club->getPostalCode() ?? '') . ' ' . ($club->getCity() ?? ''))])),
            ],
            'account' => $account,
            'date' => $this->formatDate($today),
            'deadline' => $this->formatDate(date('Y-m-d', strtotime($today . ' +' . $deadlineDays . ' days'))),
            'letters' => $letters,
        ];
    }

    private function defaultAccount(int $clubId): ?array {
        $chosen = null;
        foreach ($this->accounts->findByClub($clubId) as $account) {
            if ($chosen === null || $account->getIsDefault()) {
                $chosen = $account;
            }
        }
        return $chosen === null ? null : ['iban' => $chosen->getIban(), 'bic' => $chosen->getBic(), 'label' => $chosen->getLabel()];
    }

    /**
     * Payment reference, e.g. "Beitrag 2025, 2026, Mitglied 17" - only characters every bank accepts in a
     * transfer reference (letters, digits, blank, comma).
     *
     * @param Fee[] $fees
     */
    private function reference(int $memberId, array $fees): string {
        $periods = array_values(array_unique(array_filter(array_map(fn (Fee $f) => $f->getPeriod(), $fees))));
        sort($periods);
        return ($periods !== [] ? 'Beitrag ' . implode(', ', $periods) . ', ' : '') . 'Mitglied ' . $memberId;
    }

    private function hasPostalAddress(Member $member): bool {
        return trim((string)$member->getStreet()) !== '' && trim((string)$member->getCity()) !== '';
    }

    /** @return string[] */
    private function addressLines(Member $member): array {
        $lines = [];
        $salutation = $member->getSalutation();
        if (in_array($salutation, ['Herr', 'Frau'], true)) {
            $lines[] = $salutation;
        }
        $lines[] = trim(($member->getFirstName() ?? '') . ' ' . $member->getName());
        if (trim((string)$member->getStreet()) !== '') {
            $lines[] = trim((string)$member->getStreet());
        }
        $city = trim(($member->getPostalCode() ?? '') . ' ' . ($member->getCity() ?? ''));
        if ($city !== '') {
            $lines[] = $city;
        }
        return $lines;
    }

    private function greeting(Member $member): string {
        return match ($member->getSalutation()) {
            'Herr' => 'Sehr geehrter Herr ' . $member->getName() . ',',
            'Frau' => 'Sehr geehrte Frau ' . $member->getName() . ',',
            default => 'Guten Tag ' . trim(($member->getFirstName() ?? '') . ' ' . $member->getName()) . ',',
        };
    }

    private function formatDate(string $ymd): string {
        $d = \DateTime::createFromFormat('Y-m-d', substr($ymd, 0, 10));
        return $d === false ? $ymd : $d->format('d.m.Y');
    }
}
