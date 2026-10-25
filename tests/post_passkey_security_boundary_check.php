<?php
// Execute actual PHP endpoints in isolated CLI processes (not an HTTP/browser test).
declare(strict_types=1);
$source=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/tomos-auth-boundary-'.bin2hex(random_bytes(6));
$root=$tmp.'/site'; mkdir($root,0700,true); mkdir($tmp.'/sessions',0700);
function copyBoundary(string $from,string $to): void {
    if (is_dir($from)) { if (!is_dir($to)) mkdir($to,0700,true); foreach(scandir($from) ?: [] as $n) if ($n!=='.'&&$n!=='..') copyBoundary($from.'/'.$n,$to.'/'.$n); }
    else copy($from,$to);
}
function removeBoundary(string $p): void {
    if (is_dir($p)) { foreach(scandir($p) ?: [] as $n) if ($n!=='.'&&$n!=='..') removeBoundary($p.'/'.$n); rmdir($p); }
    elseif (file_exists($p)) unlink($p);
}
function boundaryAssert(bool $yes,string $why): void { if (!$yes) throw new RuntimeException($why); }
foreach (['core','post','update','themes','config.sample.php'] as $p) copyBoundary($source.'/'.$p,$root.'/'.$p);
foreach (['content','cache','storage','storage/update-tmp','storage/update-backups','storage/update-logs'] as $p) if (!is_dir($root.'/'.$p)) mkdir($root.'/'.$p,0700,true);
$config=require $root.'/config.sample.php';
$config['site']['url']='https://example.com/sub';
$config['site']['base_path']=$config['site']['public_base_path']='/sub';
foreach (['content_dir'=>'content','cache_dir'=>'cache','storage_dir'=>'storage','theme_dir'=>'themes'] as $k=>$p) $config['paths'][$k]=$root.'/'.$p;
$config['security']['post_password_hash']=password_hash('boundary-pass',PASSWORD_DEFAULT);
$config['security']['inbox_api_token_hash']=password_hash('api-stays-independent',PASSWORD_DEFAULT);
$config['security']['post_login_mode']='passkey_only';
$config['security']['post_auth_epoch']='current';
$config['features']['post']=true;
$config['setup_completed']=true;
function saveBoundaryConfig(array $c): void { global $root; file_put_contents($root.'/config.php','<?php return '.var_export($c,true).';'); }
saveBoundaryConfig($config);
file_put_contents($root.'/content/2026-10-25-test.md',"---\ntitle: Boundary\ndate: 2026-10-25\n---\nIndependent draft handoff body.\n");
$runner=$tmp.'/runner.php';
file_put_contents($runner, <<<'RUNNER'
<?php
$r=json_decode(file_get_contents($argv[1]),true);
ini_set('session.save_path',$r['sessions']);
ini_set('session.use_cookies','0');
session_id($r['id']);session_start();$_SESSION=$r['session'];session_write_close();
$_SERVER=['REQUEST_METHOD'=>$r['method'],'HTTPS'=>'on','SERVER_PORT'=>443,'HTTP_HOST'=>'example.com',
 'SERVER_NAME'=>'example.com','REMOTE_ADDR'=>'127.0.0.1','REQUEST_URI'=>'/sub/'.$r['path'],
 'SCRIPT_NAME'=>'/sub/'.$r['path'],'CONTENT_TYPE'=>'application/x-www-form-urlencoded'];
$_SERVER=array_merge($_SERVER,$r['server']);
$_GET=$r['get'];$_POST=$r['post'];$_COOKIE=$r['cookies'];$_FILES=[];
register_shutdown_function(static function () use ($r): void {
 file_put_contents($r['result'],json_encode(['status'=>http_response_code() ?: 200,'session'=>$_SESSION ?? [],'sid'=>session_id()]));
});
require $r['root'].'/'.$r['path'];
RUNNER
);
function endpointBoundary(string $path,array $session=[],array $post=[],array $get=[],array $cookies=[],string $method='POST',array $server=[]): array {
 global $tmp,$root,$runner;
 $id=bin2hex(random_bytes(12));$input=$tmp.'/'.$id.'.json';$result=$tmp.'/'.$id.'.result';
 $session+=['tomos_post_token'=>'csrf','tomos_update_token'=>'update-csrf','tomos_updater_finalize_token'=>'final-csrf'];
 file_put_contents($input,json_encode(['root'=>$root,'sessions'=>$tmp.'/sessions','id'=>$id,'path'=>$path,
    'session'=>$session,'post'=>$post,'get'=>$get,'cookies'=>$cookies,'method'=>$method,'result'=>$result,'server'=>$server]));
 $p=proc_open([PHP_BINARY,$runner,$input],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 boundaryAssert(is_resource($p),'endpoint process');fclose($pipes[0]);
 $body=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
 boundaryAssert($code===0,$path.' crashed: '.$err.' '.$body);
 $r=json_decode((string)file_get_contents($result),true);$r['body']=$body;$r['original_sid']=$id;
 boundaryAssert(strpos($err,'Warning:')===false,$path.' warning: '.$err);
 return $r;
}
$legacy=['tomos_post_authenticated'=>true];
$stale=['tomos_post_authenticated'=>true,'tomos_post_auth_method'=>'passkey','tomos_post_auth_epoch'=>'old',
 'tomos_passkey_authenticated_at'=>time()];
$passkey=['tomos_post_authenticated'=>true,'tomos_post_auth_method'=>'passkey','tomos_post_auth_epoch'=>'current',
 'tomos_passkey_authenticated_at'=>time()];
try {
 $updatePage=endpointBoundary('update/index.php',[],[],[],[],'GET');
 boundaryAssert(strpos($updatePage['body'],'return_to=%2Fupdate%2F')!==false,'Update GET renders passkey return link without warnings');
 $loginPage=endpointBoundary('post/passkey/login/index.php',[],[],['return_to'=>'/post/security/'],[],'GET');
 boundaryAssert($loginPage['status']===200,'passkey login entry renders');
 boundaryAssert(strpos($loginPage['body'],'<title>Tomos Postを開く</title>')!==false,'login document title is Tomos Post');
 boundaryAssert(strpos($loginPage['body'],'<h1>Tomos Postを開く</h1>')!==false,'login heading shows user goal');
 boundaryAssert(strpos($loginPage['body'],'登録済みのパスキーで認証します。')!==false,'login explanation is concise');
 boundaryAssert(strpos($loginPage['body'],'登録済み:')===false,'login hides credential count');
 boundaryAssert(strpos($loginPage['body'],'セキュリティへ戻る')===false,'login hides inappropriate return link');
 boundaryAssert(strpos($loginPage['body'],'合言葉を忘れた場合')!==false,'login keeps recovery link');
 boundaryAssert(strpos($loginPage['body'],'管理用合言葉で開く')===false,'passkey-only login hides password fallback');
 boundaryAssert(strpos($loginPage['body'],'/sub/post/security/')!==false,'reauthentication return_to remains functional');
 foreach (['post/index.php'=>'auth_gate_login','post/security/index.php'=>'passphrase_auth',
    'post/passkey/manage/index.php'=>'passphrase_auth'] as $path=>$action) {
  foreach ([[],$legacy,$stale] as $session) {
   $r=endpointBoundary($path,$session,['_token'=>'csrf','action'=>$action,'post_password'=>'boundary-pass']);
   boundaryAssert($r['status']===403 && empty($r['session']['tomos_post_authenticated']),$path.' passphrase bypass');
  }
 }
 $r=endpointBoundary('post/index.php',[],[],[],[],'GET');
 boundaryAssert($r['status']===302,'passkey-only entry redirect');
 foreach (['options','complete','authorize'] as $api) {
  foreach ([[],$legacy,$stale,['tomos_passkey_registration_authorized_until'=>time()+300,
     'tomos_passkey_registration_epoch'=>'old']] as $session) {
   $r=endpointBoundary('post/passkey/register/index.php',$session,['_token'=>'csrf','post_password'=>'boundary-pass'],['api'=>$api]);
   boundaryAssert(empty($r['session']['tomos_passkey_registration_authorized_until']) && $r['status']>=400,'registration stale grant '.$api);
  }
 }
 $r=endpointBoundary('post/passkey/register/index.php',$passkey,['_token'=>'csrf'],['api'=>'authorize']);
 boundaryAssert($r['status']===200 && !empty($r['session']['tomos_passkey_registration_authorized_until']),'fresh passkey registration authorization');
 $token=str_repeat('a',64); $hash=hash('sha256',$token);
 $rememberDir=$root.'/cache/security/post-auth'; if (!is_dir($rememberDir)) mkdir($rememberDir,0700,true);
 file_put_contents($rememberDir.'/'.$hash.'.json',json_encode(['token_hash'=>$hash,'created_at'=>time(),'expires_at'=>time()+300,'auth_epoch'=>'old']));
 foreach (['post/theme/index.php','post/theme/confirm/index.php','post/theme/add/index.php',
    'post/theme/add/confirm/index.php','post/theme/delete/index.php','post/navigation/index.php',
    'post/analytics/index.php','post/site-settings.php'] as $path) {
  foreach ([$legacy,$stale,[]] as $session) {
   $r=endpointBoundary($path,$session,['_token'=>'csrf','post_password'=>'boundary-pass'],[],['tomos_post_remember'=>$token]);
   boundaryAssert($r['status']===302 && empty($r['session']['tomos_post_authenticated']),$path.' stale session or remember bypass');
  }
 }
 $r=endpointBoundary('post/inbox/api/index.php',[],[],[],[],'GET',['HTTP_X_TOMOS_TOKEN'=>'api-stays-independent']);
 boundaryAssert($r['status']===200 && strpos($r['body'],'接続できます')!==false,'API token remains independent in passkey-only mode');
 $r=endpointBoundary('post/inbox/api/index.php',[],[],[],[],'GET',['HTTP_X_TOMOS_TOKEN'=>'boundary-pass']);
 boundaryAssert($r['status']===401,'passphrase cannot replace API token');
 foreach ([$legacy,$stale] as $session) {
  $r=endpointBoundary('post/write-handoff.php',$session,['_token'=>'csrf','content_path'=>'2026-10-25-test.md']);
  boundaryAssert($r['status']===403,'Write stale authentication rejected');
  $r=endpointBoundary('post/inbox/image/index.php',$session,[],['file'=>'draft.md','image'=>'secret.png'],[],'GET');
  boundaryAssert($r['status']===404 && empty($r['session']['tomos_post_authenticated']),'preview stale session rejected');
  foreach (['post/social/bluesky/index.php','post/social/bluesky/callback/index.php'] as $path) {
   $r=endpointBoundary($path,$session,[],[],[],'GET');
   boundaryAssert($r['status']===302 && empty($r['session']['tomos_post_authenticated']),$path.' stale session rejected');
  }
  $r=endpointBoundary('post/index.php',$session,['_token'=>'csrf','post_password'=>'boundary-pass'],['post_api'=>'start']);
  boundaryAssert($r['status']===403,'image upload API stale session rejected');
  foreach (['theme_upload','save_settings','publish'] as $action) {
   $r=endpointBoundary('post/index.php',$session,['_token'=>'csrf','action'=>$action,'post_password'=>'boundary-pass']);
   boundaryAssert(empty($r['session']['tomos_post_authenticated']),'Post direct action '.$action);
  }
  $r=endpointBoundary('update/index.php',$session,['_token'=>'update-csrf','action'=>'inspect','post_password'=>'boundary-pass']);
  boundaryAssert(empty($r['session']['tomos_post_authenticated']) && strpos($r['body'],'パスキー認証を行ってください')!==false,'Update passphrase bypass');
  $r=endpointBoundary('post/update-finalize/index.php',$session,['_token'=>'final-csrf','post_password'=>'boundary-pass']);
  boundaryAssert(empty($r['session']['tomos_post_authenticated']) && strpos($r['body'],'パスキー認証を行ってください')!==false,'finalize passphrase bypass');
 }
 $r=endpointBoundary('post/write-handoff.php',$passkey,['_token'=>'csrf','content_path'=>'2026-10-25-test.md']);
 boundaryAssert($r['status']===200 && strpos($r['body'],'Independent draft handoff body.')!==false,'authenticated Write handoff regression');
 $apiHash=$config['security']['inbox_api_token_hash'];
 $r=endpointBoundary('post/security/index.php',$passkey,['_token'=>'csrf','action'=>'change_passphrase',
     'new_passphrase'=>'replacement-pass','confirm_passphrase'=>'replacement-pass']);
 $changed=require $root.'/config.php';
 boundaryAssert(($changed['security']['post_auth_epoch'] ?? '')!=='current','password rotation');
 boundaryAssert($changed['security']['inbox_api_token_hash']===$apiHash,'password rotation preserves API token');
 boundaryAssert(empty($r['session']['tomos_post_authenticated']),'password rotation logs current session out');
 saveBoundaryConfig($config);
 $r=endpointBoundary('post/security/index.php',$passkey,['_token'=>'csrf','action'=>'change_login_mode','login_mode'=>'both']);
 $changed=require $root.'/config.php';
 boundaryAssert($changed['security']['post_login_mode']==='both' && $changed['security']['post_auth_epoch']!=='current','mode rotation');
 saveBoundaryConfig($config);
 $expired=$passkey;$expired['tomos_passkey_authenticated_at']=time()-301;
 $r=endpointBoundary('post/security/index.php',$expired,['_token'=>'csrf','action'=>'change_login_mode','login_mode'=>'both']);
 $changed=require $root.'/config.php';boundaryAssert($changed['security']['post_login_mode']==='passkey_only','expired reauthentication rejected');
 // Recovery requires the explicit server-side enable file and preserves API credentials.
 $r=endpointBoundary('post/reset/index.php',[],['_token'=>'reset-csrf']);
 $unchanged=require $root.'/config.php';boundaryAssert($unchanged['security']['post_login_mode']==='passkey_only','recovery disabled without enable file');
 file_put_contents($root.'/post-reset.enable','test-only');
 $r=endpointBoundary('post/reset/index.php',['tomos_post_reset_token'=>'reset-csrf'],['_token'=>'reset-csrf']);
 $recovered=require $root.'/config.php';
 boundaryAssert($recovered['security']['post_login_mode']==='both' && $recovered['security']['post_auth_epoch']!=='current','server-owner recovery rotates generation and restores both mode');
 boundaryAssert($recovered['security']['inbox_api_token_hash']===$apiHash,'recovery preserves API credentials');
 unlink($root.'/post-reset.enable');saveBoundaryConfig($config);
 if (is_dir($root.'/core/webauthn/vendor')) {
  rename($root.'/core/webauthn/vendor',$tmp.'/vendor-off');
  $r=endpointBoundary('post/passkey/login/index.php',[],[],[],[],'GET');
  boundaryAssert(strpos($r['body'],'管理用合言葉で開く')===false,'missing runtime must not offer passphrase fallback');
  rename($tmp.'/vendor-off',$root.'/core/webauthn/vendor');
 }
 // Normal-mode login must continue to work and rotate the PHP session ID.
 $both=$config;$both['security']['post_login_mode']='both';saveBoundaryConfig($both);
 $bothLoginPage=endpointBoundary('post/passkey/login/index.php',[],[],[],[],'GET');
 boundaryAssert(strpos($bothLoginPage['body'],'<h1>Tomos Postを開く</h1>')!==false,'both mode keeps main login heading');
 boundaryAssert(strpos($bothLoginPage['body'],'管理用合言葉で開く')!==false,'both mode still allows password login');
 $r=endpointBoundary('post/index.php',[],['_token'=>'csrf','action'=>'auth_gate_login','post_password'=>'boundary-pass']);
 boundaryAssert(!empty($r['session']['tomos_post_authenticated']) && $r['sid']!==$r['original_sid'],'normal login and session rotation');
 $securityPage=endpointBoundary('post/security/index.php',$r['session'],[],[],[],'GET');
 if (PHP_VERSION_ID < 80000) {
  boundaryAssert(strpos($securityPage['body'],'ログイン方法を変更できません')!==false,'PHP 7.4 explains unavailable login settings');
  boundaryAssert(strpos($securityPage['body'],'パスキーで再認証')===false,'PHP 7.4 hides unavailable passkey reauthentication');
  boundaryAssert(strpos($securityPage['body'],'name="login_mode"')===false,'PHP 7.4 hides login mode controls');
  boundaryAssert(strpos($securityPage['body'],'name="current_passphrase"')!==false && strpos($securityPage['body'],'name="new_passphrase"')!==false,'PHP 7.4 retains passphrase settings');
  boundaryAssert(strpos($securityPage['body'],'value="issue_inbox_api_token"')!==false,'PHP 7.4 retains posting token settings');
 } else {
  boundaryAssert(strpos($securityPage['body'],'パスキーで再認証')!==false,'supported environment retains passkey reauthentication');
 }
 $r=endpointBoundary('post/security/index.php',$r['session'],['_token'=>$r['session']['tomos_post_token'],'action'=>'issue_inbox_api_token']);
 $changed=require $root.'/config.php';boundaryAssert($changed['security']['inbox_api_token_hash']===$apiHash,'token issue requires reauthentication even without passkey');
 for ($i=0;$i<5;$i++) {
  $r=endpointBoundary('post/passkey/register/index.php',[],['_token'=>'csrf','post_password'=>'wrong'],['api'=>'authorize'],[],'POST',['REMOTE_ADDR'=>'127.0.0.2']);
  boundaryAssert($r['status']===403,'registration wrong passphrase denied');
 }
 $r=endpointBoundary('post/passkey/register/index.php',[],['_token'=>'csrf','post_password'=>'boundary-pass'],['api'=>'authorize'],[],'POST',['REMOTE_ADDR'=>'127.0.0.2']);
 boundaryAssert($r['status']===429,'registration correct passphrase cannot bypass active rate limit');
 echo "post_passkey_security_boundary_check: PASS (real endpoint subprocesses; HTTP/Safari/Sakura NOT tested)\n";
} finally { removeBoundary($tmp); }
