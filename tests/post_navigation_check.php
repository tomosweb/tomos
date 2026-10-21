<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/post/index.php');
if (!is_string($source)) {
    throw new RuntimeException('post/index.php could not be read');
}

foreach (['upload\' => \'投稿', 'drafts\' => \'下書き', 'published\' => \'ページ一覧', 'settings\' => \'設定'] as $needle) {
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

$publishedBegin = strpos($source, 'function renderPublishedSection(');
$publishedEnd = strpos($source, 'function publishedPublicUrl(', $publishedBegin === false ? 0 : $publishedBegin);
$uploadBegin = strpos($source, 'function renderUploadForm(');
$uploadEnd = strpos($source, 'function renderBasicPagesSection(', $uploadBegin === false ? 0 : $uploadBegin);
$fixedBegin = strpos($source, 'function renderBasicPagesSection(');
$fixedEnd = strpos($source, 'function renderBasicPageDownloadButton(', $fixedBegin === false ? 0 : $fixedBegin);
if ($publishedBegin === false || $publishedEnd === false || $uploadBegin === false || $uploadEnd === false || $fixedBegin === false || $fixedEnd === false) {
    throw new RuntimeException('page list function boundaries are missing');
}
$publishedSource = substr($source, $publishedBegin, $publishedEnd - $publishedBegin);
$uploadSource = substr($source, $uploadBegin, $uploadEnd - $uploadBegin);
$fixedSource = substr($source, $fixedBegin, $fixedEnd - $fixedBegin);
foreach (['page-list-tabs', '公開済み記事</a>', '固定ページ</a>', 'aria-current="page"', 'view=fixed', 'renderBasicPagesSection($token, $config);'] as $needle) {
    if (strpos($publishedSource, $needle) === false) {
        throw new RuntimeException('page list tab navigation is missing: ' . $needle);
    }
}
if (strpos($uploadSource, 'renderBasicPagesSection($token, $config);') !== false) {
    throw new RuntimeException('fixed page panel must not remain below the upload form');
}
$fixedPosition = strpos($publishedSource, 'renderBasicPagesSection($token, $config);');
$searchPosition = strpos($publishedSource, 'searchPublishedPosts(');
if ($fixedPosition === false || $searchPosition === false || $fixedPosition > $searchPosition) {
    throw new RuntimeException('fixed page tab must return before published article search');
}
if (strpos($publishedSource, 'if (empty($_SESSION[\'tomos_post_authenticated\']))') > $fixedPosition) {
    throw new RuntimeException('fixed page tab must stay behind the existing Post authentication gate');
}
foreach (['Tomos\\PostBasicPage::HOME', 'Tomos\\PostBasicPage::ABOUT', 'renderBasicPageDownloadButton', 'post/?section=upload'] as $needle) {
    if (strpos($fixedSource, $needle) === false) {
        throw new RuntimeException('fixed page existing download and upload workflow is missing: ' . $needle);
    }
}
$uiCss = file_get_contents(dirname(__DIR__) . '/post/assets/tomos-post-ui.css');
if (!is_string($uiCss) || strpos($uiCss, '.page-list-tabs a[aria-current="page"]') === false) {
    throw new RuntimeException('page list selected tab style is missing');
}

foreach (['.result a,.editable-result a{overflow-wrap:anywhere;word-break:break-word}.editable-result{min-width:0}'] as $needle) {
    if (strpos($renderPage, $needle) === false) {
        throw new RuntimeException('published result URL wrapping styles are missing: ' . $needle);
    }
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
