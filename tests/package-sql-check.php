<?php
if(PHP_SAPI!=='cli'||getenv('MM_RUN_QA')!=='1')exit(1);
require __DIR__.'/bootstrap.php';if(DB_NAME!=='musicmad_qa')exit(1);$db=getDB();
require_once dirname(__DIR__).'/includes/migration_runner.php';
$before=[(int)$db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn(),(int)$db->query('SELECT COUNT(*) FROM publicaciones')->fetchColumn()];
$sql=file_get_contents(dirname(__DIR__).'/config/actualizar_musicmad_v85.sql');
for($round=0;$round<2;$round++)ejecutarLoteSQLMusicMad($db,$sql);
$after=[(int)$db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn(),(int)$db->query('SELECT COUNT(*) FROM publicaciones')->fetchColumn()];
if($before!==$after)throw new RuntimeException('La actualización cambió el número de usuarios/publicaciones.');
echo "SQL acumulativo importado dos veces en QA; usuarios/publicaciones conservados.\n";
