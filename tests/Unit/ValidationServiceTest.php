<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\ValidationService;
use PHPUnit\Framework\TestCase;

class ValidationServiceTest extends TestCase {
    private ValidationService $service;

    protected function setUp(): void {
        $this->service = new ValidationService(l10n: SourceL10n::fromAppLanguage('de'));
    }

    public function testEmailIsOptional(): void {
        $result = $this->service->validateMember(['name' => 'Muster', 'email' => '']);
        $this->assertTrue($result['valid'], implode(', ', $result['errors']));

        $result = $this->service->validateMember(['name' => 'Muster']);
        $this->assertTrue($result['valid']);
    }

    public function testGivenEmailMustBeValid(): void {
        $result = $this->service->validateMember(['name' => 'Muster', 'email' => 'kein-mail']);
        $this->assertFalse($result['valid']);
        $this->assertContains('E-Mail ist ungültig', $result['errors']);
    }

    public function testNameIsStillRequired(): void {
        $result = $this->service->validateMember(['name' => ' ', 'email' => '']);
        $this->assertFalse($result['valid']);
    }

    public function testMandateDateAndReferenceAreChecked(): void {
        $bad = $this->service->validateMember(['name' => 'Muster', 'mandateDate' => 'kein-datum']);
        $this->assertFalse($bad['valid']);

        $tooLong = $this->service->validateMember(['name' => 'Muster', 'mandateReference' => str_repeat('A', 36)]);
        $this->assertFalse($tooLong['valid']);

        $ok = $this->service->validateMember(['name' => 'Muster', 'mandateDate' => '2021-01-05', 'mandateReference' => 'MAND-2021/01']);
        $this->assertTrue($ok['valid'], implode(', ', $ok['errors']));
    }

    public function testCreditorIdFormat(): void {
        $this->assertTrue($this->service->validateCreditorId('DE98ZZZ09999999999'));
        $this->assertTrue($this->service->validateCreditorId('DE98 ZZZ0 9999 9999 99'));
        $this->assertFalse($this->service->validateCreditorId('12345'));
        $this->assertFalse($this->service->validateCreditorId(''));
    }

    public function testIbanValidationIsPublicAndChecksTheChecksum(): void {
        $this->assertTrue($this->service->validateIBAN('DE89 3704 0044 0532 0130 00'));
        $this->assertFalse($this->service->validateIBAN('DE89370400440532013001'));
    }
}
