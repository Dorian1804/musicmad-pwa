<?php

/** Real sample peaks; no synthetic placeholder waveform. All caches retain publication permissions. */
function datosFormaOnda(int $uid, int $pub): array
{
    if ($uid < 1 || !puedeVerPublicacionSocial3($uid, $pub)) {
        throw new OutOfBoundsException('Pista no disponible.');
    }
    $st = getDB()->prepare('SELECT p.usuario_id,a.archivo,a.bytes,m.sha256,m.duracion,w.waveform,w.huella_audio FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id LEFT JOIN publicacion_medios m ON m.publicacion_id=p.id LEFT JOIN publicacion_audio_metadata w ON w.publicacion_id=p.id WHERE p.id=?');
    $st->execute([$pub]);
    $row = $st->fetch();
    if (!$row) {
        throw new OutOfBoundsException('Pista no disponible.');
    }
    $wave = $row['waveform'] ? json_decode($row['waveform'], true) : null;
    if (!is_array($wave)) {
        $wave = null;
    }
    if (!$wave || !is_string($row['sha256']) || !hash_equals($row['sha256'], (string)$row['huella_audio'])) {
        $wave = null;
    }
    if ($wave) {
        try {
            validarPicosOnda($wave['picos'] ?? null);
            if (($wave['version'] ?? null) !== 1 || !isset($wave['duracion']) || !is_numeric($wave['duracion']) || !is_finite((float)$wave['duracion']) || $wave['duracion'] <= 0 || $wave['duracion'] > 21600) {
                $wave = null;
            }
        } catch (InvalidArgumentException $e) {
            $wave = null;
        }
    }
    return ['id' => $pub,'propia' => (int)$row['usuario_id'] === $uid,'bytes' => (int)$row['bytes'],'duracion' => $row['duracion'] !== null ? (float)$row['duracion'] : null,'sha256' => $row['sha256'],'onda' => $wave,'calculo_local' => (int)$row['bytes'] <= 10 * 1024 * 1024 && $row['duracion'] !== null && (float)$row['duracion'] <= 300];
}
function validarPicosOnda(mixed $peaks): array
{
    if (!is_array($peaks) || !array_is_list($peaks) || count($peaks) !== 512) {
        throw new InvalidArgumentException('Forma de onda no válida.');
    }
    foreach ($peaks as $p) {
        if (!is_int($p) || $p < 0 || $p > 1000) {
            throw new InvalidArgumentException('Amplitud no válida.');
        }
    }
    return $peaks;
}
function guardarFormaOnda(int $uid, int $pub, array $peaks, float $duration, string $sha, string $source = 'navegador'): void
{
    validarPicosOnda($peaks);
    if (!in_array($source, ['navegador','servidor'], true) || !is_finite($duration) || $duration <= 0 || $duration > 21600 || !preg_match('/^[a-f0-9]{64}$/D', $sha)) {
        throw new InvalidArgumentException('Datos de pista no válidos.');
    }
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT p.usuario_id,m.sha256,m.duracion FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 JOIN publicacion_medios m ON m.publicacion_id=p.id WHERE p.id=? FOR UPDATE');
        $st->execute([$pub]);
        $row = $st->fetch();
        if (!$row || (int)$row['usuario_id'] !== $uid) {
            throw new OutOfBoundsException('Solo el autor puede guardar esta forma de onda.');
        }
        if (!hash_equals((string)$row['sha256'], $sha) || $row['duracion'] === null || abs((float)$row['duracion'] - $duration) > max(.5, $duration * .01)) {
            throw new InvalidArgumentException('La pista cambió; vuelve a calcular.');
        }
        $json = json_encode(['version' => 1,'picos' => $peaks,'duracion' => $duration,'origen' => $source], JSON_THROW_ON_ERROR);
        $db->prepare("INSERT INTO publicacion_audio_metadata(publicacion_id,duracion_ms,waveform,huella_audio,procesamiento) VALUES(?,?,?,?,'listo') ON DUPLICATE KEY UPDATE duracion_ms=VALUES(duracion_ms),waveform=VALUES(waveform),huella_audio=VALUES(huella_audio),procesamiento='listo'")->execute([$pub,(int)round($duration * 1000),$json,$sha]);
        if ($owns) {
            $db->commit();
        }
    } catch (Throwable $error) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
/** PCM16 WAV can be processed without an external binary, in bounded chunks. */
function picosWavLocal(string $path, int $budget = 15): ?array
{
    $f = fopen($path, 'rb');
    if (!$f) {
        throw new RuntimeException('No se puede leer la pista.');
    }
    $deadline = microtime(true) + $budget;
    try {
        $header = fread($f, 12);
        if (strlen($header) !== 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
            return null;
        }
        $format = null;
        $offset = 0;
        $bytes = 0;
        $size = filesize($path);
        for ($i = 0;$i < 200 && ftell($f) + 8 <= $size;$i++) {
            $chunk = fread($f, 8);
            $len = unpack('V', substr($chunk, 4, 4))[1];
            $start = ftell($f);
            if ($len > $size - $start) {
                throw new InvalidArgumentException('WAV incompleto.');
            }
            if (substr($chunk, 0, 4) === 'fmt ') {
                if ($len < 16) {
                    return null;
                }
                $format = unpack('vencoding/vchannels/Vrate/Vbyterate/valign/vbits', fread($f, 16));
            }
            if (substr($chunk, 0, 4) === 'data') {
                $offset = $start;
                $bytes = $len;
            }
            fseek($f, $start + $len + ($len % 2));
            if ($format && $offset) {
                break;
            }
        }
        if (!$format || !$offset || $format['encoding'] !== 1 || $format['bits'] !== 16 || !in_array($format['channels'], [1,2], true) || $format['align'] !== 2 * $format['channels'] || $format['rate'] < 8000 || $format['rate'] > 192000) {
            return null;
        }
        $frames = intdiv($bytes, $format['align']);
        if (!$frames || $bytes % $format['align']) {
            throw new InvalidArgumentException('WAV incompleto.');
        }
        $peaks = array_fill(0, 512, 0);
        $sample = 0;
        fseek($f, $offset);
        $remaining = $bytes;
        while ($remaining > 0) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('El cálculo de audio superó el tiempo disponible.');
            }
            $chunk = fread($f, min(16384, $remaining));
            if ($chunk === '') {
                throw new InvalidArgumentException('WAV incompleto.');
            }
            $remaining -= strlen($chunk);
            foreach (unpack('v*', $chunk) as $value) {
                $signed = $value > 32767 ? $value - 65536 : $value;
                $index = min(511, (int)floor(intdiv($sample, $format['channels']) * 512 / $frames));
                $peaks[$index] = max($peaks[$index], (int)round(abs($signed) * 1000 / 32768));
                $sample++;
            }
        }
        return ['picos' => $peaks,'duracion' => $frames / $format['rate']];
    } finally {
        fclose($f);
    }
}
/** CLI only. Array argv avoids a shell; fixed protocols/flags reject network inputs. */
function picosFFmpegLocal(string $path, float $duration, int $budget = 15): array
{
    if (str_contains($path, '://') || is_link($path) || !is_file($path)
        || !is_finite($duration) || $duration <= 0 || $duration > 21600 || $budget < 1 || $budget > 30) {
        throw new InvalidArgumentException('Origen o límites de procesamiento no válidos.');
    }
    if (PHP_SAPI !== 'cli' || !function_exists('proc_open')) {
        throw new RuntimeException('Procesamiento de audio no disponible.');
    }
    $configured = (string)getenv('MM_FFMPEG_PATH');
    $binary = $configured !== '' ? realpath($configured) : false;
    if (!$binary || !is_file($binary) || is_link($configured) || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $configured)) {
        throw new RuntimeException('Configura la ruta absoluta de FFmpeg.');
    }
    $root = realpath(APP_ROOT);
    if ($root && str_starts_with(strtolower(str_replace('\\', '/', $binary)), strtolower(str_replace('\\', '/', $root)) . '/')) {
        throw new RuntimeException('FFmpeg debe instalarse fuera de la carpeta pública.');
    }
    $command = [$binary,'-nostdin','-hide_banner','-loglevel','error','-max_alloc','67108864','-protocol_whitelist','file,pipe','-threads','1','-i',$path,'-map','0:a:0','-vn','-ac','1','-ar','8000','-f','s16le','pipe:1'];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo iniciar FFmpeg.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $peaks = array_fill(0, 512, 0);
    $sample = 0;
    $carry = '';
    $deadline = microtime(true) + $budget;
    $code = -1;
    try {
        while (true) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('FFmpeg superó el tiempo disponible.');
            }
            $chunk = fread($pipes[1], 16384);
            fread($pipes[2], 8192);
            $carry .= $chunk;
            $length = strlen($carry) - strlen($carry) % 2;
            if ($length) {
                foreach (unpack('v*', substr($carry, 0, $length)) as $value) {
                    $index = min(511, (int)floor($sample * 512 / max(1, $duration * 8000)));
                    $signed = $value > 32767 ? $value - 65536 : $value;
                    $peaks[$index] = max($peaks[$index], (int)round(abs($signed) * 1000 / 32768));
                    $sample++;
                }
                $carry = substr($carry, $length);
            }
            if ($sample > 21600 * 8000 + 8000) {
                throw new RuntimeException('Audio demasiado largo.');
            }
            $status = proc_get_status($process);
            if (!$status['running'] && $status['exitcode'] >= 0) {
                $code = $status['exitcode'];
            }
            if (!$status['running'] && feof($pipes[1])) {
                break;
            }
            if ($chunk === '') {
                usleep(10000);
            }
        }
        if ($code !== 0 || $carry !== '' || $sample === 0 || abs($sample / 8000 - $duration) > max(1, $duration * .02)) {
            throw new RuntimeException('FFmpeg no produjo una pista completa.');
        }
        return ['picos' => $peaks,'duracion' => $sample / 8000];
    } finally {
        foreach ([1,2] as $pipe) {
            if (is_resource($pipes[$pipe])) {
                fclose($pipes[$pipe]);
            }
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
    }
}
function prepararMetadatosOnda(int $uid, int $pub): void
{
    $meta = metadatosParaSala($uid, $pub);
    if (!isset($meta['metadata'])) {
        return;
    }
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT p.usuario_id,a.archivo FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.id=? FOR UPDATE');
        $st->execute([$pub]);
        $row = $st->fetch();
        if (!$row || (int)$row['usuario_id'] !== $uid || !hash_equals($meta['archivo'], $row['archivo'])) {
            throw new OutOfBoundsException('Pista no disponible.');
        }
        $st = $db->prepare('SELECT duracion FROM publicacion_medios WHERE publicacion_id=? FOR UPDATE');
        $st->execute([$pub]);
        $existing = $st->fetch();
        if (!$existing) {
            guardarMetadatosMedio($pub, $meta['metadata']);
        } elseif ($existing['duracion'] === null) {
            $db->prepare('UPDATE publicacion_medios SET sha256=?,duracion=?,mime=?,codec=? WHERE publicacion_id=?')->execute([$meta['metadata']['sha256'],$meta['metadata']['duracion'],$meta['metadata']['mime'],$meta['metadata']['codec'],$pub]);
        }
        if ($owns) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function procesarFormaOnda(int $uid, int $pub): void
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Solo cron.');
    }
    prepararMetadatosOnda($uid, $pub);
    $info = datosFormaOnda($uid, $pub);
    $meta = metadatosParaSala($uid, $pub);
    if (!$info['propia'] || !is_string($info['sha256'])) {
        throw new OutOfBoundsException('Pista propia no disponible.');
    }
    $path = realpath(musicmadMediaDirectoryForFile('audio', $meta['archivo']) . '/' . $meta['archivo']);
    $sha = hash_file('sha256', $path);
    if (!hash_equals($info['sha256'], $sha)) {
        throw new InvalidArgumentException('El archivo cambió.');
    }
    $wave = picosWavLocal($path) ?? picosFFmpegLocal($path, (float)$meta['duracion']);
    guardarFormaOnda($uid, $pub, $wave['picos'], $wave['duracion'], $sha, 'servidor');
}
