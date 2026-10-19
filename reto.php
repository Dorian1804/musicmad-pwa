<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$uid = (int)sesion('usuario_id');
$id = idCampo($_GET, 'id');
$reto = retoPara($uid, $id);
if (!$reto) {
    http_response_code(404);
    $pageTitle = 'Reto no disponible';
    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/navbar.php';
    echo '<main class="container"><h1>Este reto no está disponible</h1><a href="retos.php">Ver retos</a></main>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
$page = max(1, min(9, idCampo($_GET, 'pagina') ?: 1));
$more = false;
$entries = rankingReto($uid, $id, $page, $more);
$myVote = miVotoReto($uid, $id);
$st = getDB()->prepare('SELECT publicacion_id FROM reto_participaciones WHERE reto_id=? AND usuario_id=?');
$st->execute([$id,$uid]);
$mine = (int)$st->fetchColumn();
$tracks = $reto['fase'] === 'participacion' ? pistasPropiasCreativas($uid, true) : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = $reto['titulo'];
$pageCss = ['CSS/social.css','CSS/account.css','CSS/challenges.css'];
$staff = puedeModerar($uid);
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
$phase = ['proximo' => 'PRÓXIMAMENTE','participacion' => 'ENTREGA ABIERTA','votacion' => 'VOTACIÓN ABIERTA','resultados' => 'RESULTADOS','cancelado' => 'RETO CANCELADO','borrador' => 'BORRADOR'];
?>
<main class="container challenge-page"><a class="challenge-back" href="<?= e(rutaAplicacion('retos.php')) ?>">← Retos de la escena</a><header class="challenge-hero challenge-detail-hero"><span><?= e($phase[$reto['fase']]) ?></span><h1><?= e($reto['titulo']) ?></h1><p><?= nl2br(e($reto['descripcion'])) ?></p></header>
<?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></p><?php endif; ?>
<dl class="challenge-calendar"><div><dt>Comienza</dt><dd><time datetime="<?= e(str_replace(' ', 'T', $reto['inicio']) . 'Z') ?>"><?= e($reto['inicio']) ?></time></dd></div><div><dt>Entrega hasta / vota desde</dt><dd><time datetime="<?= e(str_replace(' ', 'T', $reto['votacion_desde']) . 'Z') ?>"><?= e($reto['votacion_desde']) ?></time></dd></div><div><dt>Cierre</dt><dd><time datetime="<?= e(str_replace(' ', 'T', $reto['fin']) . 'Z') ?>"><?= e($reto['fin']) ?></time></dd></div></dl><p class="challenge-note">Fechas en UTC. Una pista por músico, de hasta tres minutos. Un voto por cuenta; puedes cambiarlo durante la votación. El ranking omite pistas que dejan de ser públicas o disponibles y votos de cuentas inactivas o bloqueadas.</p>
<?php if ($reto['fase'] === 'participacion'): ?><details data-sheet data-sheet-title="Tu pista para el reto" id="participar"><summary class="btn btn-primary"><?= $mine ? 'Cambiar mi participación' : 'Participar con mi pista' ?> ＋</summary><form action="<?= e(rutaAplicacion('reto-accion.php', 'POST')) ?>" method="POST" class="account-confirm-form"><?= csrfCampo() ?><input type="hidden" name="accion" value="participar"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><label for="challenge-track">Tu audio o video</label><select id="challenge-track" name="pista" required><option value="">Elige una pista tuya</option><?php foreach ($tracks as $track): if ((float)$track['duracion'] > 180) {
    continue;
} ?><option value="<?= (int)$track['id'] ?>"><?= e($track['titulo']) ?></option><?php endforeach; ?></select><p>Tu perfil debe ser público. La pista conserva sus permisos; no hacemos públicas tus otras publicaciones.</p><button class="btn btn-primary">Enviar mi pista</button><a href="<?= e(rutaAplicacion('feed.php#compartir')) ?>">Subir una pista primero</a></form></details><?php endif; ?>
<?php if ($mine): ?><details class="challenge-admin"><summary>Mi participación</summary><a href="publicacion.php?id=<?= $mine ?>">Abrir mi pista ↗</a><form method="POST" action="<?= e(rutaAplicacion('reto-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="retirar"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><button class="btn btn-ghost">Retirar del reto</button><p>Tu publicación y su archivo se conservan.</p></form></details><?php endif; ?>
<?php if ($myVote && $reto['fase'] === 'votacion'): ?><form action="<?= e(rutaAplicacion('reto-accion.php', 'POST')) ?>" method="POST" class="challenge-vote-note"><?= csrfCampo() ?><input type="hidden" name="accion" value="votar"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="participante" value="0"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><span>Ya elegiste una pista. Puedes votar por otra o retirar tu voto.</span><button class="btn btn-ghost">Retirar mi voto</button></form><?php endif; ?>
<section class="challenge-ranking" aria-label="Pistas del reto"><header><span>LA ESCENA RESPONDE</span><h2><?= $reto['fase'] === 'resultados' ? 'Así sonó el reto' : 'Escucha las ideas' ?></h2><p>Orden por votos, fecha de entrega e identificador. Solo vemos las participaciones disponibles para tu cuenta.</p></header><?php foreach ($entries as $i => $entry): ?><article class="challenge-entry"><span class="challenge-place" aria-label="Posición visible <?= ($page - 1) * 30 + $i + 1 ?>"><?= str_pad((string)(($page - 1) * 30 + $i + 1), 2, '0', STR_PAD_LEFT) ?></span><div class="challenge-entry-copy"><a href="<?= e(rutaAplicacion('publicacion.php?id=' . (int)$entry['publicacion_id'])) ?>"><h3><?= e($entry['titulo']) ?></h3></a><a href="<?= e(rutaAplicacion('musico.php?id=' . (int)$entry['usuario_id'])) ?>"><?= e($entry['artista']) ?></a><small><?= (int)$entry['votos'] ?> <?= (int)$entry['votos'] === 1 ? 'voto' : 'votos' ?> · <?= e($entry['medio']) ?></small></div><?php if ($entry['medio'] === 'audio'): ?><button type="button" class="btn btn-ghost challenge-listen" data-track-play data-track-id="<?= (int)$entry['publicacion_id'] ?>" data-track-src="audio.php?id=<?= (int)$entry['publicacion_id'] ?>" data-track-title="<?= e($entry['titulo']) ?>" data-track-artist="<?= e($entry['artista']) ?>" aria-label="Escuchar <?= e($entry['titulo']) ?>">Escuchar ▶</button><?php else: ?><a class="btn btn-ghost challenge-listen" href="<?= e(rutaAplicacion('publicacion.php?id=' . (int)$entry['publicacion_id'])) ?>">Ver video ↗</a><?php endif; ?><?php if ($reto['fase'] === 'votacion' && (int)$entry['usuario_id'] !== $uid): ?><form action="<?= e(rutaAplicacion('reto-accion.php', 'POST')) ?>" method="POST"><?= csrfCampo() ?><input type="hidden" name="accion" value="votar"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="participante" value="<?= (int)$entry['usuario_id'] ?>"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><button class="btn <?= $myVote === (int)$entry['usuario_id'] ? 'btn-primary' : 'btn-ghost' ?>" aria-pressed="<?= $myVote === (int)$entry['usuario_id'] ? 'true' : 'false' ?>" aria-label="Votar por <?= e($entry['artista']) ?>"><?= $myVote === (int)$entry['usuario_id'] ? 'Tu voto ✓' : 'Votar' ?></button></form><?php endif; ?></article><?php endforeach; ?><?php if (!$entries): ?><div class="challenge-empty"><h3>Todavía no hay pistas que mostrar</h3><p><?= $reto['fase'] === 'participacion' ? 'Tu idea puede ser la primera.' : 'Vuelve a los retos para encontrar la próxima consigna.' ?></p></div><?php endif; ?></section>
<nav class="challenge-pagination" aria-label="Páginas de participaciones"><?php if ($page > 1): ?><a class="btn btn-ghost" href="reto.php?id=<?= $id ?>&amp;pagina=<?= $page - 1 ?>">← Anterior</a><?php endif; ?><?php if ($more): ?><a class="btn btn-ghost" href="reto.php?id=<?= $id ?>&amp;pagina=<?= $page + 1 ?>">Más pistas →</a><?php endif; ?></nav>
<?php if ($staff): ?><details class="challenge-admin"><summary>Administrar este reto</summary><?php foreach ($reto['estado'] === 'borrador' ? ['publicar' => 'Publicar reto','cancelar' => 'Cancelar reto'] : ['cancelar' => 'Cancelar reto'] as $action => $label): ?><form action="<?= e(rutaAplicacion('reto-accion.php', 'POST')) ?>" method="POST"><?= csrfCampo() ?><input type="hidden" name="accion" value="<?= $action ?>"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-ghost"><?= e($label) ?></button></form><?php endforeach; ?></details><?php endif; ?></main><?php require __DIR__ . '/includes/footer.php'; ?>
