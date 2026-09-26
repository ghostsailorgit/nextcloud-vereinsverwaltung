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
            ->setDescription('Restores the club data from a backup (replaces all current data)')
            ->addArgument('backup', InputArgument::REQUIRED, 'File name of a backup (see verein:backup:list) or path to a downloaded backup')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Run without asking');
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
        $output->writeln('Backup of ' . $backup['created'] . ' (app version ' . $backup['appVersion'] . ')');
        $output->writeln(sprintf('%-24s %10s %10s', 'Tabelle', 'jetzt', 'Sicherung'));
        foreach (BackupService::TABLES as $table) {
            $inBackup = isset($backup['tables'][$table]) ? (string)count($backup['tables'][$table]) : '- (bleibt)';
            $output->writeln(sprintf('%-24s %10d %10s', $table, $current[$table], $inBackup));
        }
        $output->writeln('');
        $output->writeln('All current club data will be replaced. A backup of the current state is created automatically first.');

        if (!$input->getOption('yes')) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            if (!$helper->ask($input, $output, new ConfirmationQuestion('Really restore? [y/N] ', false, '/^(j|y)/i'))) {
                $output->writeln('Aborted, nothing was changed.');
                return Command::FAILURE;
            }
        }

        try {
            $result = $this->backups->restore($backup);
        } catch (\Throwable $e) {
            $output->writeln('<error>Restore failed, nothing was changed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Restored.</info> Backup of the previous state: ' . $result['safetyBackup']);
        return Command::SUCCESS;
    }
}
