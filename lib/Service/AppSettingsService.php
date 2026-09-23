<?php
namespace OCA\Verein\Service;

use OCP\IConfig;

class AppSettingsService {
    private IConfig $config;
    private string $appName;

    public function __construct(IConfig $config, string $appName) {
        $this->config = $config;
        $this->appName = $appName;
    }

    /**
     * Not user-editable - App.vue's "Dokumente" tab just links here. Change
     * via `occ config:app:set verein documents_path --value=/Path` if the
     * folder is ever renamed.
     */
    public function getDocumentsPath(): string {
        return $this->config->getAppValue($this->appName, 'documents_path', '/Verein');
    }

    public function getAppSettings(): array {
        return [
            'documents_path' => $this->getDocumentsPath(),
        ];
    }
}
