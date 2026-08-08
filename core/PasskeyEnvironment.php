<?php

declare(strict_types=1);

namespace Tomos;

final class PasskeyEnvironment
{
    /** @var array<string,mixed> */
    private array $config;

    /** @var array<string,mixed> */
    private array $server;

    private bool $libraryAvailable;
    private string $phpVersion;
    private bool $opensslAvailable;
    private bool $mbstringAvailable;

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed>|null $server
     */
    public function __construct(
        array $config,
        ?array $server = null,
        ?bool $libraryAvailable = null,
        ?string $phpVersion = null,
        ?bool $opensslAvailable = null,
        ?bool $mbstringAvailable = null
    ) {
        $this->config = $config;
        $this->server = $server ?? $_SERVER;
        $this->libraryAvailable = $libraryAvailable ?? class_exists('lbuchs\\WebAuthn\\WebAuthn');
        $this->phpVersion = $phpVersion ?? PHP_VERSION;
        $this->opensslAvailable = $opensslAvailable ?? extension_loaded('openssl');
        $this->mbstringAvailable = $mbstringAvailable ?? extension_loaded('mbstring');
    }

    /** @return array<string,mixed> */
    public function diagnose(): array
    {
        $rpId = $this->rpId();
        $origin = $this->origin();
        $checks = [
            'php_8' => version_compare($this->phpVersion, '8.0.0', '>='),
            'openssl' => $this->opensslAvailable,
            'mbstring' => $this->mbstringAvailable,
            'https' => $this->isHttps(),
            'library' => $this->libraryAvailable,
            'rp_id' => $rpId !== '',
            'origin' => $origin !== '',
        ];

        return [
            'available' => !in_array(false, $checks, true),
            'checks' => $checks,
            'php_version' => $this->phpVersion,
            'rp_id' => $rpId,
            'origin' => $origin,
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->diagnose()['available']);
    }

    public function rpId(): string
    {
        $url = $this->configuredSiteUrl();
        if ($url === '') {
            return '';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return $this->validHost($host) ? $host : '';
    }

    public function origin(): string
    {
        $url = $this->configuredSiteUrl();
        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        if ($scheme !== 'https' || !$this->validHost($host)) {
            return '';
        }

        $origin = 'https://' . $host;
        if (is_int($port) && $port !== 443) {
            $origin .= ':' . $port;
        }
        return $origin;
    }

    public function isHttps(): bool
    {
        if (strtolower((string) parse_url($this->configuredSiteUrl(), PHP_URL_SCHEME)) === 'https') {
            return true;
        }

        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }
        if ((int) ($this->server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        $forwarded = strtolower(trim(explode(',', (string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $forwarded === 'https';
    }

    private function configuredSiteUrl(): string
    {
        $site = is_array($this->config['site'] ?? null) ? $this->config['site'] : [];
        return trim((string) ($site['url'] ?? ''));
    }

    private function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i', $host) === 1;
    }
}
