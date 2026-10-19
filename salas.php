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
$ready = salasEscuchaDisponibles();
$rooms = listarSalasEscucha($uid);
$q = mb_substr(trim(textoCampo($_GET, 'q')), 0, 80);
$tracks = [];
if ($ready) {
    [$policy,$params] = filtroAccesoContenido($uid, 'p.usuario_id', 'room_track_choices');
    $st = getDB()->prepare('SELECT p.id,p.titulo,CONCAT(u.nombre,\' \',u.apellido) AS artista FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.titulo LIKE :q AND ' . $policy . ' ORDER BY p.id DESC LIMIT 100');
    $st->execute([':q' => '%' . addcslashes($q, '%_\\') . '%'] + $params);
    $tracks = $st->fetchAll();
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'Salas de escucha';
$pageCss = ['CSS/social.css','CSS/account.css','CSS/rooms.css'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container rooms-page"><header class="rooms-hero"><span>EN VIVO / MUSICMAD</span><h1>Escucha.<br>Comparte el momento.</h1><p>La misma pista, al mismo tiempo, con músicos de tu comunidad.</p><?php if ($ready): ?><a class="btn btn-primary" href="<?= e(anclaAplicacion('#crear-sala')) ?>">Abrir una sala ↗</a><?php endif; ?></header><?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></p><?php endif; ?>
<?php if (!$ready): ?><section class="card"><h2>Falta activar esta actualización</h2><p>Importa la migración021 de salas de escucha.</p></section><?php else: ?><section class="rooms-grid"><?php foreach ($rooms as $room): ?><a class="room-discovery-card" href="<?= e(rutaAplicacion('sala.php?id=' . (int)$room['id'])) ?>"><span class="room-live-dot">● <?= $room['modo'] === 'publica' ? 'Abierta' : 'Por enlace' ?></span><h2><?= e($room['nombre']) ?></h2><p><?= e($room['titulo']) ?></p><small>Con <?= e($room['anfitrion']) ?></small><b aria-hidden="true">↗</b></a><?php endforeach; ?><?php if (!$rooms): ?><article class="card"><h2>Abre el primer encuentro</h2><p>Invita a otros músicos a escuchar una pista y platicar sobre ella.</p></article><?php endif; ?></section>
<details data-sheet data-sheet-title="Abrir una sala" id="crear-sala" class="rooms-create"><summary class="btn btn-primary">Crear sala de escucha <span aria-hidden="true">＋</span></summary><form method="POST" action="<?= e(rutaAplicacion('sala-accion.php', 'POST')) ?>" class="account-confirm-form"><?= csrfCampo() ?><input type="hidden" name="accion" value="crear"><label for="sala-nombre">Nombre de la sala</label><input id="sala-nombre" name="nombre" minlength="2" maxlength="100" placeholder="Escuchemos mi nuevo demo" required><label for="sala-pista">Pista que escucharemos</label><select id="sala-pista" name="publicacion" required><option value="">Elige una pista</option><?php foreach ($tracks as $track): ?><option value="<?= (int)$track['id'] ?>"><?= e($track['titulo'] . ' · ' . $track['artista']) ?></option><?php endforeach; ?></select><label for="sala-modo">Quién puede entrar</label><select id="sala-modo" name="modo"><option value="enlace">Quienes tengan mi enlace</option><option value="publica">Músicos que puedan escuchar la pista</option></select><p>Hasta 30 personas. La sala dura 24 horas; puedes terminarla antes. La privacidad de la pista sigue vigente.</p><button type="submit" class="btn btn-primary">Abrir sala</button></form></details>
<details class="rooms-track-search"><summary>Buscar otra pista para la sala</summary><form method="GET" action="<?= e(rutaAplicacion('salas.php', 'GET')) ?>" class="account-confirm-form"><label for="salas-q">Título</label><input id="salas-q" name="q" value="<?= e($q) ?>" maxlength="80"><button type="submit" class="btn btn-ghost">Buscar pistas</button></form></details><?php endif; ?></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
