<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/social.php';
// One bounded job per invocation. Original files are only read.
$lock = 'musicmad_wave_' . hash('sha256', DB_NAME);
$lock = substr($lock, 0, 64);
$acquire = getDB()->prepare('SELECT GET_LOCK(?,0)');
$acquire->execute([$lock]);
if ((int)$acquire->fetchColumn() !== 1) {
    echo "Hay otro cálculo en curso.\n";
    exit;
}
try {
    $st = getDB()->query("SELECT p.id,p.usuario_id FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 JOIN publicacion_audio a ON a.publicacion_id=p.id LEFT JOIN publicacion_medios m ON m.publicacion_id=p.id LEFT JOIN publicacion_audio_metadata w ON w.publicacion_id=p.id WHERE (m.publicacion_id IS NULL OR m.duracion IS NULL OR (m.duracion>0 AND m.duracion<=21600 AND (w.publicacion_id IS NULL OR w.waveform IS NULL OR w.huella_audio IS NULL OR w.huella_audio<>m.sha256))) AND (w.publicacion_id IS NULL OR w.procesamiento<>'fallido' OR w.updated_at<UTC_TIMESTAMP()-INTERVAL 1 DAY) ORDER BY p.id LIMIT 1");
    $row = $st->fetch();
    if (!$row) {
        echo "No hay pistas pendientes.\n";
        exit;
    }
    try {
        procesarFormaOnda((int)$row['usuario_id'], (int)$row['id']);
        echo "Forma de onda guardada.\n";
    } catch (Throwable $e) {
        getDB()->prepare("INSERT INTO publicacion_audio_metadata(publicacion_id,procesamiento) VALUES(?,'fallido') ON DUPLICATE KEY UPDATE procesamiento='fallido',updated_at=UTC_TIMESTAMP()")->execute([(int)$row['id']]);
        throw $e;
    }
} catch (Throwable $e) {
    error_log('Waveform cron: ' . get_class($e));
    fwrite(STDERR, "No se pudo procesar la pista; revisa formato, tiempo y FFmpeg.\n");
    getDB()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    exit(1);
}
getDB()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
