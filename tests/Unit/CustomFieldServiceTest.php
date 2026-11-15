<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\CustomField;
use OCA\Verein\Db\CustomFieldMapper;
use OCA\Verein\Db\CustomValue;
use OCA\Verein\Db\CustomValueMapper;
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

        $this->service = new CustomFieldService($fields, $values, $this->createMock(ClubMapper::class), $audit, SourceL10n::fromAppLanguage('de'));
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
}
