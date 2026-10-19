<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/media_storage.php';
requerirSesion();
header('Cache-Control: private, no-store');

$probeReply = static function (int $status, array $result): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
$probeMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($probeMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    $probeReply(405, ['ok' => false]);
}
if ($probeMethod === 'GET' && isset($_GET['comprobar'])) {
    $token = $_GET['comprobar'];
    $probe = $_SESSION['media_storage_probe'] ?? null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token) || !is_array($probe)
        || $probe['expires'] < time() || !hash_equals($probe['token'], $token)) {
        $probeReply(404, ['ok' => false, 'stage' => 'read']);
    }
    try {
        $file = musicmadMediaDirectoryForFile('audio', $probe['name']) . '/' . $probe['name'];
        $backup = carpetaRespaldoMedios('audio') . '/' . $probe['name'];
        $ok = is_file($file) && !is_link($file) && is_file($backup) && !is_link($backup)
            && hash_equals($probe['hash'], hash_file('sha256', $file))
            && hash_equals($probe['hash'], hash_file('sha256', $backup));
        if ($ok) {
            try {
                require_once __DIR__ . '/includes/media_metadata.php';
                analizarMedioLocal($file, 'audio');
            } catch (Throwable $error) {
                error_log('MusicMad storage probe validator: ' . get_class($error));
                $probeReply(503, ['ok' => false, 'stage' => 'validator']);
            }
            try {
                // Literal zero-row queries check the schema needed by the real publication path.
                getDB()->query('SELECT publicacion_id,archivo,bytes FROM publicacion_audio LIMIT 0');
                getDB()->query('SELECT publicacion_id,archivo,bytes FROM publicacion_video LIMIT 0');
                getDB()->query('SELECT publicacion_id,sha256,duracion,mime,ancho,alto,codec FROM publicacion_medios LIMIT 0');
                getDB()->query('SELECT id,usuario_id,estado,publicacion_id,recibidos,bytes FROM musicmad_uploads LIMIT 0');
            } catch (Throwable $error) {
                error_log('MusicMad storage probe schema: ' . get_class($error));
                $probeReply(503, ['ok' => false, 'stage' => 'schema']);
            }
            if (!class_exists('finfo') || !function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
                $probeReply(503, ['ok' => false, 'stage' => 'photos']);
            }
        }
        $probeReply($ok ? 200 : 503, ['ok' => $ok, 'stage' => 'read', 'bytes' => 556]);
    } catch (Throwable $error) {
        error_log('MusicMad storage probe read: ' . get_class($error));
        $probeReply(503, ['ok' => false, 'stage' => 'read']);
    }
}
if ($probeMethod === 'POST') {
    exigirCsrf();
    $expectedOrigin = parse_url(APP_URL, PHP_URL_SCHEME) . '://' . parse_url(APP_URL, PHP_URL_HOST)
        . (parse_url(APP_URL, PHP_URL_PORT) ? ':' . parse_url(APP_URL, PHP_URL_PORT) : '');
    if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $expectedOrigin) {
        $probeReply(403, ['ok' => false, 'stage' => 'origin']);
    }
    if ((int)($_SESSION['media_storage_probe_next'] ?? 0) > time()) {
        header('Retry-After: 60');
        $probeReply(429, ['ok' => false, 'stage' => 'rate']);
    }
    $_SESSION['media_storage_probe_next'] = time() + 60;
    // Exactly one known silent WAV. This is not an alternative upload endpoint.
    $expected = 'RIFF' . pack('V', 548) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
        . 'data' . pack('V', 512) . str_repeat("\0", 512);
    $upload = $_FILES['prueba'] ?? [];
    if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) !== strlen($expected)
        || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])
        || !hash_equals(hash('sha256', $expected), hash_file('sha256', $upload['tmp_name']))) {
        $probeReply(422, ['ok' => false, 'stage' => 'receive']);
    }
    $stage = 'primary';
    try {
        $name = 'probe_' . bin2hex(random_bytes(24)) . '.wav';
        $file = musicmadUploadDirectory('audio') . '/' . $name;
        if (file_exists($file) || !move_uploaded_file($upload['tmp_name'], $file)) {
            throw new RuntimeException('Probe write failed.');
        }
        @chmod($file, 0600);
        $stage = 'backup';
        respaldarMedioGuardado('audio', $name);
        $token = bin2hex(random_bytes(32));
        $_SESSION['media_storage_probe'] = ['name' => $name, 'hash' => hash('sha256', $expected),
            'token' => $token, 'expires' => time() + 86400];
        $probeReply(200, ['ok' => true, 'stage' => 'backup', 'token' => $token, 'bytes' => strlen($expected)]);
    } catch (Throwable $error) {
        error_log('MusicMad storage probe ' . $stage . ': ' . get_class($error));
        $probeReply(503, ['ok' => false, 'stage' => $stage]);
    }
}
$pageTitle = 'Comprobar archivos';
$pageCss = ['CSS/social.css', 'CSS/account.css'];
$pageJs = ['js/storage-probe.js'];
$ownMedia = getDB()->prepare('SELECT p.id,p.tipo,a.archivo AS audio,v.archivo AS video FROM publicaciones p
    LEFT JOIN publicacion_audio a ON a.publicacion_id=p.id
    LEFT JOIN publicacion_video v ON v.publicacion_id=p.id
    WHERE p.usuario_id=? ORDER BY p.id DESC LIMIT 10');
$ownMedia->execute([(int)sesion('usuario_id')]);
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container narrow settings-page">
  <h1>Comprobar archivos</h1>
  <section class="card settings-panel">
    <h2>Subida, respaldo y lectura</h2>
    <p>Envía un audio de prueba de 556 bytes y comprueba que siga guardado al abrir otra petición. No crea publicaciones ni cambia tus fotos.</p>
    <button class="btn btn-primary" type="button" data-storage-probe>Comprobar almacenamiento</button>
    <p role="status" aria-live="polite" data-storage-probe-result></p>
  </section>
  <section class="card settings-panel">
    <h2>Tus últimas publicaciones</h2>
    <p>Comprueba si cada publicación tiene su archivo registrado y disponible.</p>
    <ul>
<?php foreach ($ownMedia->fetchAll() as $item): ?>
      <li><a href="<?= e(rutaAplicacion('publicacion.php?id=' . (int)$item['id'])) ?>">Publicación #<?= (int)$item['id'] ?></a>
<?php foreach (['audio', 'video'] as $kind):
    if (empty($item[$kind])) {
        continue;
    }
    try {
        $directory = musicmadMediaDirectoryForFile($kind === 'video' ? 'videos' : 'audio', $item[$kind]);
        $present = is_file($directory . '/' . $item[$kind]) && !is_link($directory . '/' . $item[$kind]);
    } catch (Throwable $error) {
        $present = false;
    }
    ?>
        <span><?= $kind === 'audio' ? 'Audio' : 'Video' ?>: <?= $present ? 'archivo disponible' : 'falta el archivo' ?></span>
<?php endforeach; ?>
<?php if (empty($item['audio']) && empty($item['video'])): ?><span>Sin audio ni video adjunto</span><?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
  </section>
  <a href="<?= e(rutaAplicacion('configuracion.php')) ?>">Volver a configuración</a>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
