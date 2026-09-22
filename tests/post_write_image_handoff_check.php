<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

require_once dirname(__DIR__) . '/core/PostSubmissionGuard.php';

checkDirectHandoffImageStateOrder();
checkFrontMatterLocalOgpImageHandoff();
checkExistingImagesNeedNoReuploadAndAreKept();
checkEditableRemovedImageIsDeleted();
checkSharedImageIsPreserved();
checkAllUnreferencedImagesAreDeleted();
checkNewImageAndExistingImageAreHandledTogether();
checkNewImageAndRemovedImageAreHandledTogether();
checkConflictPreservesImagesAndSource();
checkDestinationChangePreservesSourceImages();
checkIndexWarningPreservesImages();

echo "post_write_image_handoff_check: OK\n";

function checkDirectHandoffImageStateOrder(): void
{
    $source = (string) file_get_contents(dirname(__DIR__) . '/post/index.php');
    $start = strpos($source, 'window.TomosPostImportMarkdown =');
    $end = strpos($source, 'const extensionForFile =', $start === false ? 0 : $start);
    if ($start === false || $end === false) {
        throw new RuntimeException('direct handoff import function is missing');
    }
    $direct = substr($source, $start, $end - $start);
    assertOrder($direct, [
        'const sourceMetadata = extractSourceMetadata(markdown);',
        'const images = extractImages(markdown);',
        'renderImageMatches(new Set());',
    ], 'direct handoff must resolve editability before rendering image requirements');
    assertContains($direct, '既存画像は選択不要です。Tomos Writeで新しく追加した画像だけを選んでください。', 'direct handoff must show the editable image guidance');
}

function checkFrontMatterLocalOgpImageHandoff(): void
{
    $source = (string) file_get_contents(dirname(__DIR__) . '/post/index.php');
    assertContains($source, 'const extractFrontMatterLocalImage = (markdown) => {', 'Tomos Post must detect a local Front Matter OGP image');
    assertContains($source, 'const ogpImageStatus = document.getElementById("ogp-image-status");', 'Tomos Post must render OGP status separately from article image status');
    assertContains($source, 'let frontMatterImage = null;', 'Front Matter OGP image must be tracked separately from article images');
    assertContains($source, 'const wikiPattern = /!\\[\\[', 'Tomos Post must detect Obsidian wiki image embeds');
    assertContains($source, 'kind: "local"', 'local article images must be distinguished from already-managed images');
    assertContains($source, 'String(image.sourceName || "").toLowerCase() === file.name.toLowerCase()', 'Tomos Post must match selected local article image sources');
    assertContains($source, 'String(frontMatterImage.sourceName || "").toLowerCase() === file.name.toLowerCase()', 'Tomos Post must match the selected OGP source independently');
    assertContains($source, 'const articleRewrittenMarkdown = rewriteLocalArticleImages(loadedMarkdown);', 'Tomos Post must rewrite local article images before submit');
    assertContains($source, 'const rewrittenMarkdown = rewriteFrontMatterLocalImage(articleRewrittenMarkdown);', 'Tomos Post must rewrite local OGP Front Matter after article image rewriting');
    assertContains($source, 'handoffMarkdownInput.value = rewrittenMarkdown;', 'rewritten OGP Front Matter must be submitted instead of the original local path');
    assertContains($source, 'frontMatterImage.fileName === "" || !selectedImages.has(frontMatterImage.fileName)', 'local OGP image must be required independently before publication');
    assertTrue(strpos($source, 'Front Matter指定:') === false, 'OGP image UI must not show the redundant Front Matter path label');
    assertContains($source, '元画像:', 'OGP image UI must show the source filename');
    assertContains($source, '<strong>OGP画像</strong>', 'OGP image must have a separate display block');
    assertContains($source, '<strong>投稿する画像</strong>', 'article images must keep the existing display block');
}

function checkExistingImagesNeedNoReuploadAndAreKept(): void
{
    $scenario = scenario('existing');
    try {
        $source = writeSource($scenario, ['a']);
        $editable = editableDownload($scenario, $source);
        $pending = prepareEditable($scenario, $editable['content'], 'existing-session');
        assertTrue($pending->conflict, 'unchanged editable image must reach confirmation without re-upload');
        $result = updateEditable($scenario, $pending, 'existing-session');
        assertTrue($result->ok, 'unchanged existing image update must succeed');
        assertTrue(is_file($scenario['content'] . '/images/' . $scenario['images']['a']), 'unchanged existing image must be preserved');
    } finally {
        removeTree($scenario['root']);
    }
}

function checkEditableRemovedImageIsDeleted(): void
{
    $scenario = scenario('removed');
    try {
        $source = writeSource($scenario, ['a', 'b']);
        $editable = editableDownload($scenario, $source);
        $updated = str_replace(managedImageMarkdownLine('b'), '', $editable['content']);
        $pending = prepareEditable($scenario, $updated, 'removed-session');
        $result = updateEditable($scenario, $pending, 'removed-session');
        assertTrue($result->ok, 'editable image removal update must succeed');
        assertTrue(is_file($scenario['content'] . '/images/' . $scenario['images']['a']), 'remaining image must be preserved');
        assertTrue(!is_file($scenario['content'] . '/images/' . $scenario['images']['b']), 'unreferenced removed image must be deleted');
    } finally {
        removeTree($scenario['root']);
    }
}

function checkSharedImageIsPreserved(): void
{
    $scenario = scenario('shared');
    try {
        $source = writeSource($scenario, ['a', 'b']);
        file_put_contents($scenario['content'] . '/other.md', markdownFor(['b'], 'Other'));
        $editable = editableDownload($scenario, $source);
        $updated = str_replace(managedImageMarkdownLine('b'), '', $editable['content']);
        $pending = prepareEditable($scenario, $updated, 'shared-session');
        $result = updateEditable($scenario, $pending, 'shared-session');
        assertTrue($result->ok, 'shared-image update must succeed');
        assertTrue(is_file($scenario['content'] . '/images/' . $scenario['images']['b']), 'image referenced by another article must be preserved');
    } finally {
        removeTree($scenario['root']);
    }
}

function checkAllUnreferencedImagesAreDeleted(): void
{
    $scenario = scenario('all-removed');
    try {
        $source = writeSource($scenario, ['a', 'b']);
        $editable = editableDownload($scenario, $source);
        $updated = str_replace([managedImageMarkdownLine('a'), managedImageMarkdownLine('b')], '', $editable['content']);
        $pending = prepareEditable($scenario, $updated, 'all-removed-session');
        $result = updateEditable($scenario, $pending, 'all-removed-session');
        assertTrue($result->ok, 'all-image removal update must succeed');
        assertTrue(!is_file($scenario['content'] . '/images/' . $scenario['images']['a']), 'first unreferenced image must be deleted');
        assertTrue(!is_file($scenario['content'] . '/images/' . $scenario['images']['b']), 'second unreferenced image must be deleted');
    } finally {
        removeTree($scenario['root']);
    }
}

function checkNewImageAndExistingImageAreHandledTogether(): void
{
    $scenario = scenario('new-kept');
    try {
        $source = writeSource($scenario, ['a']);
        $editable = editableDownload($scenario, $source);
        $updated = $editable['content'] . managedImageMarkdownLine('c');
        $newImage = tempImage($scenario, 'c');
        $pending = prepareEditable($scenario, $updated, 'new-kept-session', $newImage);
        $result = updateEditable($scenario, $pending, 'new-kept-session');
        assertTrue($result->ok, 'existing plus new image update must succeed');
        assertTrue(is_file($scenario['content'] . '/images/' . $scenario['images']['a']), 'existing image must not require re-upload');
        assertTrue(is_file($scenario['content'] . '/images/' . $scenario['images']['c']), 'new image must be saved');
    } finally {
        removeTree($scenario['root']);
    }
}

function checkNewImageAndRemovedImageAreHandledTogether(): void
{
    $scenario = scenario('new-replaces');
    try {
        $source = writeSource($scenario, ['a']);
        $editable = editableDownload($scenario, $source);
        $updated = str_replace(managedImageMarkdownLine('a'), '', $editable['content']) . managedImageMarkdownLine('c');
        $newImage = tempImage($scenario, 'c');
        $pending = prepareEditable($scenario, $updated, 'new-replaces-session', $newImage);
        $result = updateEditable($scenario, $pending, 'new-replaces-session');
        assertTrue($result->ok, 'new image plus removed image update must succeed');
        assertTrue(!is_file($scenario['content'] . '/images/' . $scenario['images']['a']), 'removed old image must be deleted after success');
        assertTrue(is_file($scenario['content'] . '/images/' . $scenario['images']['c']), 'replacement image must be saved');
    } finally {
        removeTree($scenario['root']);
    }
}

function checkConflictPreservesImagesAndSource(): void
{
    $scenario = scenario('conflict');
    try {
        $source = writeSource($scenario, ['a', 'b']);
        $editable = editableDownload($scenario, $source);
        $updated = str_replace(managedImageMarkdownLine('b'), '', $editable['content']);
        $pending = prepareEditable($scenario, $updated, 'conflict-session');
        $changedSource = markdownFor(['a', 'b'], 'Changed elsewhere');
        file_put_contents($scenario['content'] . '/article.md', $changedSource, LOCK_EX);
        $result = updateEditable($scenario, $pending, 'conflict-session');
        assertTrue(!$result->ok, 'source conflict must cancel editable update');
        assertSame($changedSource, (string) file_get_contents($scenario['content'] . '/article.md'), 'conflict must preserve source Markdown');
        assertTrue(is_file($scenario['content'] . '/images/' . $scenario['images']['b']), 'conflict must preserve images');
    } finally {
        removeTree($scenario['root']);
    }
}

function checkDestinationChangePreservesSourceImages(): void
{
    $scenario = scenario('destination');
    try {
        $source = writeSource($scenario, ['a']);
        $editable = editableDownload($scenario, $source);
        $pending = prepareEditable($scenario, $editable['content'], 'destination-session', [], 'new-folder');
        assertTrue($pending->conflict, 'destination change must require editable-new confirmation');
        $result = (new Tomos\PostUpload($scenario['config'], $scenario['root']))->createEditableFromTemp(
            $pending->tempId,
            'destination-session',
            $scenario['submission_id']
        );
        assertTrue($result->ok, 'destination-changed editable save must succeed');
        assertTrue(is_file($scenario['content'] . '/article.md'), 'source article must remain');
        assertTrue(is_file($scenario['content'] . '/images/' . $scenario['images']['a']), 'source image must remain');
        assertTrue(is_file($scenario['content'] . '/new-folder/article.md'), 'new destination article must be created');
    } finally {
        removeTree($scenario['root']);
    }
}

function checkIndexWarningPreservesImages(): void
{
    $scenario = scenario('index-warning');
    try {
        $source = writeSource($scenario, ['a', 'b']);
        $editable = editableDownload($scenario, $source);
        $updated = str_replace(managedImageMarkdownLine('b'), '', $editable['content']);
        $pending = prepareEditable($scenario, $updated, 'index-warning-session');
        file_put_contents($scenario['cache'] . '/index', 'index path intentionally blocked', LOCK_EX);
        set_error_handler(static function (): bool { return true; });
        try {
            $result = updateEditable($scenario, $pending, 'index-warning-session');
        } finally {
            restore_error_handler();
        }
        assertTrue($result->ok, 'index warning must not fail Markdown update');
        assertContains(implode('\n', $result->warnings), '画像参照情報', 'index warning must be reported');
        assertTrue(is_file($scenario['content'] . '/images/' . $scenario['images']['b']), 'index warning must fail closed and preserve image');
    } finally {
        removeTree($scenario['root']);
    }
}

/** @return array{root:string,content:string,cache:string,config:array,images:array,submission_id:string} */
function scenario(string $name): array
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-write-image-' . $name . '-' . bin2hex(random_bytes(6));
    $content = $root . '/content';
    $cache = $root . '/cache';
    mkdir($content . '/images', 0775, true);
    mkdir($cache, 0775, true);
    $config = [
        'site' => ['url' => 'https://example.test/', 'timezone' => 'Asia/Tokyo'],
        'paths' => ['content_dir' => $content, 'cache_dir' => $cache],
        'metadata' => ['include_drafts' => true],
        'features' => ['html_cache' => false],
    ];
    return [
        'root' => $root,
        'content' => $content,
        'cache' => $cache,
        'config' => $config,
        'images' => [
            'a' => 'tms-aaaaaaaaaaaaaaaa.jpg',
            'b' => 'tms-bbbbbbbbbbbbbbbb.jpg',
            'c' => 'tms-cccccccccccccccc.gif',
        ],
        'submission_id' => Tomos\PostSubmissionGuard::issueId(),
    ];
}

function writeSource(array $scenario, array $references): string
{
    foreach ($references as $reference) {
        file_put_contents(
            $scenario['content'] . '/images/' . $scenario['images'][$reference],
            'image-' . $reference,
            LOCK_EX
        );
    }
    $markdown = markdownFor($references, 'Source');
    file_put_contents($scenario['content'] . '/article.md', $markdown, LOCK_EX);
    return $markdown;
}

/** @return array{ok:bool,content:string} */
function editableDownload(array $scenario, string $source): array
{
    $result = (new Tomos\PostEditableMarkdown($scenario['config'], $scenario['root']))->download('article.md');
    if (empty($result['ok']) || !is_string($result['content'] ?? null)) {
        throw new RuntimeException('editable Markdown download failed');
    }
    assertSame(hash('sha256', $source), hash('sha256', (string) file_get_contents($scenario['content'] . '/article.md')), 'source must remain unchanged before update');
    return ['ok' => true, 'content' => $result['content']];
}

function prepareEditable(array &$scenario, string $markdown, string $session, array $imageFile = [], string $folder = ''): Tomos\PostUploadResult
{
    $submissionId = Tomos\PostSubmissionGuard::issueId();
    $scenario['submission_id'] = $submissionId;
    $upload = new Tomos\PostUpload($scenario['config'], $scenario['root']);
    $result = $upload->handleContent(
        $markdown,
        'article.md',
        $folder,
        '',
        $session,
        $imageFile,
        [],
        $imageFile !== [],
        $submissionId
    );
    if (!$result->conflict || $result->tempId === '') {
        throw new RuntimeException('editable content must require confirmation: ' . implode(' / ', $result->errors));
    }
    return $result;
}

function updateEditable(array $scenario, Tomos\PostUploadResult $pending, string $session): Tomos\PostUploadResult
{
    return (new Tomos\PostUpload($scenario['config'], $scenario['root']))->updateEditableFromTemp(
        $pending->tempId,
        'published',
        false,
        $session,
        $scenario['submission_id']
    );
}

function tempImage(array $scenario, string $reference): array
{
    $path = $scenario['root'] . '/new-' . $reference . '.gif';
    $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
    if (!is_string($bytes) || file_put_contents($path, $bytes, LOCK_EX) === false) {
        throw new RuntimeException('test image could not be prepared');
    }
    $name = $scenario['images'][$reference];
    return [
        'name' => [$name],
        'type' => ['image/gif'],
        'tmp_name' => [$path],
        'error' => [UPLOAD_ERR_OK],
        'size' => [strlen($bytes)],
    ];
}

function markdownFor(array $references, string $title): string
{
    $markdown = "---\ntitle: {$title}\ndraft: false\n---\n# {$title}\n\n";
    foreach ($references as $reference) $markdown .= managedImageMarkdownLine($reference);
    return $markdown;
}

function managedImageMarkdownLine(string $reference): string
{
    return '![' . strtoupper($reference) . '](images/tms-' . str_repeat($reference, 16) . '.' . ($reference === 'c' ? 'gif' : 'jpg') . ")\n";
}

function assertOrder(string $source, array $needles, string $message): void
{
    $offset = -1;
    foreach ($needles as $needle) {
        $next = strpos($source, $needle);
        if ($next === false || $next <= $offset) throw new RuntimeException($message);
        $offset = $next;
    }
}

function assertContains(string $haystack, string $needle, string $message): void
{
    if (strpos($haystack, $needle) === false) throw new RuntimeException($message);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) && !is_link($child) ? removeTree($child) : @unlink($child);
    }
    @rmdir($path);
}
