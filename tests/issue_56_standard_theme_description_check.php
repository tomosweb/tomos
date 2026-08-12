<?php

declare(strict_types=1);

function assertIssue56(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$themes = ['tomos-blog', 'tomos-90s', 'tomos-dark', 'tomos-journal', 'tomos-minimal', 'tomos-note'];
$description = '<p class="page-description">{{ page.description }}</p>';

foreach ($themes as $theme) {
    $pageTemplate = file_get_contents($root . '/themes/' . $theme . '/templates/page.html');
    $layoutTemplate = file_get_contents($root . '/themes/' . $theme . '/templates/layout.html');

    assertIssue56(is_string($pageTemplate), $theme . ' page template could not be read');
    assertIssue56(is_string($layoutTemplate), $theme . ' layout template could not be read');
    assertIssue56(strpos($pageTemplate, '<h1 class="page-title">{{ page.title }}</h1>') !== false, $theme . ' article title was removed');
    assertIssue56(strpos($pageTemplate, '{{{ page.meta_html }}}') !== false, $theme . ' article metadata was removed');
    assertIssue56(strpos($pageTemplate, '{{{ page.content }}}') !== false, $theme . ' article content was removed');
    assertIssue56(strpos($pageTemplate, $description) === false, $theme . ' article detail still displays page.description');
    assertIssue56(strpos($layoutTemplate, 'name="description" content="{{ page.description }}"') !== false, $theme . ' meta description was changed');
    assertIssue56(strpos($layoutTemplate, 'property="og:description" content="{{ page.description }}"') !== false, $theme . ' OGP description was changed');
}

echo "issue_56_standard_theme_description_check: OK\n";
