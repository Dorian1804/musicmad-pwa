<?php
require_once __DIR__.'/paths.php';
if(PHP_SAPI!=='cli')exit;putenv('MM_RUN_QA=1');require __DIR__.'/bootstrap.php';if(DB_NAME!=='musicmad_qa')throw new RuntimeException('Solo QA.');
$fixture=musicmadQaRoot().'/player-diagnostic-demo.json';
if(($argv[1]??'')==='clean'){
    $state=json_decode(file_get_contents($fixture),true,512,JSON_THROW_ON_ERROR);
    foreach(['good','missing'] as $key)getDB()->prepare('DELETE FROM publicaciones WHERE id=? AND usuario_id=1')->execute([(int)$state[$key]]);
    $name=$state['file'];if(nombreMedioRespaldoValido('audio',$name)&&is_file(AUDIO_DIR.'/'.$name))unlink(AUDIO_DIR.'/'.$name);unlink($fixture);exit;
}
$good=(int)crearPublicacion(1,['tipo'=>'cancion','titulo'=>'Audio válido · prueba aislada'])['id'];$missing=(int)crearPublicacion(1,['tipo'=>'cancion','titulo'=>'Audio ausente · prueba aislada'])['id'];
$name=bin2hex(random_bytes(12)).'.wav';$bytes=file_get_contents(musicmadQaRoot().'/uploads/audio/qa-tone.wav');file_put_contents(AUDIO_DIR.'/'.$name,$bytes);
$st=getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,?)');$st->execute([$good,$name,strlen($bytes)]);$st->execute([$missing,bin2hex(random_bytes(12)).'.wav',strlen($bytes)]);
$state=['good'=>$good,'missing'=>$missing,'file'=>$name];file_put_contents($fixture,json_encode($state,JSON_THROW_ON_ERROR));echo json_encode(['good'=>$good,'missing'=>$missing]),"\n";
