<?php
require_once __DIR__.'/paths.php';
if(PHP_SAPI!=='cli')exit(1);
$taskRoot=musicmadQaRoot().'/persistent-storage-'.bin2hex(random_bytes(8));
mkdir($taskRoot.'/public/app/uploads/audio',0700,true);mkdir($taskRoot.'/private',0700,true);
define('APP_ROOT',$taskRoot.'/public/app');define('MUSICMAD_MEDIA_BACKUP_DIR',$taskRoot.'/private');
$_SERVER['DOCUMENT_ROOT']=$taskRoot.'/public';
require dirname(__DIR__).'/includes/media_storage.php';
$result=[];
try {
    $legacy=APP_ROOT.'/uploads/audio/qa_existing.wav';file_put_contents($legacy,'EXISTING_QA_BYTES');
    $dir=musicmadMediaDirectoryForFile('audio','qa_existing.wav');
    $result['outside']=!rutaDentroRespaldo($dir,dirname(APP_ROOT));
    $result['legacy_copied']=file_get_contents($dir.'/qa_existing.wav')==='EXISTING_QA_BYTES' && is_file($legacy);
    $photo='1_'.bin2hex(random_bytes(12)).'.jpg';
    $photos=musicmadUploadDirectory('avatars');file_put_contents($photos.'/'.$photo,'SYNTHETIC_QA_PHOTO_BYTES');
    respaldarMedioGuardado('avatars',$photo);unlink($photos.'/'.$photo);
    $result['photo_restored']=file_get_contents(musicmadMediaDirectoryForFile('avatars',$photo).'/'.$photo)==='SYNTHETIC_QA_PHOTO_BYTES';
    file_put_contents(musicmadUploadDirectory('videos').'/qa_new.webm','NEW_QA_BYTES');
    rename(APP_ROOT,dirname(APP_ROOT).'/old-app');mkdir(APP_ROOT,0700);
    $result['update_survives']=file_get_contents(musicmadUploadDirectory('audio').'/qa_existing.wav')==='EXISTING_QA_BYTES'
        && file_get_contents(musicmadUploadDirectory('videos').'/qa_new.webm')==='NEW_QA_BYTES';
    file_put_contents($taskRoot.'/conflict.wav','DIFFERENT');
    $result['immutable']=musicmadPreserveMediaFile($taskRoot.'/conflict.wav',$dir.'/qa_existing.wav')===false
        && file_get_contents($dir.'/qa_existing.wav')==='EXISTING_QA_BYTES';
    $result['traversal']=!musicmadMediaNameValid('audio','../local.php') && !musicmadMediaNameValid('avatars','../../.env')
        && !musicmadMediaNameValid('market','a.php') && !musicmadMediaNameValid('covers','1_'.str_repeat('a',24).'.jpg.php');
    $result['image_name']=musicmadMediaNameValid('avatars','1_'.str_repeat('b',24).'.jpg');
    if(PHP_OS_FAMILY!=='Windows'){
        symlink($taskRoot.'/conflict.wav',$dir.'/symlink.wav');
        $result['symlink']=!musicmadPreserveMediaFile($dir.'/symlink.wav',$dir.'/unsafe.wav');
    }
    echo json_encode($result,JSON_THROW_ON_ERROR);
} finally {
    // This randomly named fixture tree contains only synthetic bytes created above.
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($taskRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($taskRoot);
}
