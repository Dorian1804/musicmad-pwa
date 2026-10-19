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
$owner = idCampo($_GET, 'usuario') ?: $uid;
$st = getDB()->prepare('SELECT id,nombre,apellido FROM usuarios WHERE id=? AND activo=1');
$st->execute([$owner]);
$profile = $st->fetch();
if (!$profile || !puedeVerPerfilSocial3($uid, $owner)) {
    http_response_code(404);
    $profile = null;
}
$hidden = $uid === $owner && textoCampo($_GET, 'ver') === 'ocultas';
$outgoing = $uid === $owner && textoCampo($_GET, 'ver') === 'enviadas';
$page = max(1, min(25, idCampo($_GET, 'pagina') ?: 1));
$list = $profile ? ($outgoing ? recomendacionesEscritas($uid, $page) : recomendacionesMusico($uid, $owner, $page, $hidden)) : [];
$mine = $profile ? miRecomendacion($uid, $owner) : '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'Voces de la escena';
$pageCss = ['CSS/social.css','CSS/account.css','CSS/endorsements.css'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container endorsements-page"><?php if (!$profile): ?><section class="card"><h1>Este músico no está disponible</h1><a href="<?= e(rutaAplicacion('musicos.php')) ?>">Explorar músicos</a></section><?php else: ?><a class="endorsement-back" href="musico.php?id=<?= $owner ?>">← Perfil de <?= e($profile['nombre']) ?></a><header class="endorsement-hero"><span>VOCES DE LA ESCENA</span><h1>La música también<br>se hace con confianza.</h1><p>Recomendaciones para <?= e($profile['nombre'] . ' ' . $profile['apellido']) ?> escritas por otros músicos. Son opiniones de la comunidad, sin certificación ni verificación de una colaboración.</p></header>
<?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></p><?php endif; ?>
<?php if ($uid !== $owner && recomendacionesDisponibles()): ?><details data-sheet data-sheet-title="Recomendar a este músico" class="endorsement-compose"><summary class="btn btn-primary"><?= $mine ? 'Editar mi recomendación' : 'Recomendar a este músico' ?> ＋</summary><form method="POST" action="<?= e(rutaAplicacion('recomendacion-accion.php', 'POST')) ?>" class="account-confirm-form"><?= csrfCampo() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="usuario" value="<?= $owner ?>"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><label for="endorsement-text">Tu experiencia musical</label><textarea id="endorsement-text" name="texto" minlength="12" maxlength="300" rows="4" required placeholder="Qué te gustó de crear o tocar con este músico"><?= e($mine) ?></textarea><p>Una recomendación por músico. Respeta su privacidad y comparte solo tu experiencia.</p><button class="btn btn-primary">Guardar recomendación</button></form></details><?php if ($mine): ?><form method="POST" action="<?= e(rutaAplicacion('recomendacion-accion.php', 'POST')) ?>" class="endorsement-remove"><?= csrfCampo() ?><input type="hidden" name="accion" value="retirar"><input type="hidden" name="usuario" value="<?= $owner ?>"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><button class="btn btn-ghost">Retirar mi recomendación</button></form><?php endif; ?><?php endif; ?>
<?php if ($uid === $owner): ?><nav class="endorsement-tabs" aria-label="Visibilidad de recomendaciones"><a href="recomendaciones.php?usuario=<?= $owner ?>" <?= !$hidden && !$outgoing ? 'aria-current="page"' : '' ?>>En mi perfil</a><a href="recomendaciones.php?usuario=<?= $owner ?>&amp;ver=ocultas" <?= $hidden ? 'aria-current="page"' : '' ?>>Ocultas</a><a href="recomendaciones.php?usuario=<?= $owner ?>&amp;ver=enviadas" <?= $outgoing ? 'aria-current="page"' : '' ?>>Escritas por mí</a></nav><?php endif; ?>
<?php if ($uid === $owner): ?><h2><?= $outgoing ? 'Escritas por ti' : ($hidden ? 'Ocultas de tu perfil' : 'Lo que otros músicos comparten de ti') ?></h2><?php endif; ?>
<section class="endorsement-list" aria-label="Recomendaciones"><?php foreach ($list as $item): ?><article class="endorsement-card"><span class="endorsement-quote" aria-hidden="true">“</span><blockquote><?= nl2br(e($item['texto'])) ?></blockquote><a class="endorsement-author" href="<?= e(rutaAplicacion('musico.php?id=' . (int)$item['autor_id'])) ?>"><img src="<?= e(musicmadPublicImageUrl($item['avatar'])) ?>" width="40" height="40" loading="lazy" alt=""><span><strong><?= e($item['autor']) ?></strong><small><time datetime="<?= e(str_replace(' ', 'T', $item['created_at']) . 'Z') ?>"><?= e(substr($item['created_at'], 0, 10)) ?> · UTC</time></small></span></a><?php if ($uid === $owner): ?><form method="POST" action="<?= e(rutaAplicacion('recomendacion-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="<?= $outgoing ? 'retirar' : ($hidden ? 'mostrar' : 'ocultar') ?>"><input type="hidden" name="usuario" value="<?= (int)$item['autor_id'] ?>"><input type="hidden" name="origen" value="<?= $outgoing ? 'enviadas' : 'perfil' ?>"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><button class="btn btn-ghost"><?= $outgoing ? 'Retirar mi recomendación' : ($hidden ? 'Volver a mostrar' : 'Ocultar de mi perfil') ?></button></form><?php endif; ?></article><?php endforeach; ?><?php if (!$list): ?><article class="endorsement-empty"><h2><?= $outgoing ? 'Todavía no has recomendado a nadie' : ($hidden ? 'No hay recomendaciones ocultas' : 'La primera voz puede ser la tuya') ?></h2><p><?= $uid === $owner ? 'Aquí aparecerán las recomendaciones que otros músicos puedan compartir contigo.' : 'Cuenta cómo fue crear música con esta persona.' ?></p></article><?php endif; ?></section>
<nav class="endorsement-pagination" aria-label="Páginas"><?php if ($page > 1): ?><a class="btn btn-ghost" href="recomendaciones.php?usuario=<?= $owner ?>&amp;pagina=<?= $page - 1 ?><?= $outgoing ? '&amp;ver=enviadas' : ($hidden ? '&amp;ver=ocultas' : '') ?>">← Anterior</a><?php endif; ?><?php if (count($list) === 20 && $page < 25): ?><a class="btn btn-ghost" href="recomendaciones.php?usuario=<?= $owner ?>&amp;pagina=<?= $page + 1 ?><?= $outgoing ? '&amp;ver=enviadas' : ($hidden ? '&amp;ver=ocultas' : '') ?>">Más voces →</a><?php endif; ?></nav><?php endif; ?></main><?php require __DIR__ . '/includes/footer.php'; ?>
