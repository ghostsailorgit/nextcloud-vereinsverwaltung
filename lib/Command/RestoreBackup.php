<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Command;

use OCA\Verein\Service\BackupService;
use OCP\Files\NotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * occ verein:backup:restore <name|file>
 *
 * Replaces all club data with the content of a backup. Deliberately not available in the
 * web interface: it overwrites everything and is a task for the server administrator.
 */
class RestoreBackup extends Command {
    public function __construct(private BackupService $backups) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('verein:backup:restore')
            ->setDescription('Stellt die Vereinsdaten aus einer Sicherung wieder her (ersetzt alle aktuellen Daten)')
            ->addArgument('backup', InputArgument::REQUIRED, 'Dateiname einer Sicherung (siehe verein:backup:list) oder Pfad zu einer heruntergeladenen Sicherung')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Ohne Rückfrage ausführen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $raw = $this->backups->readBackup((string)$input->getArgument('backup'));
            $backup = BackupService::parse($raw);
        } catch (NotFoundException | \InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $current = $this->backups->currentCounts();
        $output->writeln('Sicherung vom ' . $backup['created'] . ' (App-Version ' . $backup['appVersion'] . ')');
        $output->writeln(sprintf('%-24s %10s %10s', 'Tabelle', 'jetzt', 'Sicherung'));
        foreach (BackupService::TABLES as $table) {
            $inBackup = isset($backup['tables'][$table]) ? (string)count($backup['tables'][$table]) : '- (bleibt)';
            $output->writeln(sprintf('%-24s %10d %10s', $table, $current[$table], $inBackup));
        }
        $output->writeln('');
        $output->writeln('Alle aktuellen Vereinsdaten werden ersetzt. Vorher wird automatisch eine Sicherung des aktuellen Stands angelegt.');

        if (!$input->getOption('yes')) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            if (!$helper->ask($input, $output, new ConfirmationQuestion('Wirklich wiederherstellen? [j/N] ', false, '/^(j|y)/i'))) {
                $output->writeln('Abgebrochen, nichts wurde geändert.');
                return Command::FAILURE;
            }
        }

        try {
            $result = $this->backups->restore($backup);
        } catch (\Throwable $e) {
            $output->writeln('<error>Wiederherstellung fehlgeschlagen, es wurde nichts geändert: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Wiederhergestellt.</info> Sicherung des vorherigen Stands: ' . $result['safetyBackup']);
        return Command::SUCCESS;
    }
}
