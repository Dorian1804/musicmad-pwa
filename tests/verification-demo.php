<?php
if(PHP_SAPI!=='cli'||getenv('MM_RUN_QA')!=='1')exit(1);
require __DIR__.'/bootstrap.php';if(DB_NAME!=='musicmad_qa')exit(1);
$email='verification-browser@musicmad.invalid';$st=getDB()->prepare('SELECT id FROM usuarios WHERE email=?');$st->execute([$email]);$id=(int)$st->fetchColumn();
if(!$id){getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,avatar,bio) VALUES(?,?,?,?,?,?)')->execute(['Sofía','Artista QA',$email,REPLACE_WITH_LOCAL_VALUE_hash(bin2hex(random_bytes(32)),PASSWORD_BCRYPT),'img/avatares/musicos/m2.svg','Perfil ficticio de guitarra para comprobar la revisión manual de MusicMad.']);$id=(int)getDB()->lastInsertId();getDB()->prepare('INSERT INTO perfiles_musicales(usuario_id,instrumento,generos,ciudad) VALUES(?,?,?,?)')->execute([$id,'Guitarra','Indie, Rock','Guadalajara']);getDB()->prepare('INSERT INTO perfiles_publicos(usuario_id,slug) VALUES(?,?)')->execute([$id,'sofia_verification_qa']);getDB()->prepare('INSERT INTO musicmad_email_verified(usuario_id,email_hash,verificado_en) VALUES(?,?,UTC_TIMESTAMP())')->execute([$id,hashCorreoCuenta($email)]);}
echo 'Cuenta ficticia de navegador: '.$id."\n";
