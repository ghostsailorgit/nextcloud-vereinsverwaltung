<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Integration smoke test inside a real Nextcloud (used by the CI on several Nextcloud versions and databases).
 * Runs the app's services against the real database, calendar and app data - things the unit tests mock.
 *
 *   php smoke.php run     creates clubs, members (incl. CSV import), fee run, dunning + letters, audit log paging,
 *                         a role for the non-admin CI user, anonymize, and a backup; writes smoke-state.json
 *   php smoke.php mutate  deletes a member (to see the restore bring it back)
 *   php smoke.php verify  after `occ verein:backup:restore <backup> --yes`: counts must equal the backup's
 *
 * Exits non-zero on the first failed check. Only made-up data ("CI ..."), in a throw-away CI instance.
 */

declare(strict_types=1);

use OCA\Verein\Db\AuditLogMapper;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Service\BackupService;
use OCA\Verein\Service\ClubService;
use OCA\Verein\Service\DunningService;
use OCA\Verein\Service\Export\PdfExporter;
use OCA\Verein\Service\FeeRateService;
use OCA\Verein\Service\FeeRunService;
use OCA\Verein\Service\FeeService;
use OCA\Verein\Service\MemberImportService;
use OCA\Verein\Service\MemberService;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\Server;

require dirname(__DIR__, 4) . '/lib/base.php';

$stateFile = __DIR__ . '/smoke-state.json';
$mode = $argv[1] ?? 'run';

function check(bool $ok, string $what): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $what\n");
        exit(1);
    }
    echo "ok   $what\n";
}

/** @template T @param class-string<T> $c @return T */
function svc(string $c) {
    return Server::get($c);
}

if ($mode === 'run') {
    $clubs = svc(ClubService::class);
    $club = $clubs->create(['name' => 'CI Verein', 'street' => 'Vereinsweg 1', 'postalCode' => '12345', 'city' => 'Musterstadt']);
    $other = $clubs->create(['name' => 'CI Anderer Verein']);
    $clubId = $club->getId();
    check($clubId > 0 && $other->getId() !== $clubId, 'two clubs created');
    $clubs->createAccount($clubId, ['label' => 'Konto', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'creditorId' => 'DE98ZZZ09999999999', 'isDefault' => true]);

    $rates = svc(FeeRateService::class);
    $adult = $rates->create($clubId, ['name' => 'Erwachsene', 'amount' => 24, 'isDefault' => true]);
    $rates->create($clubId, ['name' => 'Jugend', 'amount' => 12, 'isDefault' => false]);
    $rates->create($clubId, ['name' => 'Ehrenmitglied', 'amount' => 0, 'isDefault' => false]);
    check(count($rates->findByClub($clubId)) === 3, 'three fee categories incl. a fee-free one (amount 0 must insert)');

    // CSV import: UTF-8 umlauts, German dates, IBAN with blanks, a bad IBAN and a duplicate line
    $csv = "Anrede;Vorname;Nachname;Straße;PLZ;Ort;IBAN;Geburtsdatum;Eintritt;Beitragskategorie\n"
        . "Frau;Jördis;CI Müller;Musterweg 1;12345;Musterstadt;DE89 3704 0044 0532 0130 00;01.02.1980;01.03.2015;\n"
        . "Herr;Kai;CI Jung;Musterweg 2;12345;Musterstadt;;02.03.2008;01.10.2025;Jugend\n"
        . "Herr;Ehren;CI Mitglied;Musterweg 3;12345;Musterstadt;;03.04.1950;01.01.2000;Ehrenmitglied\n"
        . "Herr;Falsch;CI Iban;;;;DE00 1234;;;\n"
        . "Frau;Jördis;CI Müller;;;;;;;\n";
    $import = svc(MemberImportService::class);
    $plan = $import->plan($clubId, $csv, true);
    check($plan['counts'] === ['ok' => 3, 'error' => 1, 'duplicate' => 1], 'import preview: 3 ok, 1 error, 1 duplicate (got ' . json_encode($plan['counts']) . ')');
    $result = $import->import($clubId, $csv, true, [2, 3, 4, 5, 6]);
    check(count($result['created']) === 3 && count($result['skipped']) === 2, 'import created 3, skipped 2');

    $members = svc(MemberService::class);
    $late = $members->create($clubId, ['name' => 'CI Spät', 'firstName' => 'Lea', 'joinDate' => '2025-10-15', 'birthDate' => '1990-05-05', 'street' => 'Musterweg 4', 'postalCode' => '12345', 'city' => 'Musterstadt']);
    $paused = $members->create($clubId, ['name' => 'CI Pause', 'firstName' => 'Paul', 'joinDate' => '2010-01-01']);
    $members->deactivate($clubId, $paused->getId());
    $former = $members->create($clubId, ['name' => 'CI Ehemalig', 'firstName' => 'Ella', 'joinDate' => '2000-01-01', 'leaveDate' => '2020-12-31', 'email' => 'ella@example.org']);
    $all = $members->findAll($clubId);
    check(count($all) === 6, 'six members in the club (got ' . count($all) . ')');
    $byName = [];
    foreach ($all as $m) {
        $byName[$m->getName()] = $m;
    }
    check($byName['CI Müller']->getFirstName() === 'Jördis' && $byName['CI Müller']->getIban() === 'DE89370400440532013000', 'umlauts and normalised IBAN stored');
    check($byName['CI Müller']->getBirthDate() !== null && str_starts_with((string)$byName['CI Müller']->getBirthDate(), '1980-02-01'), 'German date stored as ISO');

    // fee run 2025 with pro-rata: Kai and Lea joined in October 2025 -> 3/12
    $run = svc(FeeRunService::class)->run($clubId, 2025, '2025-03-31', null, true);
    $amounts = array_column($run['included'], 'amount', 'name');
    check($run['created'] === 3, 'fee run 2025 created 3 fees (Müller, Kai, Lea; honorary is fee-free) (got ' . $run['created'] . ': ' . json_encode($amounts) . ')');
    check(($amounts['Kai CI Jung'] ?? null) === 3.0 && ($amounts['Lea CI Spät'] ?? null) === 6.0, 'pro-rata amounts 3.00 and 6.00');
    $again = svc(FeeRunService::class)->run($clubId, 2025, '2025-03-31', null, true);
    check($again['created'] === 0, 'repeating the fee run creates nothing (and the audit entry with entity id 0 inserts)');

    $fees = svc(FeeService::class);
    check($fees->flagOverdue($clubId) === 3, 'three fees flagged overdue');
    $leaFee = array_values(array_filter($fees->findAll($clubId), fn ($f) => $f->getMemberId() === $late->getId()))[0];
    check($fees->markPaid($clubId, [$leaFee->getId()]) === 1, 'one fee marked paid');

    // dunning: Müller and Kai still owe (Lea paid, the deactivated person got no fee)
    $dunning = svc(DunningService::class);
    $d = $dunning->run($clubId, 14, 14);
    check($d['dunned'] === 2 && count($d['feeIds']) === 2, 'dunning run: 2 letters (got ' . $d['dunned'] . ')');
    check($dunning->plan($clubId, 14, 14)['included'] === [], 'a second run within the interval sends nothing');
    $levels = array_count_values(array_map(fn ($f) => $f->getDunningLevel(), svc(FeeMapper::class)->findByClub($clubId)));
    check(($levels[1] ?? 0) === 2, 'two fees at dunning level 1 in the database');
    $pdf = svc(PdfExporter::class)->exportDunningLetters($dunning->letters($clubId, $d['feeIds'], 14));
    check(str_starts_with($pdf['content'], '%PDF') && preg_match_all('/\/Type\s*\/Page[^s]/', $pdf['content']) === 2, 'dunning letters: a 2-page PDF (TCPDF installed)');

    // audit log paging over real rows
    $log = svc(AuditLogMapper::class);
    $page1 = $log->findByClub($clubId, null, null, 5);
    $page2 = $log->findByClub($clubId, null, null, 5, $page1[4]->getId());
    check(count($page1) === 5 && count($page2) > 0 && $page2[0]->getId() < $page1[4]->getId(), 'audit log pages backwards with beforeId');
    check($log->findByClub($clubId, 'fee_run') !== [], 'fee run audit entries exist');

    // anonymize a person who left
    $anon = $members->anonymize($former->getId());
    check($anon->getName() === 'Anonymisiert' && $anon->getEmail() === '' && $anon->getAnonymizedAt() !== null, 'former member anonymized');

    // a role for the non-admin CI user: finance only, only in this club
    $roles = svc(RoleService::class);
    $role = $roles->createRole('CI Kasse', 'custom', null, ['verein.finance.read', 'verein.finance.write']);
    $roles->assignRole('ci-user', $role->getId(), $clubId);

    $backup = svc(BackupService::class)->createBackup();
    check(str_starts_with($backup['name'], 'verein-backup-') || $backup['size'] > 0, 'backup written to app data: ' . $backup['name']);

    file_put_contents($stateFile, json_encode([
        'clubId' => $clubId,
        'otherClubId' => $other->getId(),
        'backup' => $backup['name'],
        'counts' => svc(BackupService::class)->currentCounts(),
        'deleteMember' => $byName['CI Jung']->getId(),
    ]));
    echo "state written\n";
    exit(0);
}

$state = json_decode((string)file_get_contents($stateFile), true);
if ($mode === 'mutate') {
    svc(MemberService::class)->remove($state['clubId'], $state['deleteMember']);
    $counts = svc(BackupService::class)->currentCounts();
    check($counts['verein_members'] === $state['counts']['verein_members'] - 1, 'a member was removed before the restore');
    exit(0);
}
if ($mode === 'verify') {
    $counts = svc(BackupService::class)->currentCounts();
    foreach ($state['counts'] as $table => $n) {
        check(($counts[$table] ?? -1) === $n, "after restore $table has $n rows (got " . ($counts[$table] ?? 'none') . ')');
    }
    exit(0);
}
fwrite(STDERR, "unknown mode $mode\n");
exit(2);
