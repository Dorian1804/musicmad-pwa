<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/band_forms.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$yo = (int)sesion('usuario_id');
$ready = bandasWorkspaceDisponible();
$q = mb_substr(trim(textoCampo($_GET, 'q')), 0, 80);
$bandas = listarBandas($yo, $q);
$invitaciones = $ready ? invitacionesBandaPara($yo) : [];
$archivadas = $ready ? bandasArchivadasPara($yo) : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'Bandas';
$pageCss = ['CSS/social.css','CSS/bands.css'];
$navActivo = 'bandas';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container band-directory">
<header class="band-directory-heading"><span class="community-kicker">MusicMad / Colectivo</span><h1>Un sonido.<br>Muchas manos.</h1><p>Encuentra tu banda y organiza lo que viene.</p><a href="<?= e(anclaAplicacion('#crear-banda')) ?>" class="btn btn-primary">Crear una banda ↗</a><span class="band-heading-art" aria-hidden="true">♫</span></header>
<?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></p><?php endif; ?>
<?php if ($invitaciones): ?><section class="band-invitations" id="invitaciones"><h2>Te quieren en su banda <span><?= count($invitaciones) ?></span></h2><?php foreach ($invitaciones as $invite): ?><article class="card band-invite"><div><span class="band-eyebrow">Invitación · vence en siete días</span><h3><a href="<?= e(rutaAplicacion('banda.php?id=' . (int)$invite['banda_id'])) ?>"><?= e($invite['nombre']) ?></a></h3><p><?= e($invite['actor_nombre'] . ' ' . $invite['actor_apellido']) ?> te invita a tocar.</p></div><div><?php abrirAccionBanda((int)$invite['banda_id'], 'aceptar'); ?><label>Tu instrumento<input name="instrumento" maxlength="60" placeholder="Batería, voz…"></label><button class="btn btn-primary" type="submit">Aceptar invitación</button></form><?php abrirAccionBanda((int)$invite['banda_id'], 'rechazar'); ?><button class="btn btn-ghost" type="submit">Ahora no</button></form></div></article><?php endforeach; ?></section><?php endif; ?>
<details class="card quick-sheet" id="crear-banda" data-sheet data-sheet-title="Tu nueva banda"><summary><strong>Armar un nuevo proyecto</strong><span aria-hidden="true">＋</span></summary>
<?php if ($ready) {
    abrirAccionBanda(0, 'crear');
    camposBanda(); ?><label>Tu instrumento<input name="instrumento" maxlength="60"></label><button class="btn btn-primary" type="submit">Crear banda</button></form><?php } else { ?><p>Las nuevas funciones de bandas estarán disponibles al terminar la actualización.</p><a href="<?= e(rutaAplicacion('bandas.php')) ?>" class="btn btn-ghost">Reintentar</a><?php } ?></details>
<form method="GET" class="band-directory-search"><label><span class="sr-only">Buscar bandas</span><input type="search" name="q" maxlength="80" value="<?= e($q) ?>" placeholder="Nombre, género o ciudad…" enterkeyhint="search"></label><button class="btn btn-ghost" type="submit">Buscar</button></form>
<div class="band-section-title"><h2><?= $q !== '' ? 'Resultados' : 'Encuentra tu colectivo' ?></h2><span><?= count($bandas) ?> bandas</span></div>
<?php if (!$bandas): ?><div class="card vacio"><h3>El escenario está libre.</h3><p><?= $q !== '' ? 'Prueba otro nombre, género o ciudad.' : 'Crea tu banda e invita a tus músicos.' ?></p></div><?php endif; ?>
<div class="band-directory-grid"><?php foreach ($bandas as $b): ?><a class="band-tile" data-band-color="<?= e($b['color'] ?? 'violeta') ?>" href="<?= e(rutaAplicacion('banda.php?id=' . (int)$b['id'])) ?>"><div class="band-tile-art" aria-hidden="true"><span><?= e(mb_strtoupper(mb_substr($b['nombre'], 0, 2))) ?></span><b>♫</b></div><div class="band-tile-body"><span class="band-eyebrow"><?= $b['soy_miembro'] ? 'Tu banda' : 'Conecta y crea' ?></span><h3><?= e($b['nombre']) ?></h3><p><?= e($b['genero'] ?: 'Sonido por descubrir') ?><?= $b['ciudad'] !== '' ? ' · ' . e($b['ciudad']) : '' ?></p><p class="band-tile-desc"><?= e(mb_strimwidth($b['descripcion'], 0, 140, '…')) ?></p><div class="band-tile-foot"><span><?= (int)$b['miembros'] ?> integrantes</span><span><?= ($b['ingreso'] ?? 'abierto') === 'abierto' ? 'Ingreso abierto' : 'Por invitación' ?> ↗</span></div></div></a><?php endforeach; ?></div>
<?php if ($archivadas): ?><details class="card band-archived"><summary>Bandas archivadas (<?= count($archivadas) ?>)</summary><p>Sus publicaciones y archivos se conservan.</p><?php foreach ($archivadas as $band): ?><div class="band-archive-row"><strong><?= e($band['nombre']) ?></strong><?php abrirAccionBanda((int)$band['id'], 'restaurar'); ?><button class="btn btn-ghost" type="submit">Restaurar</button></form></div><?php endforeach; ?></details><?php endif; ?>
</main><?php require __DIR__ . '/includes/footer.php'; ?>
