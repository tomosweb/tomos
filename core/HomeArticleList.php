<?php

declare(strict_types=1);

namespace Tomos;

final class HomeArticleList
{
    public static function items(array $pages, string $contentDir, string $publicBasePath): array
    {
        if ($pages === []) {
            return [];
        }

        $repository = new PageRepository($contentDir);
        $frontMatter = new FrontMatterParser();
        $images = new ImageEmbedParser($contentDir, $publicBasePath);
        $items = [];
        foreach ($pages as $indexedPage) {
            $lookup = $repository->findByRoute(new Route($indexedPage['url'], [$indexedPage['path']]));
            if ($lookup->status !== 'ok' || $lookup->page === null) {
                continue;
            }

            $page = $lookup->page;
            $imageUrl = SeoMetadata::articleImageUrl($page['image'], $page['path'], $contentDir, $publicBasePath);
            $image = $imageUrl === null ? $images->firstImage($page['body'], $page['path']) : null;
            $imageUrl = $imageUrl ?? $image['url'] ?? '';
            $date = (string) ($page['date'] ?? '');
            $excerptSource = preg_replace('/!\[[^\]\n]*\]\([^)\n]*\)|!\[\[[^\]\n]+\]\]/', '', $page['body']) ?? $page['body'];
            $items[] = [
                'title' => $page['title'],
                'url' => Security::publicUrl($page['url'], $publicBasePath),
                'date' => $date,
                'date_display' => str_replace('-', '.', $date),
                'excerpt' => $frontMatter->excerptFromMarkdown($excerptSource),
                'image_url' => $imageUrl,
                'image_alt' => $image['alt'] ?? '',
            ];
        }

        return $items;
    }
}
