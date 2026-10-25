<?php
// Explicit artifact fixture arguments; intentionally excluded from *_check.php glob.
declare(strict_types=1);
if ($argc!==3) { fwrite(STDERR,"Usage: php test.php isolated-root test-update.zip\n");exit(2); }
$root=$argv[1];$zip=$argv[2];
spl_autoload_register(static function(string $class) use ($root): void {
    if (strpos($class,'Tomos\\')!==0) return;
    $p=$root.'/core/'.substr($class,6).'.php';if (is_file($p)) require_once $p;
});
$config="<?php return ['security'=>['post_login_mode'=>'passkey_only','post_auth_epoch'=>'fixture-epoch','inbox_api_token_hash'=>'fixture-token']];\n";
file_put_contents($root.'/config.php',$config);
$service=new Tomos\UpdateService($root);
$staged=$service->stageDownloadedPackage($zip,'fixture-owner','1.1.12-pr114.1','1.1.12-pr114.2');
$result=$service->apply((string)$staged['id'],'fixture-owner');
if (empty($result['ok'])) throw new RuntimeException('fixture update failed');
$finalizer=new Tomos\UpdaterSelfUpdate($root);
if (!$finalizer->hasPendingUpdate()) throw new RuntimeException('fixture must exercise updater finalization');
$final=$finalizer->apply();
if (empty($final['recording_ok']) || $finalizer->hasPendingUpdate()) throw new RuntimeException('fixture finalize failed');
if (trim((string)file_get_contents($root.'/VERSION'))!=='1.1.12-pr114.2') throw new RuntimeException('fixture version mismatch');
if (file_get_contents($root.'/config.php')!==$config) throw new RuntimeException('fixture update mutated user config');
echo "post_security_validation_artifacts: PASS (signed ZIP, apply, finalize, config preservation; no HTTP/browser)\n";
