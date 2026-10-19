<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
$uid = (int)sesion('usuario_id');
$id = idCampo($_GET, 'id');
$room = salaDisponiblePara($uid, $id);
$member = $room && integranteSala($uid, $id);
$code = textoCampo($_GET, 'codigo');
if (!$room || (!$member && $room['modo'] === 'enlace' && !hash_equals($room['codigo'], $code))) {
    http_response_code(404);
    $pageTitle = 'Sala no disponible';
    $pageCss = ['CSS/social.css'];
    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/navbar.php';?><main class="container"><section class="card vacio"><h1>Esta sala no está disponible</h1><p>Puede haber terminado o necesitar su enlace de invitación.</p><a class="btn btn-primary" href="<?= e(rutaAplicacion('salas.php')) ?>">Explorar salas</a></section></main><?php require __DIR__ . '/includes/footer.php';
    exit;
}
$host = (int)$room['anfitrion_id'] === $uid;
$pageTitle = $room['nombre'];
$pageCss = ['CSS/social.css','CSS/account.css','CSS/rooms.css'];
$pageJs = ['js/rooms.js'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container room-page" <?= $member ? 'data-listening-room' : '' ?> data-room-id="<?= $id ?>" data-room-owner="<?= $uid ?>" data-room-host="<?= $host ? '1' : '0' ?>"><a class="account-back" href="<?= e(rutaAplicacion('salas.php')) ?>">← Salas de escucha</a><?php if ($room['modo'] === 'publica') {
    renderCompartirPublico('sala', $id);
} ?><header class="room-heading"><span class="room-live-dot">● SALA EN VIVO</span><h1><?= e($room['nombre']) ?></h1><p>Con <?= e($room['anfitrion']) ?> · <?= $room['modo'] === 'publica' ? 'Sala abierta' : 'Por enlace de invitación' ?></p></header>
<?php if (!$member): ?><section class="card room-join"><h2><?= e($room['titulo']) ?></h2><p><?= e($room['artista']) ?></p><p>Al entrar, las otras personas de la sala verán tu nombre y participación.</p><form method="POST" action="<?= e(rutaAplicacion('sala-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="entrar"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="codigo" value="<?= e($code) ?>"><button class="btn btn-primary" type="submit">Entrar a escuchar</button></form></section><?php else: ?>
<div class="room-layout"><section class="room-deck"><div class="room-record" aria-hidden="true"><span>MM</span></div><span class="room-track-label">AHORA EN LA SALA</span><h2><?= e($room['titulo']) ?></h2><p><?= e($room['artista']) ?></p><button class="btn btn-primary" type="button" data-room-listen data-track-play data-track-id="<?= (int)$room['publicacion_id'] ?>" data-track-src="audio.php?id=<?= (int)$room['publicacion_id'] ?>" data-track-title="<?= e($room['titulo']) ?>" data-track-artist="<?= e($room['artista']) ?>">Conectarme al sonido</button><p class="room-sync-state" data-room-status role="status">Toca para activar el sonido. La reproducción sigue al anfitrión.</p><p class="room-clock" data-room-clock>0:00</p>
<?php if ($host): ?><div class="room-host-controls"><button type="button" data-room-control="reproduciendo" class="btn btn-primary">▶ Reproducir para todos</button><button type="button" data-room-control="pausada" class="btn btn-ghost">Pausar sala</button><label for="room-seek">Ir al segundo<input id="room-seek" type="number" min="0" max="<?= (int)floor((float)$room['duracion']) ?>" step="1" value="0" data-room-seek></label><button type="button" class="btn btn-ghost" data-room-seek-button>Mover la sala</button></div><details data-sheet data-sheet-title="Invita a escuchar"><summary class="btn btn-ghost">Enlace de invitación ↗</summary><label>Invita con este enlace<input readonly value="<?= e(rtrim(APP_URL, '/') . '/sala.php?id=' . $id . '&codigo=' . $room['codigo']) ?>"></label><p>El enlace dura lo mismo que la sala. Las personas deben iniciar sesión y tener acceso a la pista.</p></details><?php endif; ?></section>
<section class="room-conversation"><header><h2>Entre músicos</h2><p data-room-members>Comprobando participantes…</p></header><div class="room-message-list" data-room-messages role="log" aria-label="Conversación de la sala" aria-relevant="additions"><p data-room-empty>Una pista también inicia una conversación.</p></div><form method="POST" action="<?= e(rutaAplicacion('sala-accion.php', 'POST')) ?>" data-room-message-form data-native-navigation><?= csrfCampo() ?><input type="hidden" name="accion" value="mensaje"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><label class="sr-only" for="room-message-text">Mensaje a la sala</label><textarea id="room-message-text" name="texto" maxlength="500" rows="2" placeholder="¿Qué te está gustando de la pista?" required></textarea><button class="btn btn-primary" type="submit">Enviar</button></form></section></div>
<form method="POST" action="sala-accion.php" class="room-leave" data-confirm="<?= $host ? '¿Terminar la sala para todos?' : '¿Salir de esta sala?' ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="<?= $host ? 'cerrar' : 'salir' ?>"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-ghost" type="submit"><?= $host ? 'Terminar sala' : 'Salir de la sala' ?></button></form><?php endif; ?></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
