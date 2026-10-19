<?php

// Synthetic chart fixtures only. These rows are not proof of actual listening.
if (PHP_SAPI !== 'cli') {
    exit;
}
putenv('MM_RUN_QA=1');
require __DIR__ . '/bootstrap.php';
if (DB_NAME !== 'musicmad_qa') {
    throw new RuntimeException('QA only.');
}
$db = getDB();
$total = 0;
$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
foreach ([9,6674,8984] as $index => $pub) {
    $st = $db->prepare('SELECT usuario_id FROM publicaciones WHERE id=?');
    $st->execute([$pub]);
    if ((int)$st->fetchColumn() !== 1) {
        continue;
    }
    medioParaEstadisticas(2, $pub);
    for ($i = 0;$i < 24;$i++) {
        if (($i + $index) % 3 === 0 && $i % 4 !== 0) {
            continue;
        }
        $day = $today->modify('-' . $i . ' days')->format('Y-m-d');
        $st = $db->prepare('INSERT IGNORE INTO musicmad_confirmed_plays(publicacion_id,usuario_id,fecha,confirmada_en) VALUES(?,2,?,?)');
        $st->execute([$pub,$day,$day . ' 12:00:00']);
        $total += $st->rowCount();
    }
}
logrosArtista(1, true);
echo 'Filas ficticias QA agregadas: ' . $total . "\n";
