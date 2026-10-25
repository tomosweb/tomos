<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/core/PostRateLimiter.php';
if (!function_exists('pcntl_fork')) { echo "SKIP: pcntl_fork unavailable\n"; exit(2); }
$tmp=sys_get_temp_dir().'/tomos-rate-race-'.bin2hex(random_bytes(6));mkdir($tmp,0700,true);
$config=['paths'=>['cache_dir'=>$tmp.'/cache'],'security'=>['rate_limit_salt'=>'test-only']];
$pids=[]; $parentPid=getmypid();
try {
    for ($i=0;$i<12;$i++) {
        $pid=pcntl_fork();if ($pid<0) throw new RuntimeException('fork failed');
        if ($pid===0) {
            while (!is_file($tmp.'/go')) usleep(1000);
            try {
            $limiter=new Tomos\PostRateLimiter($config,$tmp,'127.0.0.1',1786000000);
            for ($n=0;$n<20;$n++) $limiter->recordFailure();
            exit(0);
            } catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL);exit(5); }
        }
        $pids[]=$pid;
    }
    touch($tmp.'/go');
    foreach ($pids as $pid) { do { $wait=pcntl_waitpid($pid,$status); } while ($wait===-1 && pcntl_get_last_error()===PCNTL_EINTR); if ($wait!==$pid) throw new RuntimeException('waitpid failed'); if (pcntl_wexitstatus($status)!==0) throw new RuntimeException('child failed'); }
    $files=glob($tmp.'/cache/security/post-rate-limit/*.json') ?: [];
    $state=json_decode((string)file_get_contents($files[0] ?? ''),true);
    if (count($state['failures'] ?? [])!==240) throw new RuntimeException('concurrent failures lost: '.count($state['failures'] ?? []));
    $limiter=new Tomos\PostRateLimiter($config,$tmp,'127.0.0.1',1786000001);
    if ($limiter->checkAuthAllowed()->allowed) throw new RuntimeException('concurrent failures must block');
    echo "post_rate_limiter_concurrency_check: PASS (12 processes, 240 failures retained)\n";
} finally {
    if (getmypid()!==$parentPid) exit(5);
    foreach ($pids as $pid) { do { $wait=pcntl_waitpid($pid,$status); } while ($wait===-1 && pcntl_get_last_error()===PCNTL_EINTR); }
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) $p->isDir() ? rmdir($p->getPathname()) : unlink($p->getPathname());rmdir($tmp);
}
