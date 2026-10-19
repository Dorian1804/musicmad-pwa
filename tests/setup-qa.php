<?php
require_once __DIR__.'/paths.php';
// Ejecutar solo contra la instancia aislada en musicmadQaRoot(), puerto3308.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__); $env=parse_ini_file(musicmadQaRoot().'/.env',false,INI_SCANNER_RAW);
$db=new PDO('mysql:host=127.0.0.1;port=3308;charset=utf8mb4','root',$env['MM_TEST_DB_PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE DATABASE IF NOT EXISTS musicmad_qa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->exec('USE musicmad_qa');
foreach(['musicmad.sql','social.sql','social2.sql','social3.sql'] as $file) {
    $sql=file_get_contents($root.'/config/'.$file);
    $sql=preg_replace('/(?im)^\\s*use\\s+REPLACE_WITH_LOCAL_VALUE\\s*;/','USE musicmad_qa;',$sql);
    $db->exec($sql);
}
$db->prepare('INSERT INTO usuarios(id,nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,rol) VALUES(1,?,?,?,?,?) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),apellido=VALUES(apellido),email=VALUES(email),REPLACE_WITH_LOCAL_VALUE=VALUES(REPLACE_WITH_LOCAL_VALUE),rol=VALUES(rol)')
   ->execute(['Emiliano','Músico QA','qa@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_QA_Example_42!',PASSWORD_BCRYPT),'cliente']);
$db->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,rol,avatar) VALUES(?,?,?,?,?,?)')
   ->execute(['Ana','Baterista QA','ana@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_QA_Example_42!',PASSWORD_BCRYPT),'cliente','img/avatares/musicos/m1.svg']);
$db->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,rol) VALUES(?,?,?,?,?)')
   ->execute(['Equipo','Moderación QA','admin-qa@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_QA_Example_42!',PASSWORD_BCRYPT),'administrador']);
$db->exec("INSERT INTO perfiles_musicales(usuario_id,instrumento,generos,ciudad) VALUES(1,'Guitarra','Rock,Jazz','Guadalajara'),(2,'Batería','Rock','Guadalajara');
 INSERT INTO publicaciones(usuario_id,tipo,titulo,texto,genero) VALUES(1,'cancion','Un riff para arrancar','Busco ideas para darle vida a esta guitarra. ¿Te sumas?','Rock'),(2,'texto','La próxima jam','Ensayo este viernes. La escena se construye entre todos.','Rock');
 INSERT INTO seguidores(seguidor_id,seguido_id) VALUES(1,2);
 INSERT INTO mensajes(de_id,para_id,texto) VALUES(2,1,'¿Ensayamos este viernes?');
 INSERT INTO notificaciones(usuario_id,actor_id,tipo) VALUES(1,2,'seguidor');");
foreach(['audio','videos','avatars','covers','market','partituras'] as $folder) if(!is_dir(musicmadQaRoot().'/uploads/'.$folder)) mkdir(musicmadQaRoot().'/uploads/'.$folder,0755,true);
$wav='';for($i=0;$i<8000;$i++)$wav.=pack('v',(int)(sin($i*440*2*pi()/8000)*8000)&65535);
$wav='RIFF'.pack('V',36+strlen($wav)).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',strlen($wav)).$wav;
file_put_contents(musicmadQaRoot().'/uploads/audio/qa-tone.wav',$wav);
$db->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(1,?,?)')->execute(['qa-tone.wav',strlen($wav)]);
echo "Instancia QA preparada; solo datos ficticios.\n";
