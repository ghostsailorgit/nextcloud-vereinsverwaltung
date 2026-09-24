<?php
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Controller\RespondsWithErrors;
use OCA\Verein\Exception\DependencyMissingException;
use OCA\Verein\Exception\NotFoundException;
use OCA\Verein\Exception\PermissionDeniedException;
use OCA\Verein\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class RespondsWithErrorsTest extends TestCase {
    private function respond(\Throwable $e): array {
        $controller = new class {
            use RespondsWithErrors;

            public function run(\Throwable $e) {
                return $this->errorResponse($e);
            }
        };
        $response = $controller->run($e);
        return [$response->getStatus(), $response->getData()];
    }

    public function testUserFacingExceptionsKeepTheirMessageAndStatus(): void {
        $this->assertSame([400, ['status' => 'error', 'message' => 'Name fehlt']], $this->respond(new ValidationException('Name fehlt')));
        $this->assertSame([403, ['status' => 'error', 'message' => 'Nicht erlaubt']], $this->respond(new PermissionDeniedException('Nicht erlaubt')));
        $this->assertSame([404, ['status' => 'error', 'message' => 'Mitglied nicht gefunden']], $this->respond(new NotFoundException('Mitglied nicht gefunden')));
        $this->assertSame([503, ['status' => 'error', 'message' => 'PDF-Bibliothek fehlt']], $this->respond(new DependencyMissingException('PDF-Bibliothek fehlt')));
    }

    public function testAMissingRowIsANotFoundWithoutTheFrameworksSqlText(): void {
        [$status, $body] = $this->respond(new DoesNotExistException('Did expect one result but found none when executing query: SELECT * FROM oc_verein_members'));

        $this->assertSame(404, $status);
        $this->assertSame('Nicht gefunden', $body['message']);
    }

    public function testUnexpectedErrorsAreAGenericServerErrorThatLeaksNothing(): void {
        [$status, $body] = $this->respond(new \RuntimeException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x' in 'field list'"));

        $this->assertSame(500, $status);
        $this->assertStringNotContainsString('SQLSTATE', $body['message']);
        $this->assertStringNotContainsString('Unknown column', $body['message']);
    }

    /**
     * The exception text of an unexpected error must never reach the browser: controllers hand the
     * exception to errorResponse() instead of building a response from getMessage() themselves.
     */
    public function testNoControllerBuildsAResponseFromAnExceptionMessage(): void {
        $offenders = [];
        foreach (glob(__DIR__ . '/../../lib/Controller/*.php') as $file) {
            if (basename($file) === 'RespondsWithErrors.php') {
                continue;
            }
            if (preg_match('/->getMessage\(\)/', file_get_contents($file))) {
                $offenders[] = basename($file);
            }
        }
        $this->assertSame([], $offenders, 'use $this->errorResponse($e) instead of $e->getMessage() in a response');
    }
}
