<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\CustomField;
use OCA\Verein\Db\CustomFieldMapper;
use OCA\Verein\Db\CustomSection;
use OCA\Verein\Db\CustomSectionMapper;
use OCA\Verein\Db\CustomValue;
use OCA\Verein\Db\CustomValueMapper;
use OCA\Verein\Db\Role;
use OCA\Verein\Db\RoleMapper;
use OCA\Verein\Exception\NotFoundException;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\AuditLogService;
use OCA\Verein\Service\CustomFieldService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class CustomFieldServiceTest extends TestCase {
    private const CLUB = 1;

    /** @var array<int, CustomField> */
    private array $fieldStore = [];
    /** @var array<int, CustomSection> */
    private array $sectionStore = [];
    /** @var CustomValue[] */
    private array $valueStore = [];
    private int $nextId = 1;
    private array $logged = [];
    private CustomFieldService $service;

    protected function setUp(): void {
        $fields = $this->createMock(CustomFieldMapper::class);
        $fields->method('findByClub')->willReturnCallback(fn (int $c) => array_values(array_filter($this->fieldStore, fn ($f) => $f->getClubId() === $c)));
        $fields->method('find')->willReturnCallback(fn (int $id) => $this->fieldStore[$id] ?? throw new DoesNotExistException('none'));
        $fields->method('insert')->willReturnCallback(function (CustomField $f) {
            $f->setId($this->nextId++);
            $this->fieldStore[$f->getId()] = $f;
            return $f;
        });
        $fields->method('update')->willReturnArgument(0);

        $values = $this->createMock(CustomValueMapper::class);
        $values->method('findByMember')->willReturnCallback(fn (int $c, int $m) => array_values(array_filter($this->valueStore, fn ($v) => $v->getClubId() === $c && $v->getMemberId() === $m)));
        $values->method('insert')->willReturnCallback(function (CustomValue $v) {
            $this->valueStore[] = $v;
            return $v;
        });
        $values->method('update')->willReturnArgument(0);
        $values->method('delete')->willReturnCallback(function (CustomValue $v) {
            $this->valueStore = array_values(array_filter($this->valueStore, fn ($o) => $o !== $v));
            return $v;
        });

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('record')->willReturnCallback(function (...$args) {
            $this->logged[] = $args;
        });
        $audit->method('diff')->willReturn(['x' => 1]);

        $values->method('findByClub')->willReturnCallback(fn (int $c) => array_values(array_filter($this->valueStore, fn ($v) => $v->getClubId() === $c)));

        $sections = $this->createMock(CustomSectionMapper::class);
        $sections->method('findByClub')->willReturnCallback(fn (int $c) => array_values(array_filter($this->sectionStore, fn ($s) => $s->getClubId() === $c)));
        $sections->method('find')->willReturnCallback(fn (int $id) => $this->sectionStore[$id] ?? throw new DoesNotExistException('none'));
        $sections->method('insert')->willReturnCallback(function (CustomSection $s) {
            $s->setId($this->nextId++);
            $this->sectionStore[$s->getId()] = $s;
            return $s;
        });
        $sections->method('update')->willReturnArgument(0);
        $sections->method('delete')->willReturnCallback(function (CustomSection $s) {
            unset($this->sectionStore[$s->getId()]);
            return $s;
        });

        // roles 10, 11 and 12 exist
        $roles = $this->createMock(RoleMapper::class);
        $roles->method('find')->willReturnCallback(fn (int $id) => in_array($id, [10, 11, 12], true) ? new Role() : throw new DoesNotExistException('none'));

        $this->service = new CustomFieldService($fields, $values, $this->createMock(ClubMapper::class), $audit, $sections, $roles, SourceL10n::fromAppLanguage('de'));
    }

    private function field(string $label, string $type, array $extra = []): CustomField {
        return $this->service->create(self::CLUB, ['label' => $label, 'type' => $type] + $extra);
    }

    public function testFieldsAreCheckedWhenDefined(): void {
        $this->field('Lizenz', 'select', ['options' => "C-Lizenz\nB-Lizenz\n\nc-lizenz"]);
        $this->assertSame(['C-Lizenz', 'B-Lizenz'], $this->fieldStore[1]->getOptionList(), 'empty lines and repeats are dropped');

        foreach ([
            [['label' => ' ', 'type' => 'text'], 'Name des Feldes fehlt'],
            [['label' => 'lizenz', 'type' => 'text'], 'gibt es schon'],
            [['label' => 'Farbe', 'type' => 'colour'], 'Art des Feldes ist ungültig'],
            [['label' => 'Leer', 'type' => 'select', 'options' => "\n"], 'mindestens eine Möglichkeit'],
        ] as [$data, $message]) {
            try {
                $this->service->create(self::CLUB, $data);
                $this->fail('accepted: ' . json_encode($data));
            } catch (ValidationException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testValuesAreNormalisedByType(): void {
        $text = $this->field('Passnummer', 'text');
        $number = $this->field('Gewicht', 'number');
        $date = $this->field('Prüfung', 'date');
        $select = $this->field('Lizenz', 'select', ['options' => ['C-Lizenz', 'B-Lizenz']]);
        $check = $this->field('Trainer', 'checkbox');

        $values = $this->service->validateValues(self::CLUB, json_encode([
            $text->getId() => '  P-123  ',
            $number->getId() => '72,5',
            $date->getId() => '1.3.2026',
            $select->getId() => 'b-lizenz',
            $check->getId() => 'ja',
        ]), false);

        $this->assertSame([$text->getId() => 'P-123', $number->getId() => '72.5', $date->getId() => '2026-03-01',
            $select->getId() => 'B-Lizenz', $check->getId() => '1'], $values);
    }

    public function testWrongValuesAreAllNamed(): void {
        $number = $this->field('Gewicht', 'number');
        $date = $this->field('Prüfung', 'date');
        $select = $this->field('Lizenz', 'select', ['options' => ['C-Lizenz']]);

        try {
            $this->service->validateValues(self::CLUB, [$number->getId() => 'viel', $date->getId() => '31.02.2026', $select->getId() => 'A-Lizenz'], false);
            $this->fail('accepted');
        } catch (ValidationException $e) {
            foreach (['Gewicht muss eine Zahl sein', 'Prüfung muss ein Datum sein', '„A-Lizenz“ ist keine der Auswahlmöglichkeiten'] as $part) {
                $this->assertStringContainsString($part, $e->getMessage());
            }
        }
    }

    public function testRequiredFieldsAndFieldsNotSent(): void {
        $pass = $this->field('Passnummer', 'text', ['required' => '1']);
        $note = $this->field('Notiz', 'text');

        $this->assertSame([], $this->service->validateValues(self::CLUB, [], false), 'not sent while editing = unchanged');
        $this->assertSame([$note->getId() => null], $this->service->validateValues(self::CLUB, [$note->getId() => ''], false));

        $this->expectExceptionMessage('Passnummer ist ein Pflichtfeld');
        $this->service->validateValues(self::CLUB, [$note->getId() => 'x'], true);
    }

    public function testSavingWritesChangesOnlyAndLogsNoValues(): void {
        $pass = $this->field('Passnummer', 'text');
        $this->logged = [];

        $this->service->saveValues(self::CLUB, 7, [$pass->getId() => 'P-1']);
        $this->service->saveValues(self::CLUB, 7, [$pass->getId() => 'P-1']);
        $this->assertSame([$pass->getId() => 'P-1'], $this->service->valuesFor(self::CLUB, 7));
        $this->assertCount(1, $this->logged, 'an unchanged value is not logged again');
        $this->assertStringNotContainsString('P-1', json_encode($this->logged), 'the audit log never holds the value');

        $this->service->saveValues(self::CLUB, 7, [$pass->getId() => null]);
        $this->assertSame([], $this->service->valuesFor(self::CLUB, 7));
    }

    public function testAFieldOfAnotherClubIsNotFound(): void {
        $field = $this->field('Passnummer', 'text');

        $this->expectException(NotFoundException::class);
        $this->service->delete(2, $field->getId());
    }

    public function testSectionsDecideWhoSeesAndChangesAField(): void {
        $open = $this->field('Instrument', 'text');
        $bank = $this->service->createSection(self::CLUB, ['name' => 'Bankdaten', 'viewRoles' => '[10]', 'editRoles' => '[11]']);
        $police = $this->service->createSection(self::CLUB, ['name' => 'Führungszeugnis', 'viewRoles' => [], 'editRoles' => []]);
        $account = $this->field('Zweitkonto', 'text', ['sectionId' => (string)$bank->getId()]);
        $certificate = $this->field('Vorgelegt am', 'date', ['sectionId' => $police->getId(), 'required' => '1']);

        $this->assertSame(['visible' => null, 'editable' => null], $this->service->access(self::CLUB, null), 'club managers see everything');
        $this->assertSame(['visible' => [$open->getId()], 'editable' => [$open->getId()]], $this->service->access(self::CLUB, []),
            'no role: only fields without a section');
        $this->assertSame(['visible' => [$open->getId(), $account->getId()], 'editable' => [$open->getId()]], $this->service->access(self::CLUB, [10]),
            'a view role shows the section but does not open it for changes');
        $this->assertSame(['visible' => [$open->getId(), $account->getId()], 'editable' => [$open->getId(), $account->getId()]], $this->service->access(self::CLUB, [11, 12]),
            'an edit role includes seeing');

        $this->service->saveValues(self::CLUB, 7, [$open->getId() => 'Flöte', $account->getId() => 'DE00', $certificate->getId() => '2026-01-01']);
        $visible = $this->service->access(self::CLUB, [10])['visible'];
        $this->assertSame([$open->getId() => 'Flöte', $account->getId() => 'DE00'], $this->service->valuesFor(self::CLUB, 7, $visible));
        $this->assertSame([7 => [$open->getId() => 'Flöte', $account->getId() => 'DE00']], $this->service->valuesForClub(self::CLUB, $visible));
        $this->assertSame(['Instrument', 'Zweitkonto'], array_column($this->service->labelledValuesFor(self::CLUB, 7, $visible), 'label'));
        $this->assertSame('Bankdaten', $this->service->labelledValuesFor(self::CLUB, 7)[1]['section']);
        $this->assertCount(3, $this->service->labelledValuesFor(self::CLUB, 7), 'the person themselves sees all of their values');
        $this->assertSame([$open->getId(), $account->getId()], array_map(fn ($f) => $f->getId(), $this->service->fields(self::CLUB, $visible)));
    }

    public function testValuesOfFieldsSomeoneMayNotChangeAreIgnored(): void {
        $open = $this->field('Instrument', 'text');
        $police = $this->service->createSection(self::CLUB, ['name' => 'Führungszeugnis']);
        $certificate = $this->field('Vorgelegt am', 'date', ['sectionId' => $police->getId(), 'required' => '1']);
        $editable = $this->service->access(self::CLUB, [10])['editable'];

        // neither overwritten nor emptied, and its being required does not stop creating a member
        $this->assertSame([$open->getId() => 'Flöte'], $this->service->validateValues(self::CLUB, [$open->getId() => 'Flöte', $certificate->getId() => ''], true, $editable));
        $this->assertSame([], $this->service->validateValues(self::CLUB, [$certificate->getId() => '2026-01-01'], false, $editable));
        // with every right the field is required as before
        $this->expectExceptionMessage('Vorgelegt am ist ein Pflichtfeld');
        $this->service->validateValues(self::CLUB, [$open->getId() => 'Flöte'], true);
    }

    public function testSectionsAreChecked(): void {
        $this->service->createSection(self::CLUB, ['name' => 'Bankdaten']);
        foreach ([
            [['name' => ' '], 'Name des Bereichs fehlt'],
            [['name' => 'bankdaten'], 'Bereich mit diesem Namen gibt es schon'],
            [['name' => 'Intern', 'viewRoles' => '[99]'], 'Unbekannte Rolle'],
            [['name' => 'Intern', 'editRoles' => 'x'], 'Unbekannte Rolle'],
        ] as [$data, $message]) {
            try {
                $this->service->createSection(self::CLUB, $data);
                $this->fail('accepted: ' . json_encode($data));
            } catch (ValidationException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $other = new CustomSection();
        $other->setClubId(2);
        $other->setName('Fremd');
        $other->setId(500);
        $this->sectionStore[500] = $other;
        try {
            $this->field('Konto', 'text', ['sectionId' => 500]);
            $this->fail('a section of another club was accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Bereich gibt es nicht', $e->getMessage());
        }
        $this->expectException(NotFoundException::class);
        $this->service->updateSection(self::CLUB, 500, ['name' => 'Meins']);
    }

    public function testOnlyAnEmptySectionCanBeDeleted(): void {
        $section = $this->service->createSection(self::CLUB, ['name' => 'Intern', 'editRoles' => '10,11']);
        $this->assertSame([10, 11], $section->getEditRoleIds());
        $field = $this->field('Notiz', 'text', ['sectionId' => $section->getId()]);
        try {
            $this->service->deleteSection(self::CLUB, $section->getId());
            $this->fail('deleted a section with fields');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('noch Felder', $e->getMessage());
        }
        $this->service->update(self::CLUB, $field->getId(), ['label' => 'Notiz', 'sectionId' => '']);
        $this->assertNull($field->getSectionId());
        $this->service->deleteSection(self::CLUB, $section->getId());
        $this->assertSame([], $this->service->sections(self::CLUB));
    }

    public function testAFieldWhoseSectionIsGoneStaysHidden(): void {
        $field = $this->field('Notiz', 'text');
        $field->setSectionId(999);
        $this->assertSame(['visible' => [], 'editable' => []], $this->service->access(self::CLUB, [10]));
    }
}
