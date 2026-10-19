<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}
$uid = (int)sesion('usuario_id');
$ready = portafolioDisponible();
$message = '';
$ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    try {
        guardarPortafolio($uid, $_POST);
        $message = 'Portafolio guardado.';
        $ok = true;
    } catch (InvalidArgumentException|LogicException|OutOfBoundsException $error) {
        $message = $error->getMessage();
    } catch (Throwable $error) {
        error_log('Artist portfolio save: ' . get_class($error));
        $message = 'No se pudo guardar. Inténtalo de nuevo.';
    }
}
$options = opcionesPortafolio($uid);
$q = mb_substr(trim(textoCampo($_GET, 'q')), 0, 80);
$tracks = [];
if ($ready) {
    $st = getDB()->prepare('SELECT p.id,p.titulo FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.usuario_id=? AND p.titulo LIKE ? ORDER BY p.id DESC LIMIT 100');
    $st->execute([$uid,'%' . addcslashes($q, '%_\\') . '%']);
    $tracks = $st->fetchAll();
    if ($options['destacadas']) {
        $st = getDB()->prepare('SELECT p.id,p.titulo FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.usuario_id=? AND p.id IN (' . implode(',', array_fill(0, count($options['destacadas']), '?')) . ')');
        $st->execute(array_merge([$uid], $options['destacadas']));
        foreach ($st->fetchAll() as $track) {
            if (!in_array((int)$track['id'], array_map('intval', array_column($tracks, 'id')), true)) {
                $tracks[] = $track;
            }
        }
    }
}
$pageTitle = 'Tu portafolio de artista';
$pageCss = ['CSS/social.css','CSS/account.css','CSS/portfolio.css'];
$navActivo = 'perfil';
$pageJs = ['assets/vendor/qrcode/qrcodegen-1.8.0.js','js/qr.js'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container epk-editor"><a class="account-back" href="<?= e(rutaAplicacion('perfil.php')) ?>">← Editar perfil</a><header class="account-data-hero"><span>TU MÚSICA, EN PRIMER PLANO</span><h1>Tu portafolio</h1><p>Un enlace para presentar tus pistas, tu identidad y tus redes.</p></header><?php if ($message): ?><p class="alert alert-<?= $ok ? 'success' : 'error' ?>" role="status"><?= e($message) ?></p><?php endif; ?>
<?php if (!$ready): ?><p>Importa la migración018 para activar el portafolio.</p><?php else: ?><?php if ($options['slug']): ?><section class="card epk-share"><div><strong>@<?= e($options['slug']) ?></strong><p><?= (int)$options['habilitado'] ? 'Revisa que tu privacidad siga en Público para compartirlo.' : 'Vista privada: el enlace todavía no está publicado.' ?></p><a class="btn btn-primary" href="portafolio.php?usuario=<?= e($options['slug']) ?>&amp;vista=1">Ver mi portafolio</a></div><?php renderCompartirPublico('perfil', $uid); ?></section><?php endif; ?>
<section class="card"><h2>Identidad y enlaces</h2><p>La foto, portada y biografía se cambian desde <a href="<?= e(rutaAplicacion('perfil.php')) ?>">Editar perfil</a>.</p><form method="POST" action="<?= e(rutaAplicacion('epk.php', 'POST')) ?>" class="account-confirm-form"><?= csrfCampo() ?><label for="epk-slug">Nombre de usuario</label><input id="epk-slug" name="slug" value="<?= e($options['slug']) ?>" pattern="[a-z][a-z0-9_]{2,29}" minlength="3" maxlength="30" placeholder="tu_nombre" required><label for="epk-color">Color de tu portafolio</label><select id="epk-color" name="color"><?php foreach (['violeta' => 'Violeta','azul' => 'Azul eléctrico','coral' => 'Coral','verde' => 'Verde escenario'] as $key => $label): ?><option value="<?= $key ?>" <?= $options['color'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select><label class="account-check"><input type="checkbox" name="habilitado" value="1" <?= $options['habilitado'] ? 'checked' : '' ?>><span>Publicar mi portafolio para visitantes. Requiere perfil Público.</span></label>
<?php if (incrustarPortafolioDisponible()): ?><label class="account-check"><input type="checkbox" name="incrustable" value="1" <?= !empty($options['incrustable']) ? 'checked' : '' ?>><span>Permitir un visor de mis pistas públicas en otras páginas. Puedes retirarlo cuando quieras.</span></label><?php endif; ?><details class="epk-options"><summary>Redes y enlaces opcionales <span aria-hidden="true">＋</span></summary><fieldset><legend class="sr-only">Enlaces opcionales</legend><?php foreach (REDES_PORTAFOLIO as $key => $label): ?><label for="epk-<?= $key ?>"><?= $label ?></label><input id="epk-<?= $key ?>" name="<?= $key ?>" type="url" value="<?= e($options['redes'][$key] ?? '') ?>" maxlength="300" placeholder="https://"><?php endforeach; ?></fieldset>
</details><details class="epk-options"><summary>Elegir mis cinco pistas destacadas <span aria-hidden="true">＋</span></summary><fieldset><legend class="sr-only">Tus cinco pistas destacadas</legend><p>Solo audios que tú subiste. Guarda vacíos los lugares que no necesites.</p><?php for ($i = 0;$i < 5;$i++): ?><label for="epk-pista-<?= $i ?>">Pista <?= $i + 1 ?></label><select id="epk-pista-<?= $i ?>" name="destacadas[]"><option value="">Sin pista</option><?php foreach ($tracks as $track): ?><option value="<?= (int)$track['id'] ?>" <?= (int)($options['destacadas'][$i] ?? 0) === (int)$track['id'] ? 'selected' : '' ?>><?= e($track['titulo']) ?></option><?php endforeach; ?></select><?php endfor; ?></fieldset></details><button class="btn btn-primary" type="submit">Guardar portafolio</button></form></section>
<section class="card"><h2>Encuentra una pista anterior</h2><p>La selección muestra tus cien pistas más recientes; las ya destacadas se conservan. Busca por título si necesitas una anterior. Guarda el formulario antes de buscar.</p><form method="GET" action="<?= e(rutaAplicacion('epk.php', 'GET')) ?>" class="account-confirm-form"><label for="epk-q">Título de la pista</label><input id="epk-q" name="q" value="<?= e($q) ?>" maxlength="80"><button class="btn btn-ghost" type="submit">Buscar mis pistas</button></form></section><?php endif; ?></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
