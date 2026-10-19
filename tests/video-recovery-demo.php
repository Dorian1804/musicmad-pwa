<?php
if(PHP_SAPI!=='cli')exit;putenv('MM_RUN_QA=1');require __DIR__.'/bootstrap.php';if(DB_NAME!=='musicmad_qa')throw new RuntimeException('Solo QA.');
$pub=crearPublicacion(1,['tipo'=>'video','titulo'=>'Recuperación de video · QA navegador','texto'=>'Original sintético; conservación de comentarios.'])['id'];
getDB()->prepare('INSERT INTO publicacion_video(publicacion_id,archivo,formato,bytes) VALUES(?,?,?,?)')->execute([$pub,bin2hex(random_bytes(12)).'.webm','webm',27935]);
getDB()->prepare('INSERT INTO aplausos(usuario_id,publicacion_id) VALUES(2,?)')->execute([$pub]);getDB()->prepare('INSERT INTO comentarios(usuario_id,publicacion_id,texto) VALUES(2,?,?)')->execute([$pub,'Comentario anterior al respaldo QA']);
echo json_encode(['publicacion'=>$pub]),"\n";
