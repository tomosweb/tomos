<?php

declare(strict_types=1);

function assertIssue54(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$pageTemplate = file_get_contents($root . '/themes/tomos-blog/templates/page.html');
$layoutTemplate = file_get_contents($root . '/themes/tomos-blog/templates/layout.html');
$listTemplate = file_get_contents($root . '/themes/tomos-blog/templates/list.html');

assertIssue54(is_string($pageTemplate), 'tomos-blog page template could not be read');
assertIssue54(is_string($layoutTemplate), 'tomos-blog layout template could not be read');
assertIssue54(is_string($listTemplate), 'tomos-blog list template could not be read');

assertIssue54(strpos($pageTemplate, '<h1 class="page-title">{{ page.title }}</h1>') !== false, 'article title was removed');
assertIssue54(strpos($pageTemplate, '{{{ page.meta_html }}}') !== false, 'article metadata was removed');
assertIssue54(strpos($pageTemplate, '{{{ page.content }}}') !== false, 'article content was removed');
assertIssue54(strpos($pageTemplate, '<p class="page-description">{{ page.description }}</p>') === false, 'article detail still displays page.description');
assertIssue54(strpos($layoutTemplate, 'name="description" content="{{ page.description }}"') !== false, 'meta description was changed');
assertIssue54(strpos($layoutTemplate, 'property="og:description" content="{{ page.description }}"') !== false, 'OGP description was changed');
assertIssue54(strpos($listTemplate, '<p class="page-description">{{ page.description }}</p>') !== false, 'list description was changed');

echo "issue_54_tomos_blog_description_check: OK\n";
