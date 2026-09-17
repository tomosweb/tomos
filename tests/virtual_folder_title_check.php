<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Tomos\ThemeSettings;

function failVirtualFolderTitle(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function removeVirtualFolderTitleTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeVirtualFolderTitleTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/tomos-virtual-folder-title-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);

try {
    file_put_contents($root . '/theme-settings.php', <<<'PHP'
<?php
return [
    'folders' => [
        'news' => ['title' => '更新情報'],
        'research' => ['title' => '研究成果'],
        'empty' => ['title' => '   '],
        'non-string' => ['title' => 123],
    ],
];
PHP
    );

    $settings = new ThemeSettings($root);
    if ($settings->virtualFolderTitle('news') !== '更新情報') {
        failVirtualFolderTitle('configured news title must be used');
    }
    if ($settings->virtualFolderTitle('research') !== '研究成果') {
        failVirtualFolderTitle('configured research title must be used');
    }
    if ($settings->virtualFolderTitle('blog') !== 'blog') {
        failVirtualFolderTitle('missing folder title must fall back to basename');
    }
    if ($settings->virtualFolderTitle('empty') !== 'empty') {
        failVirtualFolderTitle('empty title must fall back to basename');
    }
    if ($settings->virtualFolderTitle('non-string') !== 'non-string') {
        failVirtualFolderTitle('non-string title must fall back to basename');
    }

    file_put_contents($root . '/theme-settings.php', "<?php\nreturn ['folders' => ['news' => ['title' => '壊れた設定']]];\n");
    $settingsWithoutCache = new ThemeSettings($root);
    if ($settingsWithoutCache->virtualFolderTitle('blog') !== 'blog') {
        failVirtualFolderTitle('unconfigured folder must remain basename fallback');
    }

    echo "virtual_folder_title_check: OK\n";
} finally {
    removeVirtualFolderTitleTree($root);
}
