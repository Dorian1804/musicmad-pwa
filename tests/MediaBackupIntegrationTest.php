<?php
require_once __DIR__.'/paths.php';
use PHPUnit\Framework\TestCase;
final class MediaBackupIntegrationTest extends TestCase
{
    private array $files=[];
    protected function setUp():void{if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');self::assertSame('musicmad_qa',DB_NAME);}
    protected function tearDown():void{foreach($this->files as $file)if(is_file($file)&&!is_link($file))unlink($file);}
    private function video():string{$name=bin2hex(random_bytes(12)).'.webm';if(!is_dir(VIDEO_DIR))mkdir(VIDEO_DIR,0755,true);file_put_contents(VIDEO_DIR.'/'.$name,'FICTITIOUS_QA_VIDEO_BYTES');$this->files[]=VIDEO_DIR.'/'.$name;$this->files[]=carpetaRespaldoMedios('videos').'/'.$name;return $name;}
    public function testBackupAndRestorePreserveBytesAndDoNotOverwriteExistingFile():void{
        $name=$this->video();$source=VIDEO_DIR.'/'.$name;respaldarMedioGuardado('videos',$name);respaldarMedioGuardado('videos',$name);
        self::assertSame(file_get_contents($source),file_get_contents(carpetaRespaldoMedios('videos').'/'.$name));unlink($source);
        self::assertTrue(recuperarMedioDesdeRespaldo('videos',$name));self::assertSame('FICTITIOUS_QA_VIDEO_BYTES',file_get_contents($source));
        file_put_contents($source,'DIFFERENT_EXISTING_BYTES');self::assertTrue(recuperarMedioDesdeRespaldo('videos',$name));self::assertSame('DIFFERENT_EXISTING_BYTES',file_get_contents($source));
    }
    public function testBackupNameCannotTraverseOrStoreApplicationSecrets():void{
        foreach(['../config/local.php','local.php','.env','evil.php','a.webm/../b.webm','a..webm'] as $name)self::assertFalse(nombreMedioRespaldoValido('videos',$name));
        self::assertFalse(recuperarMedioDesdeRespaldo('videos','../config/local.php'));self::assertTrue(rutaDentroRespaldo('/public_html/private','/public_html'));self::assertFalse(rutaDentroRespaldo('/public_html-other/private','/public_html'));
    }
    public function testConflictingBackupFailsInsteadOfReplacingTheOldCopy():void{
        $name=$this->video();respaldarMedioGuardado('videos',$name);file_put_contents(VIDEO_DIR.'/'.$name,'DIFFERENT_QA_CONTENT');
        try{respaldarMedioGuardado('videos',$name);self::fail('Old backup overwritten');}catch(RespaldoMedioException $error){self::assertSame('FICTITIOUS_QA_VIDEO_BYTES',file_get_contents(carpetaRespaldoMedios('videos').'/'.$name));}
    }
    public function testVideoRecoveryKeepsPostLikesCommentsAndRequiresOwner():void{
        $db=getDB();$created=crearPublicacion(1,['tipo'=>'video','titulo'=>'Restauración original QA']);$pub=(int)$created['id'];
        $missing=bin2hex(random_bytes(12)).'.webm';$db->prepare('INSERT INTO publicacion_video(publicacion_id,archivo,formato,bytes) VALUES(?,?,?,?)')->execute([$pub,$missing,'webm',27935]);
        $db->prepare('INSERT INTO aplausos(usuario_id,publicacion_id) VALUES(2,?)')->execute([$pub]);$db->prepare('INSERT INTO comentarios(usuario_id,publicacion_id,texto) VALUES(2,?,?)')->execute([$pub,'Comentario anterior QA']);
        $data=file_get_contents(musicmadQaRoot().'/qa-video.webm');$upload=crearSubidaPendiente($db,1,'video','original.webm',strlen($data));$stored=null;
        try{
            recibirBloqueSubida($db,1,$upload['id'],0,$data,hash('sha256',$data));
            try{finalizarSubida($db,2,$upload['id'],['restaurar_publicacion'=>$pub]);self::fail('Another owner restored the file');}catch(OutOfBoundsException $error){self::assertFileExists(rutaSubidaPendiente($upload['id']));}
            $done=finalizarSubida($db,1,$upload['id'],['restaurar_publicacion'=>$pub]);self::assertSame($pub,$done['publicacion_id']);
            $st=$db->prepare('SELECT archivo FROM publicacion_video WHERE publicacion_id=?');$st->execute([$pub]);$stored=$st->fetchColumn();$this->files[]=VIDEO_DIR.'/'.$stored;$this->files[]=carpetaRespaldoMedios('videos').'/'.$stored;
            self::assertSame(hash('sha256',$data),hash_file('sha256',VIDEO_DIR.'/'.$stored));self::assertSame(hash('sha256',$data),hash_file('sha256',carpetaRespaldoMedios('videos').'/'.$stored));
            self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM aplausos WHERE publicacion_id='.$pub)->fetchColumn());self::assertSame('Comentario anterior QA',$db->query('SELECT texto FROM comentarios WHERE publicacion_id='.$pub)->fetchColumn());
            self::assertSame($pub,finalizarSubida($db,1,$upload['id'],['restaurar_publicacion'=>99999999])['publicacion_id']);
        }finally{$db->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$pub]);$db->prepare('DELETE FROM musicmad_uploads WHERE id=? AND usuario_id=1')->execute([$upload['id']]);$this->files[]=rutaSubidaPendiente($upload['id']);}
    }
}
