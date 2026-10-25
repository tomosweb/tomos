<?php
// Real processes and flock; no network, browser or WebAuthn simulation claim.
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/PasskeyEnvironment.php';
require_once dirname(__DIR__) . '/core/PasskeyCredentialStore.php';
require_once dirname(__DIR__) . '/core/PasskeyManagementService.php';
use Tomos\PasskeyEnvironment;
use Tomos\PasskeyCredentialStore;
use Tomos\PasskeyManagementService;
if (!function_exists('pcntl_fork')) { echo "SKIP: pcntl_fork unavailable\n"; exit(2); }
$tmp = sys_get_temp_dir() . '/tomos-delete-race-' . bin2hex(random_bytes(6));
$config = ['site'=>['url'=>'https://example.com'], 'paths'=>['storage_dir'=>$tmp.'/storage'],
    'security'=>['post_login_mode'=>'passkey_only']];
$env = new PasskeyEnvironment($config, ['HTTPS'=>'on','SERVER_PORT'=>443], true, '8.2.0', true, true);
$store = new PasskeyCredentialStore($config, $tmp);
function waitRace(int $pid): int {
    do { $wait=pcntl_waitpid($pid,$status); } while ($wait===-1 && pcntl_get_last_error()===PCNTL_EINTR);
    if ($wait!==$pid) throw new RuntimeException('waitpid failed');
    return $status;
}
function demand(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function seed(PasskeyCredentialStore $store, string $id): void {
    demand($store->save(['credential_id'=>$id,'public_key'=>'KEY','rp_id'=>'example.com',
        'created_at'=>1786000000,'label'=>$id]), 'seed credential');
}
function cleanRace(string $path): void {
    if (is_dir($path)) { foreach (scandir($path) ?: [] as $n) if ($n!=='.' && $n!=='..') cleanRace($path.'/'.$n); rmdir($path); }
    elseif (file_exists($path)) unlink($path);
}
try {
    for ($round=0; $round<12; $round++) {
        foreach ($store->all() as $record) $store->delete($record['credential_id']);
        seed($store,'first'); seed($store,'second');
        $pids=[];
        foreach (['first','second'] as $id) {
            $pid=pcntl_fork(); demand($pid>=0,'fork');
            if ($pid===0) {
                try { (new PasskeyManagementService($env,$store,$config))->delete($id); exit(0); }
                catch (RuntimeException $e) { exit(3); }
            }
            $pids[]=$pid;
        }
        $codes=[];
        foreach ($pids as $pid) { $status=waitRace($pid); $codes[]=pcntl_wexitstatus($status); }
        sort($codes);
        demand($codes===[0,3] && count($store->all())===1,'two simultaneous deletes must retain exactly one credential');
    }
    // A delete request loaded in both mode waits while a different request changes mode.
    foreach ($store->all() as $record) $store->delete($record['credential_id']);
    seed($store,'last');
    $both=$config; $both['security']['post_login_mode']='both';
    file_put_contents($tmp.'/config.php','<?php return '.var_export($both,true).';');
    $lock=fopen($store->storageDirectory().'/.management.lock','c'); flock($lock,LOCK_EX);
    $pid=pcntl_fork(); demand($pid>=0,'fork stale mode');
    if ($pid===0) {
        fclose($lock);
        try { (new PasskeyManagementService($env,$store,$both))->delete('last'); exit(4); }
        catch (RuntimeException $e) { exit(0); }
    }
    usleep(100000);
    file_put_contents($tmp.'/config.php','<?php return '.var_export($config,true).';');
    flock($lock,LOCK_UN); fclose($lock); $status=waitRace($pid);
    demand(pcntl_wexitstatus($status)===0 && count($store->all())===1,'waiting delete must reload current mode');
    $unknown=$config; $unknown['security']['post_login_mode']='invalid';
    file_put_contents($tmp.'/config.php','<?php return '.var_export($unknown,true).';');
    $refused=false;
    try { (new PasskeyManagementService($env,$store,$unknown))->delete('last'); } catch (RuntimeException $e) { $refused=true; }
    demand($refused,'unknown mode must protect last credential');
    echo "passkey_management_concurrency_check: PASS (12 paired deletes, stale-mode race, fail-closed mode)\n";
} finally { cleanRace($tmp); }
