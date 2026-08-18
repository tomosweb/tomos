<?php

declare(strict_types=1);

namespace Tomos;

final class LinkAliasIndex
{
    private string $indexFile;
    private string $pagesIndexFile;

    public function __construct(string $cacheDir)
    {
        $indexDir = rtrim($cacheDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'index';
        $this->indexFile = $indexDir . DIRECTORY_SEPARATOR . 'link-aliases.json';
        $this->pagesIndexFile = $indexDir . DIRECTORY_SEPARATOR . 'pages.json';
    }

    public function indexFile(): string
    {
        return $this->indexFile;
    }

    public function exists(): bool
    {
        return is_file($this->indexFile) && $this->isConsistentWithPages();
    }

    public function build(array $pages): array
    {
        $candidates = [];

        foreach ($pages as $page) {
            if (!is_array($page) || !empty($page['draft'])) {
                continue;
            }

            $url = trim((string) ($page['url'] ?? ''));
            $path = trim((string) ($page['path'] ?? ''));
            if ($url === '' || $path === '') {
                continue;
            }

            foreach ($this->aliasesForPage($page) as $alias) {
                $key = $this->normalizeAlias($alias);
                if ($key === '') {
                    continue;
                }

                $candidates[$key][$url] = true;
            }
        }

        $aliases = [];
        $conflicts = [];
        foreach ($candidates as $alias => $urls) {
            $urls = array_keys($urls);
            sort($urls, SORT_NATURAL);
            if (count($urls) === 1) {
                $aliases[$alias] = $urls[0];
            } else {
                $conflicts[$alias] = $urls;
            }
        }

        ksort($aliases, SORT_NATURAL);
        ksort($conflicts, SORT_NATURAL);

        return [
            'version' => 1,
            'pages_fingerprint' => $this->pagesFingerprint($pages),
            'aliases' => $aliases,
            'conflicts' => $conflicts,
        ];
    }

    public function save(array $aliases): void
    {
        $indexDir = dirname($this->indexFile);
        if (!is_dir($indexDir) && !mkdir($indexDir, 0775, true) && !is_dir($indexDir)) {
            throw new \RuntimeException('Link alias index directory could not be created.');
        }

        $json = json_encode($aliases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Link alias index could not be encoded.');
        }

        try {
            $tmpFile = $this->indexFile . '.tmp-' . bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Link alias index temporary file could not be prepared.');
        }
        if (file_put_contents($tmpFile, $json . "\n", LOCK_EX) === false) {
            @unlink($tmpFile);
            throw new \RuntimeException('Link alias index temporary file could not be written.');
        }

        if (!rename($tmpFile, $this->indexFile)) {
            @unlink($tmpFile);
            throw new \RuntimeException('Link alias index could not be saved.');
        }
    }

    public function load(): array
    {
        if (!$this->exists()) {
            return [
                'aliases' => [],
                'conflicts' => [],
            ];
        }

        $json = @file_get_contents($this->indexFile);
        if ($json === false) {
            return [
                'aliases' => [],
                'conflicts' => [],
            ];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [
                'aliases' => [],
                'conflicts' => [],
            ];
        }

        return [
            'aliases' => is_array($decoded['aliases'] ?? null) ? $decoded['aliases'] : [],
            'conflicts' => is_array($decoded['conflicts'] ?? null) ? $decoded['conflicts'] : [],
        ];
    }

    public function normalizeAlias(string $alias): string
    {
        $alias = rawurldecode(trim($alias));
        $alias = str_replace('\\', '/', $alias);
        $alias = preg_replace('#/+#', '/', $alias) ?? $alias;
        $alias = trim($alias, " /\t\n\r\0\x0B");
        $alias = preg_replace('/[\x00-\x1F\x7F]/u', '', $alias) ?? '';
        $alias = preg_replace('/[\x{3000}\s]+/u', ' ', $alias) ?? $alias;
        $alias = trim($alias);

        return $alias;
    }

    private function isConsistentWithPages(): bool
    {
        if (!is_file($this->pagesIndexFile)) {
            return false;
        }

        $aliasJson = @file_get_contents($this->indexFile);
        $pagesJson = @file_get_contents($this->pagesIndexFile);
        if (!is_string($aliasJson) || !is_string($pagesJson)) {
            return false;
        }

        $aliasData = json_decode($aliasJson, true);
        $pages = json_decode($pagesJson, true);
        if (!is_array($aliasData) || !is_array($pages)) {
            return false;
        }

        $fingerprint = $aliasData['pages_fingerprint'] ?? null;
        if (!is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            return false;
        }

        return hash_equals($fingerprint, $this->pagesFingerprint($pages));
    }

    private function pagesFingerprint(array $pages): string
    {
        $json = json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            throw new \RuntimeException('Pages fingerprint could not be encoded.');
        }

        return hash('sha256', $json);
    }

    private function aliasesForPage(array $page): array
    {
        $aliases = [];
        $path = trim((string) ($page['path'] ?? ''));
        $title = trim((string) ($page['title'] ?? ''));

        if ($path !== '') {
            $pathWithoutExtension = substr($path, -3) === '.md' ? substr($path, 0, -3) : $path;
            $basename = basename($path);
            $basenameWithoutExtension = substr($basename, -3) === '.md' ? substr($basename, 0, -3) : $basename;

            $aliases[] = $pathWithoutExtension;
            $aliases[] = $path;
            $aliases[] = $basenameWithoutExtension;
            $aliases[] = $basename;
        }

        if ($title !== '') {
            $aliases[] = $title;
            if (substr($title, -3) === '.md') {
                $aliases[] = substr($title, 0, -3);
            }
            $aliases[] = $this->stripTrailingBracketInfo($title);
        }

        $normalized = [];
        foreach ($aliases as $alias) {
            $alias = $this->normalizeAlias((string) $alias);
            if ($alias !== '') {
                $normalized[$alias] = $alias;
            }
        }

        return array_values($normalized);
    }

    private function stripTrailingBracketInfo(string $title): string
    {
        $title = trim($title);
        $stripped = preg_replace('/\s*(?:【[^】]*】|（[^）]*）|\([^)]*\))\s*$/u', '', $title) ?? $title;

        return trim($stripped);
    }
}
