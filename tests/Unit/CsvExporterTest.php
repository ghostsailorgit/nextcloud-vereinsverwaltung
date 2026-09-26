<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\Export\CsvExporter;
use PHPUnit\Framework\TestCase;

class CsvExporterTest extends TestCase {
    private function escapeLine(array $fields): string {
        $m = new \ReflectionMethod(CsvExporter::class, 'escapeLine');
        return $m->invoke(new CsvExporter(l10n: SourceL10n::fromAppLanguage('de')), $fields);
    }

    public function testFormulaLikeTextIsDefused(): void {
        $line = $this->escapeLine(['=HYPERLINK("http://x")', '+1+1', '-cmd', '@SUM(A1)', 'Mustermann']);
        $this->assertSame("\"'=HYPERLINK(\"\"http://x\"\")\";'+1+1;'-cmd;'@SUM(A1);Mustermann\n", $line);
    }

    public function testPlainNumbersStayNumbers(): void {
        $this->assertSame("-12.50;24;DE89370400440532013000\n", $this->escapeLine(['-12.50', 24, 'DE89370400440532013000']));
    }
}
