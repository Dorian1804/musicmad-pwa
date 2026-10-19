<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/publicacion.php';
require_once __DIR__ . '/includes/band_forms.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$yo = (int)sesion('usuario_id');
$id = idCampo($_GET, 'id');
$ready = bandasWorkspaceDisponible();
$banda = bandaDisponible($yo, $id);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
if (!$banda) {
    http_response_code(404);
    $pageTitle = 'Banda no disponible';
    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/navbar.php';
    echo '<main class="container"><h1>Banda no disponible</h1><p>Puede estar archivada o no disponible para tu cuenta.</p><a class="btn btn-primary" href="bandas.php">Explorar bandas</a></main>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
$settings = configuracionBanda($id);
$miRol = rolEnBanda($yo, $id);
$admin = $miRol === 'admin';
$coord = in_array($miRol, ['admin','coordinador'], true);
$tab = textoCampo($_GET, 'tab') ?: 'publicaciones';
if (!in_array($tab, ['publicaciones','integrantes','agenda','gestion'], true) || (!$miRol && in_array($tab, ['agenda','gestion'], true)) || (!$admin && $tab === 'gestion')) {
    $tab = 'publicaciones';
}
$miembros = miembrosBanda($id);
$visibleMembers = array_values(array_filter($miembros, static fn ($m) => puedeVerPerfilSocial3($yo, (int)$m['usuario_id'])));
$before = idCampo($_GET, 'antes');
$posts = $ready && $tab === 'publicaciones' ? publicacionesBanda($yo, $id, $before) : [];
$comments = comentariosDe(array_column($posts, 'id'));
$ensayos = $ready && $miRol && $tab === 'agenda' ? ensayosBanda($yo, $id) : [];
$inviteQuery = mb_substr(trim(textoCampo($_GET, 'invq')), 0, 80);
$invites = $ready && $admin && $tab === 'gestion' ? invitacionesAdministradasBanda($yo, $id) : [];
$volver = 'banda.php?id=' . $id;
$pageTitle = $banda['nombre'];
$pageCss = ['CSS/social.css','CSS/bands.css'];
$pageJs = ['js/upload-progress.js','js/chat.js'];
$pageProfileIds = array_column($posts, 'usuario_id');
$navActivo = 'bandas';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container band-page">
<a class="band-back" href="<?= e(rutaAplicacion('bandas.php')) ?>">← Todas las bandas</a>
<?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></p><?php endif; ?>
<?php renderCompartirPublico('banda', $id); ?>
<header class="band-profile" data-band-color="<?= e($settings['color']) ?>"><div class="band-profile-art"><span aria-hidden="true">♫</span><small>MusicMad / Colectivo</small></div><div class="band-profile-info"><div class="band-monogram" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($banda['nombre'], 0, 2))) ?></div><div class="band-identity"><span class="band-eyebrow"><?= $miRol ? e(BAND_ROLES[$miRol]) : 'Banda' ?></span><h1><?= e($banda['nombre']) ?></h1><p><?= e($banda['genero'] ?: 'Un sonido propio') ?><?= $banda['ciudad'] !== '' ? ' · ' . e($banda['ciudad']) : '' ?> · <?= count($miembros) ?> <?= count($miembros) === 1 ? 'integrante' : 'integrantes' ?></p></div></div><div class="band-profile-description"><?php if ($banda['descripcion'] !== ''): ?><p><?= nl2br(e($banda['descripcion'])) ?></p><?php endif; ?><div class="band-profile-actions"><?php if ($miRol && $ready): ?><a href="banda-chat-vista.php?id=<?= $id ?>" class="btn btn-primary">Chat de la banda ↗</a><a href="banda.php?id=<?= $id ?>&amp;tab=agenda" class="btn btn-ghost">Agenda de ensayos</a><?php elseif ($ready && $settings['ingreso'] === 'abierto'): ?><details class="quick-sheet" data-sheet data-sheet-title="Unirte a la banda"><summary class="btn btn-primary">Quiero tocar aquí ＋</summary><?php abrirAccionBanda($id, 'unirse'); ?><label>Tu instrumento<input name="instrumento" maxlength="60"></label><button class="btn btn-primary" type="submit">Unirme a la banda</button></form></details><?php elseif ($ready): ?><span class="band-entry-label">Ingreso por invitación</span><?php endif; ?></div></div></header>
<nav class="band-tabs" aria-label="Secciones de la banda"><?php foreach (['publicaciones' => 'Publicaciones','integrantes' => 'Integrantes','agenda' => 'Agenda','gestion' => 'Gestionar'] as $key => $label): if (($key === 'agenda' && !$miRol) || ($key === 'gestion' && !$admin)) {
    continue;
} ?><a href="banda.php?id=<?= $id ?>&amp;tab=<?= $key ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?></nav>
<?php if (!$ready): ?><p class="card">Las nuevas funciones estarán disponibles al terminar la actualización.</p><?php elseif ($tab === 'publicaciones'): ?>
<div class="band-content-grid"><section>
<?php if ($miRol): ?><details class="composer-disclosure card" id="compartir" data-sheet data-sheet-title="Publicar en tu banda"><summary class="composer-summary"><span class="composer-plus" aria-hidden="true">＋</span><span><strong>Publicar con <?= e($banda['nombre']) ?></strong><small>Audio, video, partitura o una idea</small></span></summary><form method="POST" action="social-accion.php" class="composer" enctype="multipart/form-data" data-upload-form data-video-limit="<?= VIDEO_MAX_BYTES ?>" data-chunk-upload><?= csrfCampo() ?><input type="hidden" name="accion" value="publicar"><input type="hidden" name="banda_id" value="<?= $id ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><label>Título<input name="titulo" maxlength="120" required></label><label>Texto<textarea name="texto" maxlength="1000" rows="3"></textarea></label><label>Enlace opcional<input name="enlace" maxlength="300" placeholder="https://…"></label><label class="audio-pick">Audio propio · hasta 64 MB<input type="file" name="audio" accept=".mp3,.m4a,.ogg,.wav,audio/*"></label><label class="audio-pick">Partitura PDF · hasta 20 MB<input type="file" name="partitura" accept="application/pdf,.pdf"></label><label class="audio-pick">Video · hasta 256 MB<input type="file" name="video" accept=".mp4,.webm,video/mp4,video/webm" data-video-input><small data-video-hint role="status"></small></label><p class="band-hint">Un archivo por publicación. Las pistas respetan la privacidad de tu perfil.</p><div class="upload-progress" data-upload-progress hidden role="status" aria-live="polite"><div><strong data-upload-message>Preparando…</strong><span data-upload-percent></span></div><progress max="100" value="0" data-upload-bar></progress><div class="upload-controls"><button type="button" class="btn btn-ghost" data-upload-pause hidden>Pausar</button><button type="button" class="btn btn-ghost" data-upload-resume hidden>Reanudar</button><button type="button" class="btn btn-ghost" data-upload-cancel hidden>Cancelar subida</button></div></div><div class="composer-fila"><select name="tipo" aria-label="Tipo de publicación"><?php foreach (TIPOS_PUBLICACION as $k => [$icon,$label]): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select><select name="genero" aria-label="Género"><option value="">Género</option><?php foreach (GENEROS as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?></select><select name="licencia" aria-label="Licencia"><option value="todos_derechos">Todos los derechos reservados</option><option value="cc_by">CC BY</option><option value="cc_by_sa">CC BY-SA</option><option value="cc_by_nc">CC BY-NC</option><option value="cc0">CC0</option></select><button class="btn btn-primary" type="submit">Publicar en la banda</button></div></form></details><?php endif; ?>
<?php if (!$posts): ?><div class="card band-empty"><span aria-hidden="true">♫</span><h2>Su próximo estreno empieza aquí.</h2><p>Todavía no hay publicaciones visibles de esta banda.</p></div><?php endif; ?>
<?php foreach ($posts as $p) {
    $p['banda_nombre'] = $banda['nombre'];
    $p['banda_id'] = $id;
    renderPublicacion($p, $comments[$p['id']] ?? [], $yo, $volver);
} ?>
<?php if (count($posts) === 20): ?><a class="btn btn-ghost" href="banda.php?id=<?= $id ?>&amp;antes=<?= (int)end($posts)['id'] ?>">Publicaciones anteriores</a><?php endif; ?>
</section><aside class="card band-about"><span class="band-eyebrow">EL COLECTIVO</span><h2>Lo que nos une</h2><p><?= e($banda['genero'] ?: 'La música') ?><?= $banda['ciudad'] !== '' ? ' · ' . e($banda['ciudad']) : '' ?></p><p><?= $settings['ingreso'] === 'abierto' ? 'Abierta a nuevos integrantes.' : 'Nuevos integrantes por invitación.' ?></p><a href="banda.php?id=<?= $id ?>&amp;tab=integrantes">Conoce a los músicos ↗</a><small>El chat y la agenda solo aparecen para integrantes.</small></aside></div>
<?php elseif ($tab === 'integrantes'): ?><div class="band-section-title"><h2>Detrás del sonido</h2><span><?= count($miembros) ?> integrantes</span></div><div class="band-members"><?php foreach ($visibleMembers as $m): ?><article class="card band-member"><a href="<?= e(rutaAplicacion('musico.php?id=' . (int)$m['usuario_id'])) ?>" class="band-member-person"><img src="<?= e(musicmadPublicImageUrl($m['avatar'])) ?>" width="48" height="48" loading="lazy" alt=""><span><strong><?= e($m['nombre'] . ' ' . $m['apellido']) ?></strong><small><?= e($m['instrumento'] ?: 'Músico') ?></small></span></a><span class="band-role"><?= e(BAND_ROLES[$m['rol']]) ?></span><?php if ($admin): ?><details class="band-member-tools" data-sheet data-sheet-title="Permisos del integrante"><summary>Permisos</summary><?php abrirAccionBanda($id, 'rol'); ?><input type="hidden" name="usuario" value="<?= (int)$m['usuario_id'] ?>"><label>Rol<select name="rol"><?php foreach (BAND_ROLES as $key => $label): ?><option value="<?= $key ?>" <?= $m['rol'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><p class="band-hint">Coordinadores organizan ensayos. Solo administradores invitan y cambian permisos.</p><button class="btn btn-primary" type="submit">Guardar permiso</button></form><?php if ((int)$m['usuario_id'] !== $yo) {
    abrirAccionBanda($id, 'expulsar', 'band-form', '¿Retirar a este integrante de la banda?'); ?><input type="hidden" name="usuario" value="<?= (int)$m['usuario_id'] ?>"><button class="btn btn-danger" type="submit">Retirar integrante</button></form><?php } ?></details><?php endif; ?></article><?php endforeach; ?></div><?php if (count($visibleMembers) < count($miembros)): ?><p class="band-hint">Algunos integrantes no muestran su perfil a tu cuenta.</p><?php endif; ?><?php if ($miRol): ?><details class="card quick-sheet" data-sheet data-sheet-title="Tu instrumento en la banda"><summary>Mi instrumento <span aria-hidden="true">＋</span></summary><?php abrirAccionBanda($id, 'instrumento'); ?><label>Qué tocas<input name="instrumento" maxlength="60" value="<?= e(array_values(array_filter($miembros, static fn ($m) => (int)$m['usuario_id'] === $yo))[0]['instrumento'] ?? '') ?>"></label><button class="btn btn-primary" type="submit">Guardar</button></form></details><?php abrirAccionBanda($id, 'salir', 'band-leave', '¿Salir de la banda? Si eres su último administrador, otro integrante tomará tu lugar.'); ?><button class="btn btn-ghost" type="submit">Salir de la banda</button></form><?php endif; ?>
<?php elseif ($tab === 'agenda'): ?><header class="band-section-title"><div><span class="band-eyebrow">SOLO INTEGRANTES</span><h2>El próximo ensayo</h2></div><a href="banda-ics.php?id=<?= $id ?>" class="btn btn-ghost" data-native-navigation>Mi calendario ↓</a></header><?php if ($coord): ?><details class="card quick-sheet" id="nuevo-ensayo" data-sheet data-sheet-title="Programar ensayo"><summary>Programar un ensayo <span aria-hidden="true">＋</span></summary><?php formularioEnsayo($id); ?></details><?php endif; ?>
<?php if (!$ensayos): ?><div class="card band-empty"><span aria-hidden="true">◷</span><h3>Agenda libre.</h3><p>Organicen su próximo encuentro aquí.</p></div><?php endif; ?>
<div class="band-agenda"><?php foreach ($ensayos as $event): $date = (new DateTimeImmutable($event['inicio'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($event['zona'])); ?><article class="card band-rehearsal <?= $event['estado'] === 'cancelado' ? 'is-cancelled' : '' ?>"><div class="band-date-tile"><strong><?= $date->format('d') ?></strong><span><?= ['01' => 'ENE','02' => 'FEB','03' => 'MAR','04' => 'ABR','05' => 'MAY','06' => 'JUN','07' => 'JUL','08' => 'AGO','09' => 'SEP','10' => 'OCT','11' => 'NOV','12' => 'DIC'][$date->format('m')] ?></span></div><div class="band-rehearsal-info"><span class="band-eyebrow"><?= $event['estado'] === 'cancelado' ? 'Cancelado' : $date->format('H:i') . ' · ' . e($event['zona']) ?></span><h3><?= e($event['titulo']) ?></h3><p><?= e($event['lugar'] ?: 'Lugar por acordar') ?></p><?php if ($event['notas']): ?><p><?= nl2br(e($event['notas'])) ?></p><?php endif; ?><small><?= (int)$event['asistentes'] ?> <?= (int)$event['asistentes'] === 1 ? 'confirmó' : 'confirmaron' ?> · <?= $date->format('d/m/Y') ?></small>
<?php if ($event['estado'] === 'programado') {
    abrirAccionBanda($id, 'rsvp', 'band-rsvp'); ?><input type="hidden" name="ensayo" value="<?= (int)$event['id'] ?>"><label>Tu respuesta<select name="estado"><?php foreach (['si' => 'Voy','tal_vez' => 'Tal vez','no' => 'No puedo'] as $key => $label): ?><option value="<?= $key ?>" <?= $event['respuesta'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label><label class="band-check"><input type="checkbox" name="aviso" value="1" <?= $event['aviso'] ? 'checked' : '' ?>> Recordarme en MusicMad</label><button class="btn btn-primary" type="submit">Confirmar respuesta</button></form><?php if ($coord): ?><details class="band-event-edit" data-sheet data-sheet-title="Editar ensayo"><summary>Editar ensayo</summary><?php formularioEnsayo($id, $event);
        abrirAccionBanda($id, 'cancelar_ensayo', 'band-form', '¿Cancelar el ensayo y avisar a integrantes?'); ?><input type="hidden" name="ensayo" value="<?= (int)$event['id'] ?>"><button class="btn btn-danger" type="submit">Cancelar ensayo</button></form></details><?php endif;
} ?></div></article><?php endforeach; ?></div>
<?php elseif ($tab === 'gestion'): ?>
<section class="band-management"><a class="btn btn-ghost" href="ubicacion.php?tipo=banda&amp;id=<?= $id ?>">Zona aproximada en el mapa ↗</a><details class="card quick-sheet" id="invitar-banda" data-sheet data-sheet-title="Invitar a un músico"><summary>Invitar a la banda <span aria-hidden="true">＋</span></summary><form method="GET" action="banda.php#invitar-banda" class="band-invite-search"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="gestion"><label>Buscar músico o amigo<input type="search" name="invq" value="<?= e($inviteQuery) ?>" maxlength="80" placeholder="Nombre o instrumento"></label><button class="btn btn-ghost" type="submit">Buscar músicos</button></form><?php abrirAccionBanda($id, 'invitar'); ?><label>Músico<select name="usuario" required><option value="">Elige un músico</option><?php foreach (candidatosInvitacionBanda($yo, $id, $inviteQuery) as $m): if (rolEnBanda((int)$m['id'], $id) !== null) {
    continue;
} ?><option value="<?= (int)$m['id'] ?>"><?= e($m['nombre'] . ' ' . $m['apellido'] . ' · ' . ($m['instrumento'] ?: 'Músico')) ?></option><?php endforeach; ?></select></label><p class="band-hint">La invitación vence en siete días. Puedes retirarla antes.</p><button class="btn btn-primary" type="submit">Enviar invitación</button></form></details>
<?php if ($invites): ?><div class="card band-pending-invites"><h2>Invitaciones pendientes</h2><?php foreach ($invites as $invite): ?><div><strong><?= e($invite['nombre'] . ' ' . $invite['apellido']) ?></strong><?php abrirAccionBanda($id, 'cancelar_invitacion'); ?><input type="hidden" name="usuario" value="<?= (int)$invite['invitado_id'] ?>"><button type="submit" class="btn btn-ghost">Retirar</button></form></div><?php endforeach; ?></div><?php endif; ?>
<details class="card quick-sheet" data-sheet data-sheet-title="Perfil de la banda"><summary>Nombre, sonido y apariencia <span aria-hidden="true">＋</span></summary><?php abrirAccionBanda($id, 'editar');
camposBanda($banda, $settings); ?><button class="btn btn-primary" type="submit">Guardar banda</button></form></details>
<details class="card band-archive" data-sheet data-sheet-title="Archivar la banda"><summary>Archivar este proyecto</summary><p>Se oculta de búsquedas y se cierra el chat y la agenda. Conservamos sus publicaciones, integrantes y archivos. Puedes restaurarlo desde Bandas.</p><?php abrirAccionBanda($id, 'archivar', 'band-form', '¿Archivar la banda? Sus archivos se conservarán.'); ?><button class="btn btn-danger" type="submit">Archivar banda</button></form></details></section>
<?php endif; ?>
</main><?php require __DIR__ . '/includes/footer.php'; ?>
