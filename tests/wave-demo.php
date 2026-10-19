<?php
if(PHP_SAPI!=='cli')exit;putenv('MM_RUN_QA=1');require __DIR__.'/bootstrap.php';if(DB_NAME!=='musicmad_qa')throw new RuntimeException('QA only.');
$file='wave_demo_'.bin2hex(random_bytes(8)).'.wav';$path=AUDIO_DIR.'/'.$file;$data='';$rate=16000;$seconds=12;
for($i=0;$i<$rate*$seconds;$i++){$t=$i/$rate;$envelope=(.08+.5*(.5+.5*sin($t*1.5)))*exp(-5*fmod($t,.5));$sample=(int)round(24000*$envelope*sin(2*M_PI*220*$t));$data.=pack('v',$sample<0?$sample+65536:$sample);}
$wav='RIFF'.pack('V',36+strlen($data)).'WAVEfmt '.pack('VvvVVvv',16,1,1,$rate,$rate*2,2,16).'data'.pack('V',strlen($data)).$data;file_put_contents($path,$wav);
getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo,texto,genero) VALUES(1,'demo','Boceto rítmico · QA','Sonido sintético para comprobar formas de onda, sin datos reales.','Electrónica')")->execute();$id=(int)getDB()->lastInsertId();getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,?)')->execute([$id,$file,strlen($wav)]);guardarMetadatosMedio($id,analizarMedioLocal($path,'audio'));echo 'Publicación QA: '.$id."\n";
