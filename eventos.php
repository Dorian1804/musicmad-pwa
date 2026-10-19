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
$ready = eventosWorkspaceDisponible();
$view = textoCampo($_GET, 'ver') === 'agenda' ? 'agenda' : 'todos';
$city = mb_substr(trim(textoCampo($_GET, 'ciudad')), 0, 80);
$events = $ready ? listarEventosWorkspace($yo, ['ver' => $view,'ciudad' => $city]) : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'Eventos';
$pageCss = ['CSS/social.css','CSS/discover.css','CSS/events.css'];
$navActivo = 'eventos';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container scene-events-page"><header class="discover-heading"><span class="community-kicker">MusicMad / En vivo</span><h1>El siguiente encuentro<br>ya está sonando.</h1><p>Tocadas, jams y encuentros. Organiza, confirma y lleva tu música afuera.</p><a class="btn btn-primary" href="<?= e(rutaAplicacion('mapa.php')) ?>">Explorar la escena ↗</a></header><?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></p><?php endif; ?>
<?php if (!$ready): ?><p class="card">Los nuevos eventos estarán disponibles al terminar la actualización.</p><?php else: ?>
<details class="card quick-sheet" id="nuevo-evento" data-sheet data-sheet-title="Tu próxima tocada"><summary><strong>Organizar un evento</strong><span aria-hidden="true">＋</span></summary><?php formularioEventoWorkspace(); ?></details>
<div class="scene-events-toolbar"><nav class="discover-tabs" aria-label="Eventos"><a href="<?= e(rutaAplicacion('eventos.php')) ?>" <?= $view === 'todos' ? 'aria-current="page"' : '' ?>>La escena</a><a href="<?= e(rutaAplicacion('eventos.php?ver=agenda')) ?>" <?= $view === 'agenda' ? 'aria-current="page"' : '' ?>>Mi agenda</a></nav><form method="GET"><input type="hidden" name="ver" value="<?= $view ?>"><label class="sr-only" for="evento-ciudad">Ciudad</label><input id="evento-ciudad" type="search" name="ciudad" maxlength="80" value="<?= e($city) ?>" placeholder="Ciudad"><button class="btn btn-ghost" type="submit">Filtrar</button></form></div><p class="scene-events-count"><?= count($events) ?> <?= count($events) === 1 ? 'encuentro visible' : 'encuentros visibles' ?> · horarios en la zona del evento</p>
<?php if (!$events): ?><div class="card vacio"><h2>El próximo escenario está libre.</h2><p><?= $view === 'agenda' ? 'Los eventos que organices o en los que respondas aparecerán aquí.' : 'Publica una tocada o encuentra encuentros en otra ciudad.' ?></p></div><?php endif; ?>
<div class="scene-events-grid"><?php foreach ($events as $event) {
    renderEventoWorkspace($event, $yo);
} ?></div><?php endif; ?></main><?php require __DIR__ . '/includes/footer.php'; ?>
