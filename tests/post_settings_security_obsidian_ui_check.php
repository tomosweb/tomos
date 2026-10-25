<?php

declare(strict_types=1);
$root = dirname(__DIR__);
$post = (string) file_get_contents($root . '/post/index.php');
$security = (string) file_get_contents($root . '/post/security/index.php');
$obsidian = (string) file_get_contents($root . '/post/obsidian/index.php');
$returnTo = (string) file_get_contents($root . '/core/PostAuthReturnTo.php');
function assertSettings(bool $pass, string $message): void {
    if (!$pass) throw new RuntimeException($message);
}
assertSettings(strpos($post, "['Tomos', [") < strpos($post, "['サイト', ["), 'Tomos category must be first');
assertSettings(strpos($post, "['サイト', [") < strpos($post, "['連携', ["), 'Integration category should follow Site');
assertSettings(strpos($post, "'Tomos Update',") < strpos($post, "'セキュリティ',"), 'Tomos Update must be before Security');
assertSettings(strpos($post, "'Obsidian',") !== false, 'Obsidian must appear under integration');
assertSettings(strpos($security, '($obsidianView ? \'/post/obsidian/\' : \'/post/security/\')') !== false, 'Passkey reauth must return to initiating screen');
assertSettings(strpos($security, '<?php if ($obsidianView && $authenticated): ?>') !== false, 'Obsidian token issuance must have dedicated view');
assertSettings(strpos($security, '&& !$obsidianView') !== false, 'Old Security token requests must not silently invalidate tokens');
assertSettings(strpos($obsidian, '$_GET[\'view\'] = \'obsidian\';') !== false, 'Dedicated Obsidian route must use shared security controller');
assertSettings(strpos($returnTo, "'/post/obsidian/' => '/post/obsidian/'") !== false, 'Obsidian auth return must be allowed');
foreach (['id="generate-button"','id="show-button"','id="copy-button"','window.crypto.getRandomValues','navigator.clipboard.writeText','パスワード管理アプリなどへ保存してください。','1文字以上・4096バイト以内'] as $needle) {
    assertSettings(strpos($security, $needle) !== false, 'Security passphrase input lacks ' . $needle);
}
foreach (['core/required-installed-files.txt','tools/required-distribution-files.txt','tools/required-source-files.txt'] as $file) {
    assertSettings(strpos((string)file_get_contents($root . '/' . $file), "post/obsidian/index.php") !== false, $file . ' lacks Obsidian route');
}
echo "post_settings_security_obsidian_ui_check: PASS\n";
