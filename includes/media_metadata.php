<?php

function validarDuracionMedio(mixed $seconds): ?float
{
    if ($seconds === null) {
        return null; // Some streaming WebM files legitimately omit duration metadata.
    }
    if (!is_numeric($seconds) || !is_finite((float)$seconds) || (float)$seconds <= 0 || (float)$seconds > 21600) {
        throw new InvalidArgumentException('El medio debe tener una duración válida de hasta seis horas.');
    }
    return (float)$seconds;
}

function analizarMedioLocal(string $path, string $kind): array
{
    $limit = match ($kind) {
        'audio' => 64 * 1048576,
        'video' => 256 * 1048576,
        default => throw new InvalidArgumentException('Tipo de medio no permitido.'),
    };
    if (str_contains($path, '://') || is_link($path) || !is_file($path) || !is_readable($path)) {
        throw new InvalidArgumentException('El medio debe ser un archivo local regular.');
    }
    $bytes = filesize($path);
    if ($bytes === false || $bytes < 1 || $bytes > $limit) {
        throw new InvalidArgumentException('El archivo supera el límite de análisis.');
    }
    require_once APP_ROOT . '/vendor/autoload.php';
    if (!class_exists('getID3')) {
        throw new RuntimeException('Falta la biblioteca de validación de medios.');
    }
    $reader = new getID3();
    $reader->option_save_attachments = false;
    $reader->option_tags_html = false;
    $reader->option_tag_id3v1 = false;
    $reader->option_tag_id3v2 = false;
    $reader->option_tag_apetag = false;
    $reader->option_tag_lyrics3 = false;
    $info = $reader->analyze($path);
    if (!empty($info['error'])) {
        throw new InvalidArgumentException('El archivo está incompleto o su formato no se puede leer.');
    }
    if ($kind === 'audio' && (empty($info['audio']['dataformat']) || empty($info['audio']['sample_rate']) || !empty($info['video']['dataformat']))) {
        throw new InvalidArgumentException('El archivo no contiene una pista de audio válida.');
    }
    if ($kind === 'video' && (empty($info['video']['dataformat']) || empty($info['video']['resolution_x']) || empty($info['video']['resolution_y']))) {
        throw new InvalidArgumentException('El archivo no contiene un video legible. Usa MP4 con H.264 o WebM.');
    }
    $seconds = validarDuracionMedio($info['playtime_seconds'] ?? null);
    if ($kind === 'video' && ((int)$info['video']['resolution_x'] > 8192 || (int)$info['video']['resolution_y'] > 8192
        || (float)$info['video']['resolution_x'] * (float)$info['video']['resolution_y'] > 33554432)) {
        throw new InvalidArgumentException('El video tiene dimensiones demasiado grandes.');
    }
    return ['sha256' => hash_file('sha256', $path),'duracion' => $seconds,'mime' => $info['mime_type'] ?? ($kind === 'audio' ? 'audio/mpeg' : 'video/mp4'),
      'ancho' => (int)($info['video']['resolution_x'] ?? 0),'alto' => (int)($info['video']['resolution_y'] ?? 0),
      'codec' => mb_substr((string)($info[$kind === 'video' ? 'video' : 'audio']['codec'] ?? $info[$kind === 'video' ? 'video' : 'audio']['dataformat'] ?? ''), 0, 80)];
}
function guardarMetadatosMedio(int $pubId, array $metadata): void
{
    $st = getDB()->prepare('INSERT INTO publicacion_medios(publicacion_id,sha256,duracion,mime,ancho,alto,codec) VALUES(:p,:h,:d,:m,:w,:a,:c)');
    $st->execute([':p' => $pubId,':h' => $metadata['sha256'],':d' => $metadata['duracion'],':m' => $metadata['mime'],':w' => $metadata['ancho'],':a' => $metadata['alto'],':c' => $metadata['codec']]);
}
