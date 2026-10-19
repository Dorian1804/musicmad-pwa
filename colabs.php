<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$uid = (int)sesion('usuario_id');
$ready = estudioCreativoDisponible();
$projects = listarColaboraciones($uid);
$tracks = $ready ? pistasPropiasCreativas($uid) : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'Estudio de colaboraciones';
$pageCss = ['CSS/social.css','CSS/account.css','CSS/creative.css'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container studio-page"><header class="studio-hero"><span>HECHO ENTRE MÚSICOS</span><h1>Tu idea.<br>El sonido de todos.</h1><p>Comparte una base, reúne aportes y conserva cada versión de tu colaboración.</p><a class="btn btn-primary" href="<?= e(anclaAplicacion('#abrir-proyecto')) ?>">Abrir un proyecto ↗</a></header>
<?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></p><?php endif; ?>
<?php if (!$ready): ?><section class="card"><h2>El estudio aún no está disponible</h2><p>Inténtalo después de la actualización.</p></section><?php else: ?>
<div class="studio-grid"><?php foreach ($projects as $project): ?><a class="studio-project" href="<?= e(rutaAplicacion('colab.php?id=' . (int)$project['id'])) ?>"><span><?= e($project['estado'] === 'abierta' ? 'RECIBE APORTES' : ($project['estado'] === 'publicada' ? 'MEZCLA FINAL' : 'EN PAUSA')) ?></span><h2><?= e($project['titulo']) ?></h2><p><?= e(mb_substr($project['descripcion'], 0, 150)) ?></p><small>Una idea de <?= e($project['artista']) ?></small><b aria-hidden="true">↗</b></a><?php endforeach; ?><?php if (!$projects): ?><article class="card studio-empty"><h2>Empecemos con una idea</h2><p>Tu primera pista puede convertirse en una colaboración.</p></article><?php endif; ?></div>
<details id="abrir-proyecto" data-sheet data-sheet-title="Abrir colaboración"><summary class="btn btn-primary">Nueva colaboración ＋</summary><form method="POST" action="<?= e(rutaAplicacion('colab-accion.php', 'POST')) ?>" class="account-confirm-form"><?= csrfCampo() ?><input type="hidden" name="accion" value="crear"><label>Título<input name="titulo" minlength="2" maxlength="120" placeholder="¿Qué vamos a crear?" required></label><label for="colab-base">Tu pista base</label><select id="colab-base" name="pista" required><option value="">Elige una pista tuya</option><?php foreach ($tracks as $track): ?><option value="<?= (int)$track['id'] ?>"><?= e($track['titulo']) ?></option><?php endforeach; ?></select><label>La idea<textarea name="descripcion" maxlength="700" rows="4" placeholder="Qué instrumentos, ambiente o aportes buscas"></textarea></label><p>La pista mantiene la privacidad que elegiste en tu perfil.</p><button class="btn btn-primary" type="submit">Abrir colaboración</button><a href="<?= e(rutaAplicacion('feed.php#compartir')) ?>">Subir primero una pista</a></form></details><?php endif; ?></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
