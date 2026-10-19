<?php
require_once __DIR__.'/paths.php';
use PHPUnit\Framework\TestCase;
final class UploadIntegrationTest extends TestCase
{
    private array $uploads=[];
    protected function setUp(): void
    {
        if(getenv('MM_RUN_QA')!=='1') $this->markTestSkipped('Activar MM_RUN_QA=1 para la instancia aislada de puerto 3308.');
        self::assertSame('musicmad_qa',DB_NAME);
        getDB()->exec(file_get_contents(dirname(__DIR__).'/config/migrations/001_upload_sessions.sql'));
        getDB()->exec(file_get_contents(dirname(__DIR__).'/config/migrations/002_media_metadata.sql'));
    }
    private function begin(string $kind,string $name,int $bytes): array
    {
        $row=crearSubidaPendiente(getDB(),1,$kind,$name,$bytes);$this->uploads[]=$row['id'];return $row;
    }
    protected function tearDown(): void
    {
        foreach($this->uploads as $id) {
            $row=obtenerSubidaPropia(getDB(),$id,1);
            if($row['publicacion_id']) eliminarPublicacion((int)$row['publicacion_id'],1,false);
            getDB()->prepare('DELETE FROM musicmad_uploads WHERE id=? AND usuario_id=1')->execute([$id]);
            $path=rutaSubidaPendiente($id);if(is_file($path))unlink($path);
        }
    }
    public function testResumeDuplicateChunkOwnerIsolationAndExactlyOnePublication(): void
    {
        $bytes=file_get_contents(musicmadQaRoot().'/uploads/audio/qa-tone.wav');
        $row=$this->begin('audio','ensayo.wav',strlen($bytes));$id=$row['id'];$first=substr($bytes,0,8000);
        $row=recibirBloqueSubida(getDB(),1,$id,0,$first,hash('sha256',$first));
        self::assertSame(8000,(int)$row['recibidos']);
        self::assertSame(8000,(int)recibirBloqueSubida(getDB(),1,$id,0,$first,hash('sha256',$first))['recibidos']);
        try{obtenerSubidaPropia(getDB(),$id,2);self::fail('Otro usuario pudo consultar el archivo.');}catch(OutOfBoundsException){self::assertTrue(true);}
        $last=substr($bytes,8000);recibirBloqueSubida(getDB(),1,$id,8000,$last,hash('sha256',$last));
        $metadata=['tipo'=>'cancion','titulo'=>'QA subida reanudable','texto'=>'Prueba aislada','genero'=>'Rock'];
        $complete=finalizarSubida(getDB(),1,$id,$metadata);
        self::assertSame('completo',$complete['estado']);
        $again=finalizarSubida(getDB(),1,$id,$metadata);
        self::assertSame($complete['publicacion_id'],$again['publicacion_id']);
        $query=getDB()->prepare('SELECT archivo FROM publicacion_audio WHERE publicacion_id=?');$query->execute([$complete['publicacion_id']]);
        self::assertSame(hash('sha256',$bytes),hash_file('sha256',AUDIO_DIR.'/'.$query->fetchColumn()));
        self::assertFileDoesNotExist(rutaSubidaPendiente($id));
    }
    public function testCorruptBlockDoesNotAdvanceOffset(): void
    {
        $row=$this->begin('audio','pista.mp3',20);
        try{recibirBloqueSubida(getDB(),1,$row['id'],0,'wrong',str_repeat('a',64));self::fail('Aceptó checksum incorrecto.');}catch(InvalidArgumentException){self::assertSame(0,(int)obtenerSubidaPropia(getDB(),$row['id'],1)['recibidos']);}
    }
    public function testInvalidMediaRollsBackPublicationAndRetainsDraftFile(): void
    {
        $data='Este archivo no es audio.';$row=$this->begin('audio','falso.mp3',strlen($data));
        recibirBloqueSubida(getDB(),1,$row['id'],0,$data,hash('sha256',$data));
        $before=(int)getDB()->query('SELECT COUNT(*) FROM publicaciones')->fetchColumn();
        try{finalizarSubida(getDB(),1,$row['id'],['tipo'=>'cancion','titulo'=>'No debe publicarse']);self::fail('Aceptó texto como audio.');}catch(InvalidArgumentException){self::assertSame($before,(int)getDB()->query('SELECT COUNT(*) FROM publicaciones')->fetchColumn());}
        self::assertFileExists(rutaSubidaPendiente($row['id']));
        self::assertSame('subiendo',obtenerSubidaPropia(getDB(),$row['id'],1)['estado']);
    }
    public function testDuplicateDetectionOnlyExposesOwnExactMedia(): void
    {
        $db=getDB();$db->beginTransaction();try{$a=crearPublicacion(1,['titulo'=>'Mismo archivo A','tipo'=>'texto'])['id'];$b=crearPublicacion(1,['titulo'=>'Mismo archivo B','tipo'=>'texto'])['id'];$c=crearPublicacion(2,['titulo'=>'Archivo privado de otra cuenta','tipo'=>'texto'])['id'];$hash=hash('sha256','duplicate media QA');foreach([$a,$b,$c] as $pub)$db->prepare("INSERT INTO publicacion_medios(publicacion_id,sha256,mime) VALUES(?,?,'audio/wav')")->execute([$pub,$hash]);$own=duplicadosMedioPropio(1,$a);self::assertSame([$b],array_map('intval',array_column($own,'id')));self::assertSame([],duplicadosMedioPropio(2,$a));self::assertSame([],duplicadosMedioPropio(1,$c));}finally{$db->rollBack();}
    }
    public function testBandUploadKeepsAssociationAndRejectsRemovedMemberWithoutLosingStaging(): void
    {
        $r=crearBanda(1,['nombre'=>'QA upload band '.bin2hex(random_bytes(5))]);$band=$r['id'];$bytes=file_get_contents(musicmadQaRoot().'/uploads/audio/qa-tone.wav');
        try{
            $row=$this->begin('audio','banda.wav',strlen($bytes));recibirBloqueSubida(getDB(),1,$row['id'],0,$bytes,hash('sha256',$bytes));$done=finalizarSubida(getDB(),1,$row['id'],['tipo'=>'cancion','titulo'=>'Pista colectiva','banda_id'=>$band]);self::assertSame($done['publicacion_id'],publicacionesBanda(1,$band)[0]['id']);
            $retry=finalizarSubida(getDB(),1,$row['id'],['titulo'=>'Otro','banda_id'=>$band]);self::assertSame($done['publicacion_id'],$retry['publicacion_id']);
            $next=$this->begin('audio','salida.wav',strlen($bytes));recibirBloqueSubida(getDB(),1,$next['id'],0,$bytes,hash('sha256',$bytes));salirBandaWorkspace(1,$band);try{finalizarSubida(getDB(),1,$next['id'],['tipo'=>'cancion','titulo'=>'No autorizado','banda_id'=>$band]);self::fail();}catch(InvalidArgumentException $error){self::assertFileExists(rutaSubidaPendiente($next['id']));self::assertSame('subiendo',obtenerSubidaPropia(getDB(),$next['id'],1)['estado']);}
        }finally{getDB()->prepare('DELETE FROM bandas WHERE id=?')->execute([$band]);}
    }
    public function testStudioUploadIsAtomicAndRetryCreatesOneStem(): void
    {
        $project=crearColaboracion(1,['titulo'=>'QA direct transport','pista'=>9]);
        try{
            $data=file_get_contents(musicmadQaRoot().'/uploads/audio/qa-tone.wav');
            $row=$this->begin('audio','studio.wav',strlen($data));recibirBloqueSubida(getDB(),1,$row['id'],0,$data,hash('sha256',$data));
            $metadata=['tipo'=>'demo','titulo'=>'Direct studio take','colaboracion_id'=>$project,'offset_ms'=>125,'volumen'=>60];
            $done=finalizarSubida(getDB(),1,$row['id'],$metadata);$again=finalizarSubida(getDB(),1,$row['id'],array_replace($metadata,['colaboracion_id'=>999999]));
            self::assertSame($done['publicacion_id'],$again['publicacion_id']);$stems=stemsColabPara(1,$project);self::assertCount(1,$stems);self::assertSame((int)$done['publicacion_id'],(int)$stems[0]['publicacion_id']);self::assertSame(125,(int)$stems[0]['offset_ms']);
            $pending=$this->begin('audio','closed.wav',strlen($data));recibirBloqueSubida(getDB(),1,$pending['id'],0,$data,hash('sha256',$data));
            cambiarEstadoColab(1,$project,'cerrada');$before=(int)getDB()->query('SELECT COUNT(*) FROM publicaciones')->fetchColumn();$files=glob(AUDIO_DIR.'/*');
            try{finalizarSubida(getDB(),1,$pending['id'],$metadata);self::fail('Closed project accepted an upload');}catch(LogicException $e){self::assertSame($before,(int)getDB()->query('SELECT COUNT(*) FROM publicaciones')->fetchColumn());self::assertSame($files,glob(AUDIO_DIR.'/*'));self::assertFileExists(rutaSubidaPendiente($pending['id']));}
            cambiarEstadoColab(1,$project,'abierta');finalizarSubida(getDB(),1,$pending['id'],$metadata);self::assertCount(2,stemsColabPara(1,$project));
        }finally{getDB()->prepare('DELETE FROM colaboraciones WHERE id=?')->execute([$project]);}
    }
    public function testNativeRequestIdIsOwnedAndReusesOnePendingUpload(): void
    {
        $nonce=bin2hex(random_bytes(16));$a=crearSubidaPendiente(getDB(),1,'audio','native.wav',100,$nonce);$this->uploads[]=$nonce;
        $b=crearSubidaPendiente(getDB(),1,'audio','native.wav',100,$nonce);self::assertSame($a['id'],$b['id']);
        try{crearSubidaPendiente(getDB(),2,'audio','native.wav',100,$nonce);self::fail('Cross-owner request');}catch(OutOfBoundsException $e){self::assertTrue(true);}
        try{crearSubidaPendiente(getDB(),1,'audio','other.wav',100,$nonce);self::fail('Changed request');}catch(DomainException $e){self::assertTrue(true);}
    }
    public function testInactiveAccountCannotFinishAStagedUpload(): void
    {
        getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute(['Upload','QA','upload_'.bin2hex(random_bytes(6)).'@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Upload_QA_42!',PASSWORD_BCRYPT)]);$uid=(int)getDB()->lastInsertId();$row=null;
        try{
            $data=file_get_contents(musicmadQaRoot().'/uploads/audio/qa-tone.wav');$row=crearSubidaPendiente(getDB(),$uid,'audio','inactive.wav',strlen($data));recibirBloqueSubida(getDB(),$uid,$row['id'],0,$data,hash('sha256',$data));getDB()->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$uid]);
            try{finalizarSubida(getDB(),$uid,$row['id'],['titulo'=>'Must not publish']);self::fail('Inactive owner finalized media');}catch(DomainException $e){self::assertSame('subiendo',obtenerSubidaPropia(getDB(),$row['id'],$uid)['estado']);self::assertFileExists(rutaSubidaPendiente($row['id']));self::assertSame(0,(int)getDB()->query('SELECT COUNT(*) FROM publicaciones WHERE usuario_id='.$uid)->fetchColumn());}
        }finally{if($row&&is_file(rutaSubidaPendiente($row['id'])))unlink(rutaSubidaPendiente($row['id']));getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$uid]);}
    }
    public function testOver100MegabytesUsesSmallBlocks(): void
    {
        $size=101*1048576;$row=$this->begin('video','video-grande.mp4',$size);$block=str_repeat('Q',MUSICMAD_CHUNK_BYTES);$digest=hash('sha256',$block);
        for($offset=0;$offset<$size;$offset+=strlen($block)) $row=recibirBloqueSubida(getDB(),1,$row['id'],$offset,$block,$digest);
        self::assertSame($size,(int)$row['recibidos']);
        clearstatcache(true,rutaSubidaPendiente($row['id']));self::assertSame($size,filesize(rutaSubidaPendiente($row['id'])));
        // Transport test only; it must not claim arbitrary bytes are playable video.
    }
}
