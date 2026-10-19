<?php

if (PHP_SAPI !== 'cli') {
    exit;
}
putenv('MM_RUN_QA=1');
require __DIR__ . '/bootstrap.php';
if (DB_NAME !== 'musicmad_qa') {
    exit(1);
}
$st = getDB()->prepare('SELECT id FROM retos_musicales WHERE creado_por=3 AND titulo=?');
$st->execute(['Tres notas · demostración QA']);
$id = (int)$st->fetchColumn();
if (!$id) {
    $id = crearReto(3, ['titulo' => 'Tres notas · demostración QA','descripcion' => 'Haz una melodía con tres notas. Estas pistas son ficticias y se usan solo para comprobar la aplicación.','inicio' => gmdate('Y-m-d\TH:i', time() + 3600),'votacion' => gmdate('Y-m-d\TH:i', time() + 86400),'fin' => gmdate('Y-m-d\TH:i', time() + 172800)], bin2hex(random_bytes(16)));
    getDB()->prepare('UPDATE retos_musicales SET inicio=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id=?')->execute([$id]);
    participarReto(2, $id, 7242, bin2hex(random_bytes(16)));
}
if (($argv[1] ?? '') === 'vote') {
    getDB()->prepare('UPDATE musicmad_challenge_details SET votacion_desde=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE reto_id=?')->execute([$id]);
}
echo 'Reto ficticio: ' . $id . "\n";
