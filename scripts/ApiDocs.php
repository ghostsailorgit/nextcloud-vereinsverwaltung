<?php
/**
 * Generates docs/API.md from appinfo/routes.php and the #[RequirePermission] attributes of the
 * controllers, so the documentation cannot drift from the code.
 *
 *   php scripts/ApiDocs.php            # rewrites docs/API.md
 *
 * tests/Unit/ApiDocsTest fails when docs/API.md is out of date.
 */

use OCA\Verein\Attributes\RequirePermission;

function generateApiDocs(string $root): string {
    $routes = (require $root . '/appinfo/routes.php')['routes'];

    $groups = [];
    foreach ($routes as $route) {
        [$controller, $method] = explode('#', $route['name']);
        $class = 'OCA\\Verein\\Controller\\' . ucfirst($controller) . 'Controller';
        $reflection = new ReflectionMethod($class, $method);

        $attributes = $reflection->getAttributes(RequirePermission::class);
        $doc = (string)$reflection->getDocComment();
        if ($attributes !== []) {
            $requirement = $attributes[0]->newInstance();
            $access = '`' . $requirement->getPermission() . '`' . ($requirement->isClubScoped() ? '' : ' (übergreifend)');
        } elseif (!preg_match('/^\s*\*\s*@NoAdminRequired\b/m', $doc)) {
            $access = 'Nextcloud-Administrator';
        } else {
            $access = 'angemeldet (nur eigene Daten)';
        }

        $groups[$controller][] = [
            'verb' => strtoupper($route['verb'] ?? 'GET'),
            'url' => $route['url'],
            'access' => $access,
            'name' => $route['name'],
        ];
    }

    $titles = [
        'club' => 'Vereine, Bankkonten, Beitragskategorien',
        'member' => 'Mitglieder',
        'memberImport' => 'Mitgliederimport (CSV)',
        'finance' => 'Beiträge (Finanzen)',
        'feeRun' => 'Beitragslauf',
        'dunning' => 'Mahnwesen',
        'sepa' => 'SEPA-Lastschrift',
        'export' => 'Export (CSV/PDF)',
        'statistics' => 'Statistik',
        'role' => 'Rollen und Rechte',
        'permission' => 'Berechtigungsliste',
        'me' => 'Meine Daten (Selbstauskunft)',
        'auditLog' => 'Änderungsprotokoll',
        'backup' => 'Sicherungen',
        'page' => 'Oberfläche',
    ];

    $out = "# API-Übersicht\n\n"
        . "*Diese Datei wird aus `appinfo/routes.php` und den `#[RequirePermission]`-Attributen erzeugt (`php scripts/ApiDocs.php`); "
        . "ein Test stellt sicher, dass sie aktuell ist. Nicht von Hand ändern.*\n\n"
        . "Alle Adressen liegen unter `/index.php/apps/verein`. Anfragen brauchen eine angemeldete Nextcloud-Sitzung "
        . "(oder Basic-Auth mit einem App-Passwort) und für schreibende Anfragen das CSRF-Token (`requesttoken`-Header). "
        . "Vereinsbezogene Endpunkte erwarten den Parameter `clubId`; das Recht wird für genau diesen Verein geprüft. "
        . "Fehler kommen als JSON `{\"status\":\"error\",\"message\":\"…\"}` mit dem passenden HTTP-Status "
        . "(400 ungültige Eingabe, 403 fehlendes Recht, 404 nicht gefunden, 503 fehlende Server-Voraussetzung, 500 unerwarteter Fehler).\n";

    foreach ($groups as $controller => $rows) {
        $out .= "\n## " . ($titles[$controller] ?? ucfirst($controller)) . "\n\n"
            . "| Methode | Pfad | Erforderliches Recht |\n|---|---|---|\n";
        foreach ($rows as $r) {
            $out .= '| ' . $r['verb'] . ' | `' . $r['url'] . '` | ' . $r['access'] . " |\n";
        }
    }

    return $out . "\n"
        . "Rechte: `verein.member.view/manage`, `verein.finance.read/write/delete/export`, `verein.sepa.export`, "
        . "`verein.role.manage`, `verein.club.manage`, `verein.audit.view`. Nextcloud-Administratoren haben alle Rechte in allen Vereinen. "
        . "Details zur Rechtevergabe: [ARCHITEKTUR.md](ARCHITEKTUR.md#rechte).\n";
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    require $root . '/vendor/autoload.php';
    file_put_contents($root . '/docs/API.md', generateApiDocs($root));
    echo "docs/API.md written\n";
}
