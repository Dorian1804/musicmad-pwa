<?php
if(PHP_SAPI!=='cli'||getenv('MM_RUN_QA')!=='1')exit(1);
require __DIR__.'/bootstrap.php';if(DB_NAME!=='musicmad_qa')exit(1);
$st=getDB()->prepare('SELECT id FROM playlists WHERE usuario_id=1 AND nombre=?');$st->execute(['Ideas para el ensayo · QA']);$id=(int)$st->fetchColumn();
if(!$id){$r=crearPlaylistSocial3(1,'Ideas para el ensayo · QA','Colección ficticia para revisar permisos y diseño.',false);$id=(int)$r['id'];}
agregarPistaPlaylistSocial3(1,$id,6674);echo $id;
