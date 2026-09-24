<?php
namespace OCA\Verein\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/ApiDocs.php';

class ApiDocsTest extends TestCase {
    public function testApiDocumentationMatchesTheRoutes(): void {
        $root = dirname(__DIR__, 2);
        $committed = str_replace("\r\n", "\n", (string)file_get_contents($root . '/docs/API.md'));

        $this->assertSame(
            \generateApiDocs($root),
            $committed,
            'docs/API.md is out of date - run "php scripts/ApiDocs.php"'
        );
    }
}
