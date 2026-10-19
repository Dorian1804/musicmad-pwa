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
$ready = playlistsEquipoDisponible();
$list = $id ? obtenerPlaylistSocial3($id, $uid) : null;
$can = $list && puedeEditarPlaylist($uid, $list);
if ($id && !$can) {
    http_response_code(404);
}
$query = mb_substr(trim(textoCampo($_GET, 'q')), 0, 80);
$candidates = $can && (int)$list['usuario_id'] === $uid ? candidatosInvitacionPlaylist($uid, $query) : [];
$members = $can ? equipoPlaylist($uid, $id) : [];
$pending = $ready ? listasEquipoUsuario($uid, true) : [];
$shared = $ready ? listasEquipoUsuario($uid) : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'Colecciones compartidas';
$pageCss = ['CSS/social.css','CSS/playlist-team.css'];
$navActivo = 'listas';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container playlist-team-page"><header class="playlist-team-hero"><span>COLECCIONES / EN EQUIPO</span><h1>Una lista.<br>Más ideas.</h1><p>Prepara el próximo ensayo o reúne descubrimientos con otros músicos.</p><a class="btn btn-ghost" href="<?= e(rutaAplicacion('listas.php')) ?>">Mis playlists →</a></header>
<?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></p><?php endif; ?>
<?php if (!$ready): ?><section class="card"><h2>Las colecciones compartidas estarán disponibles pronto.</h2></section><?php elseif ($id && !$can): ?><section class="card"><h2>Esta colección no está disponible.</h2><p>La invitación pudo retirarse o cambiar el acceso.</p></section><?php elseif ($can): ?>
<section class="card playlist-team-panel"><h2><?= e($list['nombre']) ?></h2><p>El creador puede administrar el equipo y quitar cualquier pista. Cada colaborador puede añadir pistas y retirar las que añadió. Las publicaciones y los archivos originales se conservan.</p><a class="btn btn-primary" href="listas.php?id=<?= $id ?>">Abrir colección →</a>
<?php if ((int)$list['usuario_id'] === $uid): ?><form method="GET" class="playlist-team-search"><input type="hidden" name="id" value="<?= $id ?>"><label for="invite-search">Buscar músicos<input type="search" name="q" id="invite-search" maxlength="80" value="<?= e($query) ?>" placeholder="Nombre del músico"></label><button type="submit" class="btn btn-ghost">Buscar</button></form><?php if (!$candidates): ?><p>No encontramos músicos disponibles con ese nombre.</p><?php endif; ?><details data-sheet data-sheet-title="Invitar a mi playlist"><summary class="btn btn-ghost">Invitar a un músico</summary><form method="POST" action="<?= e(rutaAplicacion('playlist-equipo-accion.php', 'POST')) ?>" class="settings-form"><?= csrfCampo() ?><input type="hidden" name="accion" value="invitar"><input type="hidden" name="id" value="<?= $id ?>"><label for="playlist-musician">Músico<select id="playlist-musician" name="musico" required><option value="">Elige a quién invitar</option><?php foreach ($candidates as $candidate): ?><option value="<?= (int)$candidate['id'] ?>"><?= e($candidate['nombre'] . ' ' . $candidate['apellido']) ?></option><?php endforeach; ?></select></label><p>Recibirá una invitación en Colecciones compartidas. No se añade sin aceptar.</p><button class="btn btn-primary" type="submit">Enviar invitación</button></form></details>
<?php else: ?><form method="POST" action="<?= e(rutaAplicacion('playlist-equipo-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="salir"><input type="hidden" name="id" value="<?= $id ?>"><button type="submit" class="btn btn-ghost">Salir de esta colección</button></form><?php endif; ?>
<h3>El equipo</h3><ul class="playlist-team-members"><li><strong><?= e($list['autor'] . ' ' . $list['autor_apellido']) ?></strong><span>Creador</span></li><?php foreach ($members as $member): ?><li><a href="<?= e(rutaAplicacion('musico.php?id=' . (int)$member['usuario_id'])) ?>"><?= e($member['nombre'] . ' ' . $member['apellido']) ?></a><span><?= $member['estado'] === 'aceptada' ? 'Colaborador' : 'Invitación pendiente' ?></span><?php if ((int)$list['usuario_id'] === $uid): ?><form method="POST" action="<?= e(rutaAplicacion('playlist-equipo-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="revocar"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="musico" value="<?= (int)$member['usuario_id'] ?>"><button class="btn btn-ghost" type="submit">Retirar</button></form><?php endif; ?></li><?php endforeach; ?></ul></section>
<?php else: ?>
<section class="playlist-team-panel"><h2>Invitaciones</h2><?php if (!$pending): ?><p>No tienes invitaciones pendientes. Cuando te inviten, aparecerán aquí.</p><?php endif; ?><?php foreach ($pending as $invitation): ?><article class="card"><h3><?= e($invitation['nombre']) ?></h3><p>De <?= e($invitation['autor'] . ' ' . $invitation['autor_apellido']) ?></p><form method="POST" action="<?= e(rutaAplicacion('playlist-equipo-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="id" value="<?= (int)$invitation['id'] ?>"><button class="btn btn-primary" type="submit" name="accion" value="aceptar">Aceptar invitación</button><button class="btn btn-ghost" type="submit" name="accion" value="rechazar">Ahora no</button></form></article><?php endforeach; ?></section>
<section class="playlist-team-panel"><h2>Compartidas conmigo</h2><?php if (!$shared): ?><p>Todavía no colaboras en una playlist. También puedes invitar desde una lista tuya.</p><?php endif; ?><?php foreach ($shared as $item): ?><a class="card playlist-team-link" href="<?= e(rutaAplicacion('playlist-equipo.php?id=' . (int)$item['id'])) ?>"><strong><?= e($item['nombre']) ?></strong><span><?= e($item['autor'] . ' ' . $item['autor_apellido']) ?> ↗</span></a><?php endforeach; ?></section>
<?php endif; ?></main><?php require __DIR__ . '/includes/footer.php'; ?>
