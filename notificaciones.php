<?php
// ============================================================
//  MusicMad – Notificaciones
//  notificaciones.php
// ============================================================
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/chat_live.php';

requerirSesion();
$yo = (int) sesion('usuario_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $accion = textoCampo($_POST, 'accion');
    if ($accion === 'leer_todas') {
        marcarNotificacionesLeidas($yo);
        marcarAvisosEventosHasta($yo, PHP_INT_MAX);
    } elseif ($accion === 'responder_solicitud' && social3Disponible()) {
        $ok = responderSolicitudSeguimiento($yo, idCampo($_POST, 'solicitud'), textoCampo($_POST, 'decision') === 'aceptar');
        $_SESSION['flash'] = [$ok ? 'success' : 'error', $ok ? 'Solicitud de seguimiento actualizada.' : 'No se pudo actualizar la solicitud.'];
    }
    header('Location: notificaciones.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}

$lista = array_merge(listarNotificaciones($yo), listarAvisosEventos($yo));
usort($lista, static fn ($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: ($b['id'] <=> $a['id']));
$lista = array_slice($lista, 0, 50);
$solicitudes = social3Disponible() ? solicitudesPendientesSeguimiento($yo) : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$normalIds = array_column(array_filter($lista, static fn ($n) => empty($n['_origen'])), 'id');
$eventIds = array_column(array_filter($lista, static fn ($n) => ($n['_origen'] ?? '') === 'evento'), 'id');
$hastaAviso = $normalIds ? (int)max($normalIds) : 0;
$hastaEvento = $eventIds ? (int)max($eventIds) : 0;
$pageJs = ['js/chat.js'];

$pageTitle = 'Notificaciones';
$pageCss = ['CSS/social.css'];
$navActivo = 'notificaciones';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="container narrow-wide" <?= chatLiveDisponible() ? 'data-open-notifications="' . $hastaAviso . '" data-open-events="' . $hastaEvento . '"' : '' ?>>
  <h1 class="page-title">Notificaciones</h1>
  <noscript><form method="POST" action="<?= e(rutaAplicacion('notificaciones.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="leer_todas"><button type="submit" class="btn btn-ghost btn-sm">Marcar todas como leídas</button></form></noscript>
  <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
  <?php if ($solicitudes): ?>
    <section class="card follow-requests">
      <h2>Solicitudes para seguirte</h2>
      <ul>
        <?php foreach ($solicitudes as $solicitud): ?>
          <li><a href="<?= e(rutaAplicacion('musico.php?id=' . (int) $solicitud['solicitante_id'])) ?>"><img src="<?= e(musicmadPublicImageUrl($solicitud['avatar'])) ?>" alt=""><span><strong><?= e($solicitud['nombre'] . ' ' . $solicitud['apellido']) ?></strong><small><?= e($solicitud['instrumento'] ?: 'Músico') ?> · <?= e(hace($solicitud['created_at'])) ?></small></span></a>
            <div class="follow-request-actions"><?php foreach (['aceptar' => 'Aceptar', 'rechazar' => 'Eliminar'] as $decision => $etiqueta): ?><form method="POST" action="<?= e(rutaAplicacion('notificaciones.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="responder_solicitud"><input type="hidden" name="solicitud" value="<?= (int) $solicitud['id'] ?>"><input type="hidden" name="decision" value="<?= e($decision) ?>"><button type="submit" class="btn <?= $decision === 'aceptar' ? 'btn-primary' : 'btn-ghost' ?> btn-sm"><?= e($etiqueta) ?></button></form><?php endforeach; ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
  <?php if (!$lista && !$solicitudes): ?>
    <div class="card vacio"><p>Todavía no tienes notificaciones. Cuando alguien aplauda, comente o te siga, aparecerá aquí.</p></div>
  <?php else: ?>
    <ul class="card notif-lista">
      <?php foreach ($lista as $n): [$texto, $url] = ($n['_origen'] ?? '') === 'evento' ? textoAvisoEvento($n) : textoNotificacion($n, $yo); ?>
        <li class="<?= $n['leida'] ? '' : 'nueva' ?>">
          <a href="<?= e($url) ?>">
            <img src="<?= e(musicmadPublicImageUrl($n['avatar'])) ?>" alt="" class="post-avatar">
            <span><?= e($texto) ?><small><?= e(hace($n['created_at'])) ?></small></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
