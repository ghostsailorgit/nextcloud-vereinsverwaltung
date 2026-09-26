<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRate;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Membership fee categories of a club ("Erwachsene", "Kinder", ...) with the
 * yearly amount each one pays. One category can be the club's default, used
 * for members without an explicit category.
 */
class FeeRateService {
    public function __construct(
        private FeeRateMapper $rates,
        private MembershipMapper $memberships,
        private ClubMapper $clubs,
        private ?AuditLogService $auditLog = null
    ) {
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
            throw new ValidationException('Die Beitragskategorie wird noch von Mitgliedern verwendet');
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
            throw new DoesNotExistException('Beitragskategorie nicht gefunden');
        }
        return $rate;
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
            throw new ValidationException('Name der Beitragskategorie ist erforderlich');
        }
        foreach ($this->rates->findByClub($clubId) as $other) {
            if ($other->getId() !== $existingId && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new ValidationException('Diese Beitragskategorie gibt es schon');
            }
        }

        $raw = str_replace(',', '.', trim((string)($data['amount'] ?? '')));
        if ($raw === '' || !is_numeric($raw)) {
            throw new ValidationException('Betrag ist ungültig');
        }
        $amount = round((float)$raw, 2);
        if ($amount < 0 || $amount > 100000) {
            throw new ValidationException('Betrag muss zwischen 0 und 100.000 liegen (0 = beitragsfrei)');
        }

        $rate->setName($name);
        $rate->setAmount($amount);
    }

    private function toBool(mixed $value): bool {
        return is_bool($value) ? $value : in_array($value, ['1', 1, 'true', true], true);
    }
}
