<?php

declare(strict_types=1);

namespace Tomos;

final class Route
{
    public string $urlPath;
    /** @var string[] */
    public array $contentPathCandidates;
    public bool $isValid;
    public string $reason;

    /**
     * @param string[] $contentPathCandidates
     */
    public function __construct(string $urlPath, array $contentPathCandidates, bool $isValid = true, string $reason = 'ok')
    {
        $this->urlPath = $urlPath;
        $this->contentPathCandidates = $contentPathCandidates;
        $this->isValid = $isValid;
        $this->reason = $reason;
    }
}

final class Router
{
    private string $basePath;

    public function __construct(string $basePath = '')
    {
        $this->basePath = Security::normalizeBasePath($basePath);
    }

    public function resolve(string $requestUri): Route
    {
        $rawPath = $this->normalizeRequestPath($requestUri);
        if ($rawPath === null) {
            return new Route('/', [], false, 'invalid_path');
        }

        $validation = Security::validateUrlPath($rawPath);

        if (!$validation['is_valid']) {
            return new Route('/', [], false, $validation['reason']);
        }

        $path = $validation['path'];

        if ($path === '/') {
            return new Route('/', ['index.md']);
        }

        $trimmed = trim($path, '/');
        if (substr($path, -1) === '/') {
            return new Route('/' . $trimmed . '/', [$trimmed . '/index.md']);
        }

        return new Route('/' . $trimmed, [
            $trimmed . '.md',
            $trimmed . '/index.md',
        ]);
    }

    private function normalizeRequestPath(string $requestUri): ?string
    {
        $rawPath = parse_url($requestUri, PHP_URL_PATH);
        $rawPath = is_string($rawPath) ? $rawPath : '/';
        $rawPath = $this->stripBasePath($rawPath);
        if ($rawPath === null) {
            return null;
        }

        $rawPath = rawurldecode($rawPath);
        $rawPath = preg_replace('#/+#', '/', $rawPath) ?? '/';

        if ($rawPath === '' || $rawPath[0] !== '/') {
            $rawPath = '/' . $rawPath;
        }

        if ($rawPath === '/index.php' || $rawPath === '/index.php/') {
            return '/';
        }

        if (strpos($rawPath, '/index.php/') === 0) {
            $rawPath = substr($rawPath, strlen('/index.php'));
            return $rawPath === '' ? '/' : $rawPath;
        }

        return $rawPath;
    }

    private function stripBasePath(string $rawPath): ?string
    {
        if ($this->basePath === '') {
            return $rawPath;
        }

        if ($rawPath === $this->basePath) {
            return '/';
        }

        if (strpos($rawPath, $this->basePath . '/') === 0) {
            return substr($rawPath, strlen($this->basePath));
        }

        return null;
    }

}
