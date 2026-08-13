<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/post/index.php');
if (!is_string($source)) {
    throw new RuntimeException('post/index.php could not be read');
}

foreach (['upload\' => \'投稿', 'drafts\' => \'下書き', 'published\' => \'公開済み', 'settings\' => \'設定'] as $needle) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException('primary navigation item is missing: ' . $needle);
    }
}

$renderPageStart = strpos($source, 'function renderPage(');
$renderPageEnd = strpos($source, 'function renderSectionNav(', $renderPageStart === false ? 0 : $renderPageStart);
if ($renderPageStart === false || $renderPageEnd === false) {
    throw new RuntimeException('renderPage boundaries could not be located');
}
$renderPage = substr($source, $renderPageStart, $renderPageEnd - $renderPageStart);
if (strpos($renderPage, 'renderEditableMarkdownSection(') !== false) {
    throw new RuntimeException('editable Markdown search must not be a primary page block');
}
if (strpos($renderPage, 'renderSettingsHomeSection(') === false) {
    throw new RuntimeException('settings home must be rendered as a separate entry page');
}
if (strpos($source, "return 'published';") === false || strpos($source, "return 'drafts';") === false) {
    throw new RuntimeException('section compatibility routing is missing');
}

foreach (["'?section=settings'", "publicUrl('/post/'"] as $needle) {
    $sources = [
        file_get_contents(dirname(__DIR__) . '/post/theme/index.php'),
        file_get_contents(dirname(__DIR__) . '/post/theme/confirm/index.php'),
        file_get_contents(dirname(__DIR__) . '/post/security/index.php'),
        file_get_contents(dirname(__DIR__) . '/update/index.php'),
        file_get_contents(dirname(__DIR__) . '/post/update-finalize/index.php'),
    ];
    $joined = implode("\n", array_filter($sources, 'is_string'));
    if (strpos($joined, $needle) === false) {
        throw new RuntimeException('settings return target is missing: ' . $needle);
    }
}

echo "post_navigation_check: OK\n";
