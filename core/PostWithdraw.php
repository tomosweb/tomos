<?php

declare(strict_types=1);

namespace Tomos;

foreach ([
    'Security' => 'Security.php',
    'FrontMatterParser' => 'FrontMatterParser.php',
    'HtmlCache' => 'HtmlCache.php',
    'ImageReferenceIndex' => 'ImageReferenceIndex.php',
    'ImageDeletionRetryQueue' => 'ImageDeletionRetryQueue.php',
    'PostBasicPage' => 'PostBasicPage.php',
] as $dependency => $file) {
    if (!class_exists(__NAMESPACE__ . '\\' . $dependency)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . $file;
    }
}

final class PostWithdrawResult
{
    public bool $ok;
    /** @var string[] */
    public array $errors;
    /** @var string[] */
    public array $warnings;
    public string $fromPath;
    public string $toPath;

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    public function __construct(bool $ok, array $errors = [], array $warnings = [], string $fromPath = '', string $toPath = '')
    {
        $this->ok = $ok;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->fromPath = $fromPath;
        $this->toPath = $toPath;
    }
}

final class PostWithdraw
{
    private string $contentDir;
    private string $trashDir;
    private string $cacheDir;
    private bool $htmlCacheEnabled;
    private bool $includeDrafts;
    private ?array $freshImageReferenceIndex = null;

    public function __construct(array $config, string $rootDir)
    {
        $this->contentDir = (string) (($config['paths']['content_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'content'));
        $this->trashDir = $rootDir . DIRECTORY_SEPARATOR . 'trash';
        $this->cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'cache'));
        $this->htmlCacheEnabled = (bool) ($config['features']['html_cache'] ?? false);
        $this->includeDrafts = (bool) ($config['metadata']['include_drafts'] ?? false);
    }

    public function withdraw(string $contentPath): PostWithdrawResult
    {
        $contentPath = trim(str_replace('\\', '/', $contentPath));
        if (PostBasicPage::isProtectedContentPath($contentPath)) {
            return new PostWithdrawResult(false, ['トップページとAboutページは取り下げできません。']);
        }
        if (!Security::isSafeRelativePath($contentPath) || !Security::hasAllowedExtension($contentPath, ['md'])) {
            return new PostWithdrawResult(false, ['取り下げ対象は content/ 配下の .md ファイルだけです。']);
        }

        $contentBase = realpath($this->contentDir);
        if ($contentBase === false || !is_dir($contentBase)) {
            return new PostWithdrawResult(false, ['content/ フォルダが見つかりません。']);
        }

        $sourcePath = rtrim($contentBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $contentPath);
        $sourceReal = realpath($sourcePath);
        if ($sourceReal === false || !is_file($sourceReal) || is_link($sourceReal)) {
            return new PostWithdrawResult(false, ['取り下げ対象のMarkdownファイルが見つかりません。']);
        }

        if (!Security::isPathInside($sourceReal, $contentBase)) {
            return new PostWithdrawResult(false, ['content/ 外のファイルは取り下げ対象にできません。']);
        }

        $sourceMarkdown = @file_get_contents($sourceReal);
        $removedImageRefs = is_string($sourceMarkdown)
            ? $this->managedImageReferences($sourceMarkdown, $contentPath)
            : [];

        $trashContentDir = $this->trashDir . DIRECTORY_SEPARATOR . 'content';
        $targetPath = $trashContentDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $contentPath);
        $targetPath = $this->avoidCollision($targetPath);
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return new PostWithdrawResult(false, ['trash/ の移動先フォルダを作成できませんでした。']);
        }

        if (!$this->isInsideTrash($targetDir)) {
            return new PostWithdrawResult(false, ['trash/ 外へ移動しようとしたため中止しました。']);
        }

        if (!@rename($sourceReal, $targetPath)) {
            if (!@copy($sourceReal, $targetPath)) {
                return new PostWithdrawResult(false, ['ファイルを trash/ へ移動できませんでした。']);
            }
            if (!@unlink($sourceReal)) {
                @unlink($targetPath);
                return new PostWithdrawResult(false, ['ファイルを trash/ へ移動できませんでした。']);
            }
        }

        $warnings = $this->clearCaches($contentPath, $removedImageRefs);
        $from = 'content/' . $contentPath;
        $to = 'trash/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($targetPath, strlen(rtrim($this->trashDir, DIRECTORY_SEPARATOR)) + 1));

        return new PostWithdrawResult(true, [], $warnings, $from, $to);
    }

    private function avoidCollision(string $targetPath): string
    {
        if (!file_exists($targetPath)) {
            return $targetPath;
        }

        $dir = dirname($targetPath);
        $name = pathinfo($targetPath, PATHINFO_FILENAME);
        $extension = pathinfo($targetPath, PATHINFO_EXTENSION);
        $suffix = '__' . date('Ymd-His');
        $candidate = $dir . DIRECTORY_SEPARATOR . $name . $suffix . ($extension !== '' ? '.' . $extension : '');
        $counter = 2;
        while (file_exists($candidate)) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $name . $suffix . '-' . $counter . ($extension !== '' ? '.' . $extension : '');
            $counter++;
        }

        return $candidate;
    }

    private function isInsideTrash(string $path): bool
    {
        if (!is_dir($this->trashDir) && !@mkdir($this->trashDir, 0775, true) && !is_dir($this->trashDir)) {
            return false;
        }

        $realPath = realpath($path);
        $realTrash = realpath($this->trashDir);
        if ($realPath === false || $realTrash === false) {
            return false;
        }

        return strpos(rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, rtrim($realTrash, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
    }

    /**
     * @return string[]
     */
    private function clearCaches(string $contentPath, array $removedImageRefs): array
    {
        $warnings = [];
        $pagesJson = rtrim($this->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'pages.json';
        if (is_file($pagesJson) && !@unlink($pagesJson)) {
            $warnings[] = 'cache/index/pages.json を削除できませんでした。一覧や検索への反映が遅れる場合があります。';
        }

        $htmlCache = new HtmlCache($this->cacheDir, $this->htmlCacheEnabled);
        if (!$htmlCache->delete($contentPath)) {
            $warnings[] = '対象ページのHTMLキャッシュを削除できませんでした。しばらくしても表示が残る場合は cache/html/ を確認してください。';
        }

        $imageReferenceReady = false;
        try {
            $index = $this->imageReferenceIndex();
            $this->freshImageReferenceIndex = $index->rebuild();
            $imageReferenceReady = true;
        } catch (\Throwable $exception) {
            $warnings[] = '投稿は取り下げましたが、画像参照情報を更新できませんでした。画像削除判定を行う前に cache/index/image-references.json を再生成してください。';
        }

        if ($imageReferenceReady) {
            $warnings = array_merge($warnings, (new ImageDeletionRetryQueue($this->cacheDir, $index))->attempt([], $this->freshImageReferenceIndex));
            $warnings = array_merge($warnings, $this->deleteUnreferencedImages($removedImageRefs));
        }

        return $warnings;
    }

    /**
     * @return string[]
     */
    private function managedImageReferences(string $markdown, string $contentPath): array
    {
        try {
            return $this->imageReferenceIndex()->managedReferencesFromMarkdown($markdown, $contentPath);
        } catch (\Throwable $exception) {
            return [];
        }
    }

    /**
     * @param string[] $imagePaths
     * @return string[]
     */
    private function deleteUnreferencedImages(array $imagePaths): array
    {
        $index = $this->imageReferenceIndex();
        return (new ImageDeletionRetryQueue($this->cacheDir, $index))->attempt(
            $imagePaths,
            $this->freshImageReferenceIndex,
            false
        );
    }

    private function imageReferenceIndex(): ImageReferenceIndex
    {
        return new ImageReferenceIndex(
            $this->contentDir,
            $this->cacheDir,
            new FrontMatterParser(),
            $this->includeDrafts
        );
    }
}
