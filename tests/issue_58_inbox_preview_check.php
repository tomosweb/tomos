<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'Tomos\\') !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/core/' . str_replace('Tomos\\', '', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-issue-58-' . bin2hex(random_bytes(6));
$content = $root . DIRECTORY_SEPARATOR . 'content';
$cache = $root . DIRECTORY_SEPARATOR . 'cache';
$inboxPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inbox';
foreach ([$content, $cache, $inboxPath] as $directory) {
    if (!mkdir($directory, 0775, true)) {
        throw new RuntimeException('test directory could not be created');
    }
}

file_put_contents($content . DIRECTORY_SEPARATOR . 'published.md', "---\ntitle: Published\n---\n# Published\n");
$postDraftPath = $content . DIRECTORY_SEPARATOR . 'diary' . DIRECTORY_SEPARATOR . 'draft-experiment.md';
mkdir(dirname($postDraftPath), 0775, true);
$postDraftMarkdown = "---\ntitle: 下書き実験\ndate:\nupdated: 2026-08-12\ndescription:\nfolder: diary\ndraft: true\n---\n\n# 下書き実験です。\n\n今日のことを書きます。\n受信箱実験です。\n";
$markdown = "---\ntitle: Inbox preview\ndate:\ndraft: true\ntags:\n  - inbox\n---\n# Inbox preview\n\n本文です。\n";
$inboxFile = $inboxPath . DIRECTORY_SEPARATOR . 'preview.md';
file_put_contents($inboxFile, $markdown);
$beforeHash = hash_file('sha256', $inboxFile);
$beforeMtime = filemtime($inboxFile);

$config = [
    'site' => [
        'url' => 'https://example.test/',
        'name' => 'Example',
        'language' => 'ja',
        'description' => 'Example site',
        'timezone' => 'Asia/Tokyo',
    ],
    'paths' => [
        'content_dir' => $content,
        'cache_dir' => $cache,
        'inbox_dir' => $inboxPath,
        'theme_dir' => dirname(__DIR__) . '/themes',
    ],
    'security' => ['allow_raw_html' => false],
    'features' => ['rss' => true],
];
$inbox = new \Tomos\PostInbox($config, $root);
if (!is_file($postDraftPath)) {
    $created = (new \Tomos\PostUpload($config, $root))->handleContent($postDraftMarkdown, 'draft-experiment.md', 'diary', '', 'test-session', [], [], false, str_repeat('b', 64));
    if (!$created->ok) {
        throw new RuntimeException('Tomos Post draft could not be saved through the normal Post path');
    }
    if (!$created->isDraft || $created->operation !== 'draft_create') {
        throw new RuntimeException('Tomos Post draft result must expose explicit draft state');
    }
}
$savedDraft = file_get_contents($postDraftPath);
if (!is_string($savedDraft)) {
    throw new RuntimeException('Tomos Post draft was not saved in content/');
}
$postDraftMarkdown = $savedDraft;
$beforeContentFiles = array_map('basename', glob($content . DIRECTORY_SEPARATOR . '*') ?: []);
$beforeCacheFiles = array_map('basename', glob($cache . DIRECTORY_SEPARATOR . '*') ?: []);
$drafts = new \Tomos\PostDrafts($config, $root);
$read = $inbox->read('preview.md');
if (!$read->ok || $read->content !== $markdown) {
    throw new RuntimeException('Inbox read must return the original Markdown');
}
$draftItems = $drafts->list();
$sources = array_map(static fn (\Tomos\PostDraftItem $item): string => $item->source, $draftItems);
if (!in_array('inbox', $sources, true) || !in_array('post', $sources, true)) {
    throw new RuntimeException('Inbox and Tomos Post drafts must share one draft list');
}
$postRead = $drafts->read('post', 'diary/draft-experiment.md');
if (!$postRead->ok || $postRead->content !== $postDraftMarkdown) {
    throw new RuntimeException('Tomos Post draft must be readable byte-for-byte');
}

foreach (['tomos-minimal', 'tomos-blog', 'tomos-note', 'tomos-journal', 'tomos-dark', 'tomos-90s'] as $theme) {
    $themeConfig = $config;
    $themeConfig['theme'] = ['name' => $theme];
    $html = (new \Tomos\PostInboxPreview($themeConfig, $root))->render($read->content, $read->fileName);
    if (strpos($html, 'Inbox preview') === false || strpos($html, '本文です。') === false) {
        throw new RuntimeException($theme . ' preview did not render the Inbox page');
    }
    if (strpos($html, '<meta name="robots" content="noindex, nofollow">') === false) {
        throw new RuntimeException($theme . ' preview must be noindex/nofollow');
    }
}
$postHtml = (new \Tomos\PostInboxPreview($config, $root))->render($postRead->content, $postRead->fileName, $postRead->path);
if (strpos($postHtml, '下書き実験です。') === false || strpos($postHtml, '今日のことを書きます。') === false) {
    throw new RuntimeException('Tomos Post draft preview did not render');
}

if (hash_file('sha256', $inboxFile) !== $beforeHash || filemtime($inboxFile) !== $beforeMtime) {
    throw new RuntimeException('preview must not modify Inbox content or mtime');
}
if (array_map('basename', glob($content . DIRECTORY_SEPARATOR . '*') ?: []) !== $beforeContentFiles
    || array_map('basename', glob($cache . DIRECTORY_SEPARATOR . '*') ?: []) !== $beforeCacheFiles
) {
    throw new RuntimeException('preview must not create content or cache files');
}

$outside = $root . DIRECTORY_SEPARATOR . 'outside.md';
file_put_contents($outside, "secret\n");
if (!symlink($outside, $inboxPath . DIRECTORY_SEPARATOR . 'link.md')) {
    throw new RuntimeException('test symlink could not be created');
}
if ($inbox->read('link.md')->ok || $inbox->read('../outside.md')->ok) {
    throw new RuntimeException('Inbox traversal and symlink paths must be rejected');
}
if (!symlink($outside, $content . DIRECTORY_SEPARATOR . 'post-link.md')) {
    throw new RuntimeException('content test symlink could not be created');
}
if ($drafts->read('post', 'post-link.md')->ok || $drafts->read('post', '../outside.md')->ok) {
    throw new RuntimeException('Tomos Post draft traversal and symlink paths must be rejected');
}
if (($drafts->delete('post', 'post-link.md')['ok'] ?? false) || !is_file($outside)) {
    throw new RuntimeException('Tomos Post draft deletion must reject symlinks without deleting outside content');
}

$published = (new \Tomos\PostUpload($config, $root))->publishDraft(
    'diary/draft-experiment.md',
    hash('sha256', $postDraftMarkdown),
    'test-session',
    str_repeat('a', 64)
);
if (!$published->ok) {
    throw new RuntimeException('Tomos Post draft publication failed: ' . implode('; ', $published->errors));
}
$publishedMarkdown = file_get_contents($postDraftPath);
if (!is_string($publishedMarkdown) || strpos($publishedMarkdown, 'draft: false') === false || !preg_match('/^date: \d{4}-\d{2}-\d{2}$/m', $publishedMarkdown) || strpos($publishedMarkdown, 'published: ') === false) {
    throw new RuntimeException('Tomos Post draft publication did not complete metadata safely');
}
foreach ($drafts->list() as $item) {
    if ($item->source === 'post' && $item->path === 'diary/draft-experiment.md') {
        throw new RuntimeException('published Tomos Post draft must leave the draft list');
    }
}

$deleteInbox = $inboxPath . DIRECTORY_SEPARATOR . 'delete-inbox.md';
file_put_contents($deleteInbox, "---\ntitle: Delete Inbox\ndraft: true\n---\n# Delete Inbox\n");
$deletePost = $content . DIRECTORY_SEPARATOR . 'diary' . DIRECTORY_SEPARATOR . 'delete-post.md';
file_put_contents($deletePost, "---\ntitle: Delete Post\ndraft: true\n---\n# Delete Post\n");
$deleteItems = $drafts->list();
if (count(array_filter($deleteItems, static fn (\Tomos\PostDraftItem $item): bool => $item->path === 'delete-inbox.md')) !== 1
    || count(array_filter($deleteItems, static fn (\Tomos\PostDraftItem $item): bool => $item->path === 'diary/delete-post.md')) !== 1
) {
    throw new RuntimeException('both draft sources must be deletable management items');
}
if (!($drafts->delete('inbox', 'delete-inbox.md')['ok'] ?? false) || is_file($deleteInbox)) {
    throw new RuntimeException('Inbox draft deletion failed');
}
if (!($drafts->delete('post', 'diary/delete-post.md')['ok'] ?? false) || is_file($deletePost)) {
    throw new RuntimeException('Tomos Post draft deletion failed');
}
$publishedFile = $content . DIRECTORY_SEPARATOR . 'published.md';
$publishedDelete = $drafts->delete('post', 'published.md');
if (!empty($publishedDelete['ok']) || !is_file($publishedFile)) {
    throw new RuntimeException('published content must not be deletable through draft deletion');
}
if (($drafts->delete('post', '../outside.md')['ok'] ?? false) || ($drafts->delete('inbox', '../outside.md')['ok'] ?? false)) {
    throw new RuntimeException('draft deletion traversal must be rejected');
}

$postSource = file_get_contents(dirname(__DIR__) . '/post/index.php');
if (!is_string($postSource)
    || strpos($postSource, '下書きとして保存') === false
    || strpos($postSource, '下書き投稿が完了しました。') === false
    || strpos($postSource, 'return confirm(') === false
    || strpos($postSource, 'name="draft_source"') === false
    || strpos($postSource, '["true", "1", "yes"]') === false
    || strpos($postSource, 'return false;') === false
    || strpos($postSource, 'name="action" value="publish_draft"') === false
    || strpos($postSource, 'name="draft_hash"') === false
    || strpos($postSource, "['publish_inbox', 'publish_draft', 'delete_draft']") === false
    || strpos($postSource, '下書きを更新しました。下書き一覧から確認できます。') === false
    || strpos($postSource, '下書きを新しい名前で保存しました。下書き一覧から確認できます。') === false
) {
    throw new RuntimeException('draft button, completion, and delete UI safeguards are missing');
}
if (strpos($postSource, '$isDraftSave = $result->isDraft;') === false
    || strpos($postSource, 'if ($result->operation === \'draft_create\')') === false
) {
    throw new RuntimeException('draft completion state must be explicit in the result');
}

echo "issue_58_inbox_preview_check: OK\n";
