<?php

declare(strict_types=1);

namespace Tomos;

final class PerformanceLogger
{
    private bool $enabled;
    private string $logFile;
    private float $startedAt;
    private float $lastAt;
    /** @var array<string, int|float|string> */
    private array $fields = [];

    public function __construct(array $config)
    {
        $debug = is_array($config['debug'] ?? null) ? $config['debug'] : [];
        $this->enabled = !empty($debug['performance_log']);
        $cacheDir = rtrim((string) ($config['paths']['cache_dir'] ?? ''), DIRECTORY_SEPARATOR);
        $this->logFile = $cacheDir !== ''
            ? $cacheDir . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'performance.log'
            : '';
        $this->startedAt = microtime(true);
        $this->lastAt = $this->startedAt;
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->logFile !== '';
    }

    public function lap(string $name): void
    {
        if (!$this->enabled()) {
            return;
        }

        $now = microtime(true);
        $this->fields[$name . '_ms'] = (int) round(($now - $this->lastAt) * 1000);
        $this->lastAt = $now;
    }

    /**
     * @param int|float|string|bool $value
     */
    public function set(string $name, $value): void
    {
        if (!$this->enabled()) {
            return;
        }

        $this->fields[$name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
    }

    public function increment(string $name): void
    {
        if (!$this->enabled()) {
            return;
        }

        $this->fields[$name] = (int) ($this->fields[$name] ?? 0) + 1;
    }

    public function finish(string $path): void
    {
        if (!$this->enabled()) {
            return;
        }

        $this->fields['total_ms'] = (int) round((microtime(true) - $this->startedAt) * 1000);
        $this->write($path);
    }

    private function write(string $path): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $safePath = parse_url($path, PHP_URL_PATH);
        $safePath = is_string($safePath) && $safePath !== '' ? $safePath : '/';
        $parts = [
            '[' . date('Y-m-d H:i:s') . ']',
            'path=' . $this->sanitizeValue($safePath),
        ];

        foreach ($this->fields as $key => $value) {
            $parts[] = $this->sanitizeKey($key) . '=' . $this->sanitizeValue((string) $value);
        }

        @file_put_contents($this->logFile, implode(' ', $parts) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) ?? 'field';
    }

    private function sanitizeValue(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F\s]+/u', '_', $value) ?? '';
        return substr($value, 0, 180);
    }
}
