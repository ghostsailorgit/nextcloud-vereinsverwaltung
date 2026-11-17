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
use OCA\Verein\Db\CustomSection;
use OCA\Verein\Db\CustomSectionMapper;
use OCA\Verein\Db\CustomValue;
use OCA\Verein\Db\CustomValueMapper;
use OCA\Verein\Db\RoleMapper;
use OCA\Verein\Exception\NotFoundException;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\L10n\SourceL10n;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Fields each club defines for its members itself (licence, pass number, instrument, grade, ...), so that a new kind of
 * information needs no new version of the app. Values are stored per club and member as text, normalised by type:
 * number with a dot, date as YYYY-MM-DD, a select's exact choice, a multiple selection as a JSON list of exact choices
 * (in the order of the field's choices), a checkbox as "1" (unchecked = no value).
 *
 * Values are personal data: they belong to the member's record ("My data", data export), are removed when the person
 * leaves the club or is anonymized, and the audit log only notes which fields changed, never the values.
 *
 * Fields can be put into sections (CustomSection) that only some roles may see or change. Everything that hands out
 * values or accepts them takes the result of access() and leaves out what the user may not see or change - the
 * values never leave the server for someone without the right. A field without a section is seen by everyone who
 * sees members and changed by everyone who may manage them.
 */
class CustomFieldService {
    private const MAX_LABEL = 100;
    private const MAX_OPTIONS = 100;
    private const MAX_OPTION = 100;
    private const MAX_TEXT = 500;
    public const MAX_FIELDS = 50;
    public const MAX_SECTIONS = 20;

    private IL10N $l;

    public function __construct(
        private CustomFieldMapper $fields,
        private CustomValueMapper $values,
        private ClubMapper $clubs,
        private ?AuditLogService $auditLog = null,
        private ?CustomSectionMapper $sections = null,
        private ?RoleMapper $roleDefinitions = null,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
    }

    /**
     * @param int[]|null $only field ids to keep (see access()), null = all
     * @return CustomField[]
     */
    public function fields(int $clubId, ?array $only = null): array {
        $fields = $this->fields->findByClub($clubId);
        return $only === null ? $fields : array_values(array_filter($fields, fn (CustomField $f) => in_array($f->getId(), $only, true)));
    }

    /**
     * Which fields someone sees and may change, from the roles they hold in the club (RoleService::sectionRoleIds()).
     * A field in a section is seen with one of its view or edit roles and changed only with an edit role; changing
     * needs the permission to manage members on top, which the routes check. Null role ids = every field.
     *
     * @param int[]|null $roleIds
     * @return array{visible: int[]|null, editable: int[]|null} field ids, null = all
     */
    public function access(int $clubId, ?array $roleIds): array {
        if ($roleIds === null) {
            return ['visible' => null, 'editable' => null];
        }
        $sections = [];
        foreach ($this->sections($clubId) as $section) {
            $sections[$section->getId()] = $section;
        }
        $visible = [];
        $editable = [];
        foreach ($this->fields->findByClub($clubId) as $field) {
            $sectionId = $field->getSectionId();
            if ($sectionId === null) {
                $visible[] = $field->getId();
                $editable[] = $field->getId();
                continue;
            }
            // a field pointing to a section that is gone stays hidden rather than open
            $section = $sections[$sectionId] ?? null;
            if ($section === null) {
                continue;
            }
            $mayEdit = array_intersect($roleIds, $section->getEditRoleIds()) !== [];
            if ($mayEdit || array_intersect($roleIds, $section->getViewRoleIds()) !== []) {
                $visible[] = $field->getId();
            }
            if ($mayEdit) {
                $editable[] = $field->getId();
            }
        }
        return ['visible' => $visible, 'editable' => $editable];
    }

    /** @return CustomSection[] */
    public function sections(int $clubId): array {
        return $this->sections?->findByClub($clubId) ?? [];
    }

    /** @throws ValidationException */
    public function createSection(int $clubId, array $data): CustomSection {
        if ($this->sections === null) {
            throw new ValidationException($this->l->t('Sections are not available'));
        }
        $this->clubs->find($clubId);
        $existing = $this->sections->findByClub($clubId);
        if (count($existing) >= self::MAX_SECTIONS) {
            throw new ValidationException($this->l->t('A club can have at most %s sections', [self::MAX_SECTIONS]));
        }
        $section = new CustomSection();
        $section->setClubId($clubId);
        $this->applySection($section, $data, $existing);
        $section->setSortOrder(count($existing) === 0 ? 0 : max(array_map(fn (CustomSection $s) => (int)$s->getSortOrder(), $existing)) + 1);
        $section->setCreatedAt(date('Y-m-d H:i:s'));
        $section = $this->sections->insert($section);
        $this->auditLog?->record($clubId, 'custom_section', $section->getId(), 'create', $section->jsonSerialize());
        return $section;
    }

    /**
     * @throws ValidationException
     * @throws NotFoundException
     */
    public function updateSection(int $clubId, int $id, array $data): CustomSection {
        $section = $this->ownSection($clubId, $id);
        $before = $section->jsonSerialize();
        $this->applySection($section, $data, $this->sections->findByClub($clubId));
        $section = $this->sections->update($section);
        if ($this->auditLog !== null) {
            $changes = $this->auditLog->diff($before, $section->jsonSerialize());
            if ($changes !== []) {
                $this->auditLog->record($clubId, 'custom_section', $id, 'update', $changes);
            }
        }
        return $section;
    }

    /**
     * Only an empty section can go: removing it would otherwise open its fields to everyone.
     *
     * @throws ValidationException
     * @throws NotFoundException
     */
    public function deleteSection(int $clubId, int $id): void {
        $section = $this->ownSection($clubId, $id);
        foreach ($this->fields->findByClub($clubId) as $field) {
            if ($field->getSectionId() === $id) {
                throw new ValidationException($this->l->t('The section still has fields. Move them to another section or delete them first.'));
            }
        }
        $this->sections->delete($section);
        $this->auditLog?->record($clubId, 'custom_section', $id, 'delete', ['name' => $section->getName()]);
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

    /**
     * @param int[]|null $only field ids to keep (see access()), null = all
     * @return array<int, array<int, string>> member id => field id => value
     */
    public function valuesForClub(int $clubId, ?array $only = null): array {
        $byMember = [];
        foreach ($this->values->findByClub($clubId) as $value) {
            if ($only !== null && !in_array($value->getFieldId(), $only, true)) {
                continue;
            }
            if ($value->getValue() !== null && $value->getValue() !== '') {
                $byMember[$value->getMemberId()][$value->getFieldId()] = (string)$value->getValue();
            }
        }
        return $byMember;
    }

    /**
     * @param int[]|null $only field ids to keep (see access()), null = all
     * @return array<int, string> field id => value
     */
    public function valuesFor(int $clubId, int $memberId, ?array $only = null): array {
        $out = [];
        foreach ($this->values->findByMember($clubId, $memberId) as $value) {
            if ($only !== null && !in_array($value->getFieldId(), $only, true)) {
                continue;
            }
            if ($value->getValue() !== null && $value->getValue() !== '') {
                $out[$value->getFieldId()] = (string)$value->getValue();
            }
        }
        return $out;
    }

    /**
     * The fields whose values the member sees under "My data": fields without a section and those of sections marked
     * "visible to the member". The data export by someone who manages members is not limited by this.
     *
     * @return int[]
     */
    public function memberVisibleFieldIds(int $clubId): array {
        $visible = [];
        foreach ($this->sections($clubId) as $section) {
            $visible[$section->getId()] = $section->isMemberVisible();
        }
        $ids = [];
        foreach ($this->fields->findByClub($clubId) as $field) {
            $sectionId = $field->getSectionId();
            if ($sectionId === null || ($visible[$sectionId] ?? false)) {
                $ids[] = $field->getId();
            }
        }
        return $ids;
    }

    /**
     * The member's values with their labels, in the club's order, as a person reads them: for "My data" all of the
     * person's own values, for the data export of a member only what the exporting user sees.
     *
     * @param int[]|null $only field ids to keep (see access()), null = all
     * @return array<int, array{label: string, type: string, value: string, section: ?string}>
     */
    public function labelledValuesFor(int $clubId, int $memberId, ?array $only = null): array {
        $values = $this->valuesFor($clubId, $memberId, $only);
        $sectionNames = [];
        foreach ($this->sections($clubId) as $section) {
            $sectionNames[$section->getId()] = $section->getName();
        }
        $out = [];
        foreach ($this->fields->findByClub($clubId) as $field) {
            if (isset($values[$field->getId()])) {
                $out[] = [
                    'label' => $field->getLabel(),
                    'type' => (string)($field->getType() ?? 'text'),
                    'value' => $values[$field->getId()],
                    'section' => $field->getSectionId() !== null ? ($sectionNames[$field->getSectionId()] ?? null) : null,
                ];
            }
        }
        return $out;
    }

    /**
     * Checks and normalises submitted values (field id => value, or that as JSON). Fields of other clubs and unknown ids
     * are ignored; a field not sent stays as it is - except that creating a member needs every required field.
     * Fields outside $editable (see access()) are ignored the same way and never required: whoever may not change a
     * section can neither overwrite nor empty it, and can still create members.
     *
     * @param int[]|null $editable field ids the user may change, null = all
     * @return array<int, ?string> field id => normalised value (null = empty)
     * @throws ValidationException naming every field that is wrong
     */
    public function validateValues(int $clubId, mixed $raw, bool $creating, ?array $editable = null): array {
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
            if ($editable !== null && !in_array($id, $editable, true)) {
                continue;
            }
            $sent =array_key_exists($id, $raw) || array_key_exists((string)$id, $raw);
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
     * A value as text for a spreadsheet: a date as stored (YYYY-MM-DD), a checkbox as yes/no, the choices of a multiple
     * selection separated by "; " (what the import reads back).
     */
    public function display(CustomField $field, ?string $value): string {
        if ($value === null || $value === '') {
            return '';
        }
        return match ($field->getType()) {
            'checkbox' => $this->l->t('Yes'),
            'multiselect' => implode('; ', CustomField::valueList($value)),
            default => $value,
        };
    }

    /** @throws ValidationException */
    private function normalise(CustomField $field, mixed $value): ?string {
        if ($field->getType() === 'multiselect') {
            return $this->normaliseChoices($field, $value);
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '';
        }
        // a list with one entry, e.g. a value kept from the time the field allowed several choices
        if (is_array($value) && count($value) === 1 && is_scalar(reset($value))) {
            $value = reset($value);
        } elseif ($field->getType() === 'select' && is_string($value) && str_starts_with(trim($value), '[')) {
            $list = json_decode($value, true);
            if (is_array($list) && count($list) === 1 && is_scalar(reset($list))) {
                $value = reset($list);
            }
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

    /**
     * Several choices: a list (form), JSON of a list, or text with the choices separated by ";" or line breaks (spreadsheet).
     * Text that is exactly one choice is that choice even if it contains a comma; otherwise commas separate too.
     * Stored as a JSON list in the order of the field's choices, nothing chosen = no value.
     *
     * @throws ValidationException
     */
    private function normaliseChoices(CustomField $field, mixed $value): ?string {
        $byKey = [];
        foreach ($field->getOptionList() as $option) {
            $byKey[mb_strtolower($option)] = $option;
        }
        if (is_string($value)) {
            $text = trim($value);
            $decoded = str_starts_with($text, '[') ? json_decode($text, true) : null;
            if (is_array($decoded)) {
                $value = $decoded;
            } elseif ($text === '' || isset($byKey[mb_strtolower($text)])) {
                $value = [$text];
            } else {
                $parts = preg_split('/[;\r\n]+/', $text) ?: [];
                if (count($parts) === 1) {
                    $parts = explode(',', $text);
                }
                $value = $parts;
            }
        } elseif (is_scalar($value)) {
            $value = [(string)$value];
        } elseif ($value === null) {
            $value = [];
        }
        if (!is_array($value)) {
            throw new ValidationException($this->l->t('%s is invalid', [$field->getLabel()]));
        }
        $chosen = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                throw new ValidationException($this->l->t('%s is invalid', [$field->getLabel()]));
            }
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            $option = $byKey[mb_strtolower($item)] ?? null;
            if ($option === null) {
                throw new ValidationException($this->l->t('%1$s: “%2$s” is not one of the choices', [$field->getLabel(), $item]));
            }
            $chosen[$option] = true;
        }
        if ($chosen === []) {
            return null;
        }
        // the field's order, so that the same choices are always the same value
        $ordered = array_values(array_filter($field->getOptionList(), fn (string $o) => isset($chosen[$o])));
        return json_encode($ordered, JSON_UNESCAPED_UNICODE);
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
        if (in_array($type, CustomField::CHOICE_TYPES, true)) {
            $options = $this->options($data['options'] ?? ($field->getOptionList()));
            if ($options === []) {
                throw new ValidationException($this->l->t('A selection needs at least one choice'));
            }
        }
        $field->setLabel($label);
        $field->setType($type);
        $field->setOptions(in_array($type, CustomField::CHOICE_TYPES, true) ? json_encode($options, JSON_UNESCAPED_UNICODE) : null);
        $field->setRequired($this->bool($data['required'] ?? $field->getRequired() ?? false));
        $field->setShowInList($this->bool($data['showInList'] ?? $field->getShowInList() ?? false));
        if (array_key_exists('sortOrder', $data) && is_numeric($data['sortOrder'])) {
            $field->setSortOrder((int)$data['sortOrder']);
        }
        if (array_key_exists('sectionId', $data)) {
            $sectionId = is_numeric($data['sectionId']) && (int)$data['sectionId'] > 0 ? (int)$data['sectionId'] : null;
            if ($sectionId !== null) {
                // only a section of the same club
                try {
                    $this->ownSection($field->getClubId(), $sectionId);
                } catch (NotFoundException $e) {
                    throw new ValidationException($this->l->t('The section does not exist'));
                }
            }
            $field->setSectionId($sectionId);
        }
    }

    /**
     * @param CustomSection[] $existing the club's sections
     * @throws ValidationException
     */
    private function applySection(CustomSection $section, array $data, array $existing): void {
        $name = trim(is_scalar($data['name'] ?? null) ? (string)$data['name'] : $section->getName());
        if ($name === '') {
            throw new ValidationException($this->l->t('The name of the section is required'));
        }
        if (mb_strlen($name) > self::MAX_LABEL) {
            throw new ValidationException($this->l->t('The name of the section is too long'));
        }
        foreach ($existing as $other) {
            if ($other->getId() !== $section->getId() && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new ValidationException($this->l->t('A section with this name already exists'));
            }
        }
        $section->setName($name);
        if (array_key_exists('viewRoles', $data)) {
            $section->setViewRoles(json_encode($this->roleIds($data['viewRoles'])));
        }
        if (array_key_exists('editRoles', $data)) {
            $section->setEditRoles(json_encode($this->roleIds($data['editRoles'])));
        }
        if (array_key_exists('sortOrder', $data) && is_numeric($data['sortOrder'])) {
            $section->setSortOrder((int)$data['sortOrder']);
        }
        if (array_key_exists('memberVisible', $data) && $data['memberVisible'] !== null) {
            $section->setMemberVisible(filter_var($data['memberVisible'], FILTER_VALIDATE_BOOLEAN));
        } elseif ($section->getId() === null) {
            $section->setMemberVisible(true);
        }
    }

    /**
     * Role ids from a list, JSON of a list or comma-separated text; unknown roles are refused.
     *
     * @return int[]
     * @throws ValidationException
     */
    private function roleIds(mixed $raw): array {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : ($raw === '' ? [] : explode(',', $raw));
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!is_numeric($id) || (int)$id <= 0) {
                throw new ValidationException($this->l->t('Unknown role'));
            }
            $ids[(int)$id] = (int)$id;
        }
        foreach ($ids as $id) {
            try {
                $this->roleDefinitions?->find($id);
            } catch (DoesNotExistException $e) {
                throw new ValidationException($this->l->t('Unknown role'));
            }
        }
        return array_values($ids);
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
    private function ownSection(int $clubId, int $id): CustomSection {
        if ($this->sections === null) {
            throw new NotFoundException($this->l->t('Section not found'));
        }
        try {
            $section = $this->sections->find($id);
        } catch (DoesNotExistException $e) {
            throw new NotFoundException($this->l->t('Section not found'));
        }
        if ($section->getClubId() !== $clubId) {
            throw new NotFoundException($this->l->t('Section not found'));
        }
        return $section;
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
