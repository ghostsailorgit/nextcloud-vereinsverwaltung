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

    public function isChartsEnabled(): bool {
        return $this->config->getAppValue($this->appName, 'enable_charts', '0') === '1';
    }

    public function setChartsEnabled(bool $enabled): void {
        $this->config->setAppValue($this->appName, 'enable_charts', $enabled ? '1' : '0');
    }

    public function getDocumentsPath(): string {
        return $this->config->getAppValue($this->appName, 'documents_path', '/Verein');
    }

    public function setDocumentsPath(string $path): void {
        $path = '/' . trim($path, '/');
        $this->config->setAppValue($this->appName, 'documents_path', $path);
    }

    public function getAppSettings(): array {
        return [
            'enable_charts' => $this->isChartsEnabled(),
            'documents_path' => $this->getDocumentsPath(),
        ];
    }
}
