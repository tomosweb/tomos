<?php

declare(strict_types=1);

namespace Tomos;

final class VirtualFolderIndex
{
    public static function find(Route $route, array $pages): ?array
    {
        if (!$route->isValid || $route->urlPath === '/' || substr($route->urlPath, -1) !== '/') {
            return null;
        }

        $folder = trim($route->urlPath, '/');
        if ($folder === '' || !Security::isSafeRelativePath($folder)) {
            return null;
        }

        $prefix = $folder . '/';
        $hasPublicChild = false;

        foreach ($pages as $page) {
            if (!is_array($page) || !empty($page['draft'])) {
                continue;
            }

            $path = (string) ($page['path'] ?? '');
            $relative = strpos($path, $prefix) === 0 ? substr($path, strlen($prefix)) : '';
            if ($relative !== '' && strpos($relative, '/') === false && substr($relative, -3) === '.md') {
                if ($relative !== 'index.md') {
                    $hasPublicChild = true;
                    break;
                }
            }
        }

        if (!$hasPublicChild) {
            return null;
        }

        $segments = explode('/', $folder);
        $title = (string) end($segments);

        return [
            'page_type' => 'virtual_folder_index',
            'folder_path' => $folder,
            'path' => '',
            'url' => $route->urlPath,
            'title' => $title,
            'description' => '',
            'description_explicit' => true,
            'date' => '',
            'published' => '',
            'updated' => '',
            'tags' => [],
            'draft' => false,
            'content_raw' => '',
            'body' => '',
            'markdown' => '',
            'metadata' => [],
        ];
    }
}
