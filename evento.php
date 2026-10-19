<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/event_card.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$yo = (int)sesion('usuario_id');
$id = idCampo($_GET, 'id');
$event = eventosWorkspaceDisponible() ? eventoDisponiblePara($yo, $id, true) : null;
if ($event) {
    $st = getDB()->prepare("SELECT SUM(estado='asistire') AS asistentes,SUM(estado='interesado') AS interesados FROM evento_asistentes a JOIN usuarios u ON u.id=a.usuario_id AND u.activo=1 WHERE a.evento_id=?");
    $st->execute([$id]);
    $event += $st->fetch();
    $st = getDB()->prepare('SELECT a.estado AS mi_estado,COALESCE(p.aviso,0) AS aviso FROM evento_asistentes a LEFT JOIN musicmad_event_preferences p ON p.evento_id=a.evento_id AND p.usuario_id=a.usuario_id WHERE a.evento_id=? AND a.usuario_id=?');
    $st->execute([$id,$yo]);
    $event += ($st->fetch() ?: []);
} else {
    http_response_code(404);
}
$pageTitle = $event ? $event['titulo'] : 'Evento no disponible';
$pageCss = ['CSS/social.css','CSS/discover.css','CSS/events.css'];
$navActivo = 'eventos';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?><main class="container scene-event-detail"><a class="discover-back" href="<?= e(rutaAplicacion('eventos.php')) ?>">← Eventos de la escena</a><?php if ($event) { ?><h1 class="sr-only"><?= e($event['titulo']) ?></h1><?php renderCompartirPublico('evento', $id);
    renderEventoWorkspace($event, $yo);
} else { ?><h1>Evento no disponible</h1><p>Puede haber sido retirado o no estar disponible para tu cuenta.</p><?php } ?></main><?php require __DIR__ . '/includes/footer.php'; ?>
