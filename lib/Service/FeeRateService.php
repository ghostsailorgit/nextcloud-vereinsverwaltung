<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubGroupMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRate;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * Membership fee categories of a club ("Erwachsene", "Kinder", ...) with the
 * amount each payment has, either one amount or by age (see AgeGrading), and how often it is paid (see FeePeriod). One
 * category can be the club's default, used for members without an explicit category.
 */
class FeeRateService {
    private const MAX_AGE_TIERS = 20;
    /** the length of the remittance text of a SEPA debit */
    private const MAX_FEE_TEXT = 140;

    private IL10N $l;

    public function __construct(
        private FeeRateMapper $rates,
        private MembershipMapper $memberships,
        private ClubMapper $clubs,
        private ?AuditLogService $auditLog = null,
        ?IL10N $l10n = null,
        private ?ClubGroupMapper $groups = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
    }

    /** @return FeeRate[] */
    public function findByClub(int $clubId): array {
        return $this->rates->findByClub($clubId);
    }

    /**
     * @throws ValidationException
     */
    public function create(int $clubId, array $data): FeeRate {
        $this->clubs->find($clubId);
        $rate = new FeeRate();
        $rate->setClubId($clubId);
        $this->apply($rate, $data, $clubId, null);
        $rate->setCreatedAt(date('Y-m-d H:i:s'));
        $isFirst = $this->rates->findByClub($clubId) === [];
        $rate->setIsDefault($isFirst || $this->toBool($data['isDefault'] ?? false));
        $rate = $this->rates->insert($rate);
        if ($rate->getIsDefault()) {
            $this->makeOnlyDefault($rate);
        }
        $this->auditLog?->record($clubId, 'fee_rate', $rate->getId(), 'create', $rate->jsonSerialize());
        return $rate;
    }

    /**
     * @throws ValidationException
     * @throws DoesNotExistException
     */
    public function update(int $clubId, int $id, array $data): FeeRate {
        $rate = $this->ownRate($clubId, $id);
        $before = $rate->jsonSerialize();
        $this->apply($rate, $data, $clubId, $id);
        if ($this->toBool($data['isDefault'] ?? false)) {
            if ($this->usedByGroup($clubId, $id)) {
                throw new ValidationException($this->l->t('A fee category used by a group cannot be the default'));
            }
            $rate->setIsDefault(true);
        }
        $rate = $this->rates->update($rate);
        if ($rate->getIsDefault()) {
            $this->makeOnlyDefault($rate);
        }
        if ($this->auditLog !== null) {
            $changes = $this->auditLog->diff($before, $rate->jsonSerialize());
            if ($changes !== []) {
                $this->auditLog->record($clubId, 'fee_rate', $id, 'update', $changes);
            }
        }
        return $rate;
    }

    /**
     * @throws ValidationException if members still use the category
     * @throws DoesNotExistException
     */
    public function delete(int $clubId, int $id): void {
        $rate = $this->ownRate($clubId, $id);
        if ($this->memberships->countByFeeRate($id) > 0) {
            throw new ValidationException($this->l->t('The fee category is still used by members'));
        }
        if ($this->usedByGroup($clubId, $id)) {
            throw new ValidationException($this->l->t('The fee category is still used by a group'));
        }
        $wasDefault = $rate->getIsDefault();
        $this->rates->delete($rate);
        $this->auditLog?->record($clubId, 'fee_rate', $id, 'delete');
        if ($wasDefault) {
            $remaining = $this->rates->findByClub($clubId);
            if ($remaining !== []) {
                $remaining[0]->setIsDefault(true);
                $this->rates->update($remaining[0]);
            }
        }
    }

    /**
     * @throws DoesNotExistException if the rate does not belong to the club
     */
    private function ownRate(int $clubId, int $id): FeeRate {
        $rate = $this->rates->find($id);
        if ($rate->getClubId() !== $clubId) {
            throw new DoesNotExistException($this->l->t('Fee category not found'));
        }
        return $rate;
    }

    private function usedByGroup(int $clubId, int $id): bool {
        foreach ($this->groups?->findByClub($clubId) ?? [] as $group) {
            if ($group->getFeeRateId() === $id) {
                return true;
            }
        }
        return false;
    }

    private function makeOnlyDefault(FeeRate $default): void {
        foreach ($this->rates->findByClub($default->getClubId()) as $other) {
            if ($other->getId() !== $default->getId() && $other->getIsDefault()) {
                $other->setIsDefault(false);
                $this->rates->update($other);
            }
        }
    }

    /**
     * @throws ValidationException
     */
    private function apply(FeeRate $rate, array $data, int $clubId, ?int $existingId): void {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException($this->l->t('The name of the fee category is required'));
        }
        foreach ($this->rates->findByClub($clubId) as $other) {
            if ($other->getId() !== $existingId && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new ValidationException($this->l->t('This fee category already exists'));
            }
        }

        $tiers = $this->parseAgeTiers($data['ageTiers'] ?? null);
        $interval = $data['billingInterval'] ?? '';
        if ($interval === '' || $interval === null) {
            $interval = $rate->getBillingInterval();
        }
        if (!is_string($interval) || !isset(FeePeriod::INTERVALS[$interval])) {
            throw new ValidationException($this->l->t('The payment interval is invalid'));
        }

        $rate->setName($name);
        $rate->setBillingInterval($interval);
        // null = not sent, keep; empty = remove
        if (($data['joinFee'] ?? null) !== null) {
            $joinFee = $data['joinFee'] === '' ? 0.0 : $this->parseAmount($data['joinFee']);
            $rate->setJoinFee($joinFee > 0 ? $joinFee : null);
        }
        if (($data['feeText'] ?? null) !== null) {
            $feeText = is_string($data['feeText']) ? trim($data['feeText']) : null;
            if ($feeText === null || mb_strlen($feeText) > self::MAX_FEE_TEXT) {
                throw new ValidationException($this->l->t('The payment text is invalid or longer than 140 characters'));
            }
            $rate->setFeeText($feeText === '' ? null : $feeText);
        }
        // with tiers the amount of each tier counts, not this one
        $rate->setAmount($tiers === [] ? $this->parseAmount($data['amount'] ?? '') : 0.0);
        $rate->setAgeTiers($tiers === [] ? null : json_encode($tiers));
        $rate->setAgeCutoff($tiers === [] ? null : $this->parseCutoff($data['ageCutoff'] ?? null));
    }

    /**
     * @throws ValidationException
     */
    private function parseAmount(mixed $value): float {
        $raw = is_scalar($value) ? str_replace(',', '.', trim((string)$value)) : '';
        if ($raw === '' || !is_numeric($raw)) {
            throw new ValidationException($this->l->t('Amount is invalid'));
        }
        $amount = round((float)$raw, 2);
        if ($amount < 0 || $amount > 100000) {
            throw new ValidationException($this->l->t('Amount must be between 0 and 100,000 (0 = fee-exempt)'));
        }
        return $amount;
    }

    /**
     * @return array<int, array{fromAge: int, amount: float}> ascending; empty = the category does not depend on age
     * @throws ValidationException
     */
    private function parseAgeTiers(mixed $raw): array {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        // the web interface sends form fields, so the list arrives as JSON text
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || count($raw) > self::MAX_AGE_TIERS) {
            throw new ValidationException($this->l->t('The age tiers are invalid'));
        }
        $byAge = [];
        foreach ($raw as $tier) {
            $from = is_array($tier) ? filter_var($tier['fromAge'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 150]]) : false;
            if ($from === false) {
                throw new ValidationException($this->l->t('The age tiers are invalid'));
            }
            if (isset($byAge[$from])) {
                throw new ValidationException($this->l->t('Each age may only start one age tier'));
            }
            $byAge[$from] = ['fromAge' => $from, 'amount' => $this->parseAmount($tier['amount'] ?? '')];
        }
        if (!isset($byAge[0])) {
            throw new ValidationException($this->l->t('The first age tier must start at age 0'));
        }
        ksort($byAge);
        return array_values($byAge);
    }

    /**
     * @throws ValidationException
     */
    private function parseCutoff(mixed $raw): string {
        if ($raw === null || $raw === '') {
            return AgeGrading::DEFAULT_CUTOFF;
        }
        // 2024 is a leap year, so February 29 is a possible day
        if (!is_string($raw) || !preg_match('/^(\d{2})-(\d{2})$/', $raw, $m) || !checkdate((int)$m[1], (int)$m[2], 2024)) {
            throw new ValidationException($this->l->t('The cut-off day is invalid'));
        }
        return $raw;
    }


    private function toBool(mixed $value): bool {
        return is_bool($value) ? $value : in_array($value, ['1', 1, 'true', true], true);
    }
}
