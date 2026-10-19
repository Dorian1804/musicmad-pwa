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
$source = musicmadQaRoot() . '/uploads/videos/f724a620c7b2956f5e296e33.webm';
$target = musicmadQaRoot() . '/qa-large.webm';
$base = file_get_contents($source);
$total = 101 * 1048576;
$padding = $total - strlen($base) - 9;
if ($padding < 0) {
    throw new RuntimeException('Fixture no esperado.');
}
$out = fopen($target, 'wb');
if (!$out) {
    throw new RuntimeException('No se pudo crear la prueba.');
}
// An EBML Void element extends this genuine short WebM without inventing video frames.
fwrite($out, $base);
fwrite($out, "\xEC\x01\x00\x00\x00" . pack('N', $padding));
$block = str_repeat("\0", 524288);
while ($padding > 0) {
    $length = min($padding, strlen($block));
    fwrite($out, substr($block, 0, $length));
    $padding -= $length;
}
fclose($out);
$metadata = analizarMedioLocal($target, 'video');
echo json_encode(['bytes' => filesize($target),'duracion' => $metadata['duracion'],'ancho' => $metadata['ancho'],'alto' => $metadata['alto'],'codec' => $metadata['codec']]),"\n";
