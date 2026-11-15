<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\CustomField;
use OCA\Verein\Db\CustomFieldMapper;
use OCA\Verein\Db\CustomValue;
use OCA\Verein\Db\CustomValueMapper;
use OCA\Verein\Exception\NotFoundException;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\L10n\SourceL10n;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Fields each club defines for its members itself (licence, pass number, instrument, grade, ...), so that a new kind of
 * information needs no new version of the app. Values are stored per club and member as text, normalised by type:
 * number with a dot, date as YYYY-MM-DD, a select's exact choice, a checkbox as "1" (unchecked = no value).
 *
 * Values are personal data: they belong to the member's record ("My data", data export), are removed when the person
 * leaves the club or is anonymized, and the audit log only notes which fields changed, never the values.
 */
class CustomFieldService {
    private const MAX_LABEL = 100;
    private const MAX_OPTIONS = 100;
    private const MAX_OPTION = 100;
    private const MAX_TEXT = 500;
    public const MAX_FIELDS = 50;

    private IL10N $l;

    public function __construct(
        private CustomFieldMapper $fields,
        private CustomValueMapper $values,
        private ClubMapper $clubs,
        private ?AuditLogService $auditLog = null,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
    }

    /** @return CustomField[] */
    public function fields(int $clubId): array {
        return $this->fields->findByClub($clubId);
    }

    /** @throws ValidationException */
    public function create(int $clubId, array $data): CustomField {
        $this->clubs->find($clubId);
        $existing = $this->fields->findByClub($clubId);
        if (count($existing) >= self::MAX_FIELDS) {
            throw new ValidationException($this->l->t('A club can have at most %s own fields', [self::MAX_FIELDS]));
        }
        $field = new CustomField();
        $field->setClubId($clubId);
        $this->apply($field, $data, $existing);
        if (!array_key_exists('sortOrder', $data)) {
            $field->setSortOrder(count($existing) === 0 ? 0 : max(array_map(fn (CustomField $f) => (int)$f->getSortOrder(), $existing)) + 1);
        }
        $field->setCreatedAt(date('Y-m-d H:i:s'));
        $field = $this->fields->insert($field);
        $this->auditLog?->record($clubId, 'custom_field', $field->getId(), 'create', $field->jsonSerialize());
        return $field;
    }

    /**
     * Changing the type keeps the stored values; ones that do not fit the new type are shown as they are and refused
     * only when the member is saved again.
     *
     * @throws ValidationException
     * @throws NotFoundException
     */
    public function update(int $clubId, int $id, array $data): CustomField {
        $field = $this->own($clubId, $id);
        $before = $field->jsonSerialize();
        $this->apply($field, $data, $this->fields->findByClub($clubId));
        $field = $this->fields->update($field);
        if ($this->auditLog !== null) {
            $changes = $this->auditLog->diff($before, $field->jsonSerialize());
            if ($changes !== []) {
                $this->auditLog->record($clubId, 'custom_field', $id, 'update', $changes);
            }
        }
        return $field;
    }

    /**
     * Removes the field and every value of it.
     *
     * @throws NotFoundException
     */
    public function delete(int $clubId, int $id): void {
        $field = $this->own($clubId, $id);
        $this->values->deleteByField($id);
        $this->fields->delete($field);
        $this->auditLog?->record($clubId, 'custom_field', $id, 'delete', ['label' => $field->getLabel()]);
    }

    /** @return array<int, array<int, string>> member id => field id => value */
    public function valuesForClub(int $clubId): array {
        $byMember = [];
        foreach ($this->values->findByClub($clubId) as $value) {
            if ($value->getValue() !== null && $value->getValue() !== '') {
                $byMember[$value->getMemberId()][$value->getFieldId()] = (string)$value->getValue();
            }
        }
        return $byMember;
    }

    /** @return array<int, string> field id => value */
    public function valuesFor(int $clubId, int $memberId): array {
        $out = [];
        foreach ($this->values->findByMember($clubId, $memberId) as $value) {
            if ($value->getValue() !== null && $value->getValue() !== '') {
                $out[$value->getFieldId()] = (string)$value->getValue();
            }
        }
        return $out;
    }

    /**
     * The member's values with their labels, in the club's order, as a person reads them (for "My data").
     *
     * @return array<int, array{label: string, type: string, value: string}>
     */
    public function labelledValuesFor(int $clubId, int $memberId): array {
        $values = $this->valuesFor($clubId, $memberId);
        $out = [];
        foreach ($this->fields->findByClub($clubId) as $field) {
            if (isset($values[$field->getId()])) {
                $out[] = ['label' => $field->getLabel(), 'type' => (string)($field->getType() ?? 'text'), 'value' => $values[$field->getId()]];
            }
        }
        return $out;
    }

    /**
     * Checks and normalises submitted values (field id => value, or that as JSON). Fields of other clubs and unknown ids
     * are ignored; a field not sent stays as it is - except that creating a member needs every required field.
     *
     * @return array<int, ?string> field id => normalised value (null = empty)
     * @throws ValidationException naming every field that is wrong
     */
    public function validateValues(int $clubId, mixed $raw, bool $creating): array {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : json_decode($raw, true);
        }
        if ($raw === null) {
            $raw = [];
        }
        if (!is_array($raw)) {
            throw new ValidationException($this->l->t('The own fields could not be read'));
        }
        $out = [];
        $errors = [];
        foreach ($this->fields->findByClub($clubId) as $field) {
            $id = $field->getId();
            $sent = array_key_exists($id, $raw) || array_key_exists((string)$id, $raw);
            if (!$sent) {
                if ($creating && $field->getRequired() && $field->getType() !== 'checkbox') {
                    $errors[] = $this->l->t('%s is required', [$field->getLabel()]);
                }
                continue;
            }
            $value = $raw[$id] ?? $raw[(string)$id] ?? null;
            try {
                $normalised = $this->normalise($field, $value);
            } catch (ValidationException $e) {
                $errors[] = $e->getMessage();
                continue;
            }
            if ($normalised === null && $field->getRequired() && $field->getType() !== 'checkbox') {
                $errors[] = $this->l->t('%s is required', [$field->getLabel()]);
                continue;
            }
            $out[$id] = $normalised;
        }
        if ($errors !== []) {
            throw new ValidationException(implode('; ', $errors));
        }
        return $out;
    }

    /**
     * Stores values checked by validateValues(); null removes the value.
     *
     * @param array<int, ?string> $values
     */
    public function saveValues(int $clubId, int $memberId, array $values): void {
        if ($values === []) {
            return;
        }
        $existing = [];
        foreach ($this->values->findByMember($clubId, $memberId) as $row) {
            $existing[$row->getFieldId()] = $row;
        }
        $changed = [];
        foreach ($values as $fieldId => $value) {
            $row = $existing[$fieldId] ?? null;
            if ($value === null) {
                if ($row !== null) {
                    $this->values->delete($row);
                    $changed[] = $fieldId;
                }
                continue;
            }
            if ($row === null) {
                $row = new CustomValue();
                $row->setClubId($clubId);
                $row->setFieldId($fieldId);
                $row->setMemberId($memberId);
                $row->setValue($value);
                $this->values->insert($row);
                $changed[] = $fieldId;
            } elseif ($row->getValue() !== $value) {
                $row->setValue($value);
                $this->values->update($row);
                $changed[] = $fieldId;
            }
        }
        if ($changed !== []) {
            // which fields, never the values (personal data)
            $this->auditLog?->record($clubId, 'member_custom', $memberId, 'update', ['fields' => $changed]);
        }
    }

    public function deleteForMember(int $clubId, int $memberId): void {
        $this->values->deleteByMember($clubId, $memberId);
    }

    public function deleteForPerson(int $memberId): void {
        $this->values->deleteByPerson($memberId);
    }

    /**
     * A value as text for a spreadsheet or a letter: a date as stored (YYYY-MM-DD), a checkbox as yes/no.
     */
    public function display(CustomField $field, ?string $value): string {
        if ($value === null || $value === '') {
            return '';
        }
        return $field->getType() === 'checkbox' ? $this->l->t('Yes') : $value;
    }

    /** @throws ValidationException */
    private function normalise(CustomField $field, mixed $value): ?string {
        if (is_bool($value)) {
            $value = $value ? '1' : '';
        }
        if ($value !== null && !is_scalar($value)) {
            throw new ValidationException($this->l->t('%s is invalid', [$field->getLabel()]));
        }
        $text = trim((string)($value ?? ''));
        switch ($field->getType()) {
            case 'checkbox':
                return in_array(mb_strtolower($text), ['1', 'true', 'yes', 'ja', 'x', 'on'], true) ? '1' : null;
            case 'number':
                if ($text === '') {
                    return null;
                }
                $number = str_replace(',', '.', $text);
                if (!is_numeric($number)) {
                    throw new ValidationException($this->l->t('%s must be a number', [$field->getLabel()]));
                }
                return $number;
            case 'date':
                if ($text === '') {
                    return null;
                }
                $date = $this->date($text);
                if ($date === null) {
                    throw new ValidationException($this->l->t('%s must be a date', [$field->getLabel()]));
                }
                return $date;
            case 'select':
                if ($text === '') {
                    return null;
                }
                foreach ($field->getOptionList() as $option) {
                    if (mb_strtolower($option) === mb_strtolower($text)) {
                        return $option;
                    }
                }
                throw new ValidationException($this->l->t('%1$s: “%2$s” is not one of the choices', [$field->getLabel(), $text]));
            default:
                if ($text === '') {
                    return null;
                }
                if (mb_strlen($text) > self::MAX_TEXT) {
                    throw new ValidationException($this->l->t('%1$s may have at most %2$s characters', [$field->getLabel(), self::MAX_TEXT]));
                }
                return $text;
        }
    }

    /** YYYY-MM-DD or DD.MM.YYYY (as in a spreadsheet) */
    private function date(string $text): ?string {
        foreach (['Y-m-d', 'd.m.Y', 'j.n.Y'] as $format) {
            $parsed = \DateTime::createFromFormat('!' . $format, $text);
            // formatting it back catches impossible dates like 31.02.2026, which PHP would roll over
            if ($parsed !== false && $parsed->format($format) === $text) {
                return $parsed->format('Y-m-d');
            }
        }
        return null;
    }

    /**
     * @param CustomField[] $existing the club's fields
     * @throws ValidationException
     */
    private function apply(CustomField $field, array $data, array $existing): void {
        $label = trim(is_scalar($data['label'] ?? null) ? (string)$data['label'] : '');
        if ($label === '') {
            throw new ValidationException($this->l->t('The name of the field is required'));
        }
        if (mb_strlen($label) > self::MAX_LABEL) {
            throw new ValidationException($this->l->t('The name of the field is too long'));
        }
        foreach ($existing as $other) {
            if ($other->getId() !== $field->getId() && mb_strtolower($other->getLabel()) === mb_strtolower($label)) {
                throw new ValidationException($this->l->t('A field with this name already exists'));
            }
        }
        $type = (string)($data['type'] ?? $field->getType() ?? 'text');
        if (!in_array($type, CustomField::TYPES, true)) {
            throw new ValidationException($this->l->t('The type of the field is invalid'));
        }
        $options = [];
        if ($type === 'select') {
            $options = $this->options($data['options'] ?? ($field->getOptionList()));
            if ($options === []) {
                throw new ValidationException($this->l->t('A selection needs at least one choice'));
            }
        }
        $field->setLabel($label);
        $field->setType($type);
        $field->setOptions($type === 'select' ? json_encode($options, JSON_UNESCAPED_UNICODE) : null);
        $field->setRequired($this->bool($data['required'] ?? $field->getRequired() ?? false));
        $field->setShowInList($this->bool($data['showInList'] ?? $field->getShowInList() ?? false));
        if (array_key_exists('sortOrder', $data) && is_numeric($data['sortOrder'])) {
            $field->setSortOrder((int)$data['sortOrder']);
        }
    }

    /**
     * The choices of a select: a list, JSON of a list, or text with one choice per line.
     *
     * @return string[]
     * @throws ValidationException
     */
    private function options(mixed $raw): array {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/\r\n|\r|\n/', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $option) {
            $option = trim(is_scalar($option) ? (string)$option : '');
            if ($option === '' || isset($seen[mb_strtolower($option)])) {
                continue;
            }
            if (mb_strlen($option) > self::MAX_OPTION) {
                throw new ValidationException($this->l->t('A choice may have at most %s characters', [self::MAX_OPTION]));
            }
            $seen[mb_strtolower($option)] = true;
            $out[] = $option;
        }
        if (count($out) > self::MAX_OPTIONS) {
            throw new ValidationException($this->l->t('A selection may have at most %s choices', [self::MAX_OPTIONS]));
        }
        return $out;
    }

    private function bool(mixed $value): bool {
        return $value === true || $value === 1 || in_array(mb_strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @throws NotFoundException */
    private function own(int $clubId, int $id): CustomField {
        try {
            $field = $this->fields->find($id);
        } catch (DoesNotExistException $e) {
            throw new NotFoundException($this->l->t('Field not found'));
        }
        if ($field->getClubId() !== $clubId) {
            throw new NotFoundException($this->l->t('Field not found'));
        }
        return $field;
    }
}
