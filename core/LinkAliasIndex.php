<?php

declare(strict_types=1);

namespace Tomos;

final class LinkAliasIndex
{
    private string $indexFile;

    public function __construct(string $cacheDir)
    {
        $this->indexFile = rtrim($cacheDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'index'
            . DIRECTORY_SEPARATOR . 'link-aliases.json';
    }

    public function indexFile(): string
    {
        return $this->indexFile;
    }

    public function exists(): bool
    {
        return is_file($this->indexFile);
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

        $tmpFile = $this->indexFile . '.tmp';
        if (file_put_contents($tmpFile, $json . "\n", LOCK_EX) === false) {
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
