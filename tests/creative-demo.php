<?php

if (PHP_SAPI !== 'cli') {
    exit;
}
putenv('MM_RUN_QA=1');
require __DIR__ . '/bootstrap.php';
if (DB_NAME !== 'musicmad_qa') {
    throw new RuntimeException('QA only.');
}
$id = (int)($argv[1] ?? 0);
$project = colabDisponiblePara(1, $id);
if (!$project || (int)$project['usuario_id'] !== 1 || !str_ends_with($project['titulo'], 'QA')) {
    throw new RuntimeException('Expected QA collaboration.');
}
getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo,texto) VALUES(2,'demo','Ana · toma QA','Aporte sintético ficticio para probar colaboración.')")->execute();
$pub = (int)getDB()->lastInsertId();
getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) SELECT ?,archivo,bytes FROM publicacion_audio WHERE publicacion_id=9')->execute([$pub]);
getDB()->prepare('INSERT INTO publicacion_medios(publicacion_id,sha256,duracion,mime,ancho,alto,codec) SELECT ?,sha256,duracion,mime,ancho,alto,codec FROM publicacion_medios WHERE publicacion_id=9')->execute([$pub]);
$stem = aportarStemColab(2, $id, ['pista' => $pub,'titulo' => 'Capa de Ana QA','offset_ms' => 500,'volumen' => 50], bin2hex(random_bytes(16)));
echo 'Aporte QA: ' . $stem . '; publicación: ' . $pub . "\n";
