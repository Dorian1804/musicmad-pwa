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
$ready = retosDisponibles();
$staff = puedeModerar($uid);
$retos = $ready ? listarRetos($uid) : [];
$schedule = $ready && $staff ? getDB()->query('SELECT habilitado FROM musicmad_challenge_schedule WHERE id=1')->fetchColumn() : false;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'Retos de la escena';
$pageCss = ['CSS/social.css','CSS/account.css','CSS/challenges.css'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
$labels = ['proximo' => 'PRÓXIMAMENTE','participacion' => 'ENVÍA TU PISTA','votacion' => 'ESCUCHA Y VOTA','resultados' => 'RESULTADOS','borrador' => 'BORRADOR','cancelado' => 'CANCELADO'];
?>
<main class="container challenge-page"><header class="challenge-hero"><span>UNA IDEA / MUCHAS VERSIONES</span><h1>Suena diferente.<br>Crea en comunidad.</h1><p>Un pequeño reto puede iniciar tu próxima canción. Comparte una pista propia, escucha otras ideas y elige tu favorita.</p><a class="btn btn-primary" href="<?= e(anclaAplicacion('#retos')) ?>">Ver los retos ↘</a><svg viewBox="0 0 120 120" aria-hidden="true"><path d="M10 65h15l10-40 16 72 15-56 13 36 10-22h21"/></svg></header>
<?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></p><?php endif; ?>
<div class="challenge-steps"><span><b>01</b> Crea una pista</span><span><b>02</b> Comparte tu idea</span><span><b>03</b> Escucha y vota</span></div>
<section id="retos" class="challenge-list" aria-label="Retos de la comunidad"><?php foreach ($retos as $reto): ?><a class="challenge-tile phase-<?= e($reto['fase']) ?>" href="<?= e(rutaAplicacion('reto.php?id=' . (int)$reto['id'])) ?>"><span><?= e($labels[$reto['fase']]) ?></span><h2><?= e($reto['titulo']) ?></h2><p><?= e(mb_substr($reto['descripcion'], 0, 180)) ?></p><small>Entrega hasta <?= e($reto['votacion_desde']) ?> UTC</small><strong>Entrar al reto ↗</strong></a><?php endforeach; ?><?php if (!$retos): ?><article class="challenge-empty"><h2><?= $ready ? 'La próxima idea está por llegar' : 'Los retos estarán disponibles pronto' ?></h2><p>Mientras tanto, comparte lo que estás creando con la escena.</p><a href="<?= e(rutaAplicacion('feed.php#compartir')) ?>" class="btn btn-primary">Compartir mi música</a></article><?php endif; ?></section>
<?php if ($ready && $staff): ?><details class="challenge-admin" data-sheet data-sheet-title="Organizar un reto"><summary class="btn btn-ghost">Organizar un reto ＋</summary><form method="POST" action="<?= e(rutaAplicacion('reto-accion.php', 'POST')) ?>" class="account-confirm-form"><?= csrfCampo() ?><input type="hidden" name="accion" value="crear"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><label>Título<input name="titulo" minlength="3" maxlength="120" required></label><label>La consigna<textarea name="descripcion" maxlength="500" rows="4"></textarea></label><label>Comienza (UTC)<input type="datetime-local" name="inicio" required></label><label>Empieza la votación (UTC)<input type="datetime-local" name="votacion" required></label><label>Cierra (UTC)<input type="datetime-local" name="fin" required></label><label>Estado<select name="estado"><option value="activo">Publicar</option><option value="borrador">Guardar borrador</option></select></label><p>Hasta 28 días. Participación y votación tienen periodos separados. Sin premios monetarios.</p><button class="btn btn-primary">Guardar reto</button></form></details>
<details class="challenge-admin"><summary>Programación semanal</summary><p>Con cron activo, MusicMad abre una consigna cada lunes UTC. Seis días para participar y el domingo para votar. Al activar puede aparecer el reto de la semana en curso.</p><form action="<?= e(rutaAplicacion('reto-accion.php', 'POST')) ?>" method="POST"><?= csrfCampo() ?><input type="hidden" name="accion" value="programar"><input type="hidden" name="habilitado" value="<?= $schedule ? '0' : '1' ?>"><button class="btn btn-ghost"><?= $schedule ? 'Desactivar programación semanal' : 'Activar programación semanal' ?></button></form></details><?php endif; ?></main><?php require __DIR__ . '/includes/footer.php'; ?>
