<?php

require_once __DIR__ . '/paths.php';
if (PHP_SAPI !== 'cli') {
    exit;
}
putenv('MM_RUN_QA=1');
require __DIR__ . '/bootstrap.php';
if (DB_NAME !== 'musicmad_qa') {
    throw new RuntimeException('Solo QA.');
}
$id = (int)($argv[1] ?? 0);
$st = getDB()->prepare("SELECT v.archivo,v.bytes,m.duracion,m.codec FROM publicacion_video v JOIN publicacion_medios m ON m.publicacion_id=v.publicacion_id JOIN publicaciones p ON p.id=v.publicacion_id WHERE v.publicacion_id=? AND p.usuario_id=1 AND p.titulo='Video de 101 MB · prueba QA'");
$st->execute([$id]);
$row = $st->fetch();
if (!$row || !preg_match('/^[a-f0-9]{24}\.webm$/D', $row['archivo'])) {
    throw new RuntimeException('Fixture no esperado.');
}
$path = VIDEO_DIR . '/' . $row['archivo'];
echo json_encode(['publicacion' => $id,'bytes' => (int)$row['bytes'],'bytes_archivo' => filesize($path),'duracion' => (float)$row['duracion'],'codec' => $row['codec'],'hash_igual' => hash_equals(hash_file('sha256', musicmadQaRoot() . '/qa-large.webm'), hash_file('sha256', $path))]),"\n";
