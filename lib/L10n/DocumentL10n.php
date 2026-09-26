<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\L10n;

use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * The language of everything that leaves the app as a document - dunning letters, PDF exports, the SEPA file and
 * default texts that get stored (a fee's description).
 *
 * If the administrator configured an instance language (`default_language` or `force_language` in config.php), that
 * one: a club's letters then read the same no matter which board member prints them. Otherwise the language of the
 * person creating the document - Nextcloud itself would fall back to English there, which would turn the letters of
 * a German club English just because nobody set a default.
 */
class DocumentL10n {
    public function __construct(
        private IFactory $factory,
        private IConfig $config,
        private IL10N $userL10n,
    ) {
    }

    public function get(): IL10N {
        $configured = $this->config->getSystemValue('force_language', false) !== false
            || $this->config->getSystemValue('default_language', false) !== false;
        return $configured ? $this->factory->get('verein', $this->factory->findGenericLanguage('verein')) : $this->userL10n;
    }
}
