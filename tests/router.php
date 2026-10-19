<?php
require_once __DIR__.'/paths.php';
// Router exclusivo del servidor local de pruebas. No es un endpoint de producción.
if(PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
define('MUSICMAD_TEST_RUN',true);
$root=dirname(__DIR__); $env=parse_ini_file(musicmadQaRoot().'/.env',false,INI_SCANNER_RAW);
define('DB_HOST','127.0.0.1;port=3308');define('DB_NAME','musicmad_qa');define('DB_USER','root');define('DB_PASS',$env['MM_TEST_DB_PASS']);
define('APP_UPLOAD_ROOT',musicmadQaRoot().'/uploads');
foreach(['MAIL_HOST','MAIL_USER','MAIL_PASS','MAIL_FROM','MAIL_PORT','VAPID_PUBLIC_KEY','VAPID_PRIVATE_KEY','VAPID_SUBJECT'] as $testSetting)putenv('MM_'.$testSetting);
putenv('MM_APP_URL=http://127.0.0.1:'.(int)($_SERVER['SERVER_PORT']??8765));
putenv('MM_PUBLIC_URL=https://127.0.0.1:'.(int)($_SERVER['SERVER_PORT']??8765));
putenv('MM_ENC_KEY='.str_repeat('qa-test-key-',6));putenv('MM_ENC_KEY_LEGACY_UNTIL=0');putenv('MM_JWT_SECRET='.str_repeat('qa-jwt-key-',6));
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('#^/(config|includes|vendor|storage|bin|tests|\\.github|\\.tests-tmp)(/|$)#',$path)||str_contains($path,'..')||str_contains($path,"\0")){http_response_code(404);exit;}
if($path==='/acciones-pendientes.php' && is_file(musicmadQaRoot().'/qa-actions-outage')){http_response_code(503);header('Content-Type: application/json');header('Cache-Control: no-store');echo '{"ok":false,"mensaje":"Interrupción temporal simulada en QA."}';exit;}
if(str_starts_with($path,'/uploads/')){
 if(preg_match('#^/uploads/(audio|videos|partituras)/#',$path)){http_response_code(403);exit;}
 $file=APP_UPLOAD_ROOT.substr($path,strlen('/uploads'));
 if(!is_file($file)||!preg_match('/\\.(jpg|png|webp)$/',$file)){http_response_code(404);exit;}
 header('Content-Type: '.(new finfo(FILEINFO_MIME_TYPE))->file($file));readfile($file);exit;
}
if(preg_match('#^/api/v1/([a-z_-]+)$#',$path,$m)){$_GET['resource']=$m[1];require $root.'/api/v1/index.php';exit;}
if(preg_match('#^/@([a-z][a-z0-9_]{2,29})/?$#D',$path,$m)){$_GET['usuario']=$m[1];$path='/portafolio.php';}
if($path==='/')$path='/index.php';
if($path==='/qa-media.html'){header('Content-Type: text/html; charset=utf-8');readfile($root.'/tests/media-fixture.html');exit;}
if($path==='/qa-media.js'){header('Content-Type: application/javascript');readfile($root.'/tests/media-fixture.js');exit;}
if($path==='/qa-failure.php'){$_SERVER['SCRIPT_NAME']=$path;require $root.'/tests/fault-fixture.php';exit;}
if($path==='/qa-flags-off.php'){require $root.'/tests/flags-off-fixture.php';exit;}
if($path==='/qa-map-navigation.php'){$_SERVER['SCRIPT_NAME']=$path;require $root.'/tests/map-navigation-fixture.php';exit;}
if($path==='/qa-map-external.php'){$_SERVER['SCRIPT_NAME']=$path;require $root.'/tests/map-external-fixture.php';exit;}
$file=$root.$path;
if(!is_file($file) && !preg_match('#^/(CSS|js|img|assets|uploads)/#',$path)){
 $_SERVER['SCRIPT_NAME']='/front.php';require $root.'/front.php';exit;
}
if(!is_file($file)){$_SERVER['SCRIPT_NAME']='/404.php';require $root.'/404.php';exit;}
if(str_ends_with($file,'.php')){if (in_array($path, ['/feed.php', '/perfil.php', '/mercado.php'], true) && str_contains((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip')) ob_start('ob_gzhandler');$_SERVER['SCRIPT_NAME']=$path;chdir($root);require $file;exit;}
// PHP's development server does not apply Apache mod_deflate to static files.
if (preg_match('#^/(CSS|js|assets)/.+\.(css|js|svg)$#D', $path, $staticType)) {
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml'];
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip')) ob_start('ob_gzhandler');
    header('Content-Type: ' . $types[$staticType[2]]);
    readfile($file);
    exit;
}
if(preg_match('#^/(CSS|js|img|assets)/#',$path)||$path==='/sw.js'||$path==='/manifest.json'||$path==='/offline.html'||$path==='/biblioteca.html'||$path==='/pendientes.html')return false;
http_response_code(404);
