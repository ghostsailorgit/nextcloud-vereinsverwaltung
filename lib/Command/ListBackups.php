<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Command;

use OCA\Verein\Service\BackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ verein:backup:list
 */
class ListBackups extends Command {
    public function __construct(private BackupService $backups) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('verein:backup:list')
            ->setDescription('Lists the existing backups of the club data (newest first)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $list = $this->backups->listBackups();
        if ($list === []) {
            $output->writeln('No backups yet.');
            return Command::SUCCESS;
        }
        foreach ($list as $b) {
            $output->writeln(sprintf('%s  %s  %d KB', $b['name'], gmdate('Y-m-d H:i:s', $b['created']) . ' UTC', max(1, (int)round($b['size'] / 1024))));
        }
        return Command::SUCCESS;
    }
}
