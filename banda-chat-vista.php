<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$yo = (int)sesion('usuario_id');
$id = idCampo($_GET, 'id');
try {
    if (!bandasWorkspaceDisponible()) {
        throw new OutOfBoundsException();
    }
    exigirIntegranteBanda($yo, $id);
    $banda = bandaDisponible($yo, $id);
    $estado = leerChatBanda($yo, $id);
} catch (OutOfBoundsException $error) {
    http_response_code(404);
    $pageTitle = 'Chat no disponible';
    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/navbar.php';
    echo '<main class="container"><h1>Chat no disponible</h1><p>Necesitas ser integrante de esta banda.</p><a href="bandas.php">Ver bandas</a></main>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$st = getDB()->prepare('SELECT p.id,p.titulo FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.usuario_id=? ORDER BY p.id DESC LIMIT 20');
$st->execute([$yo]);
$pistas = $st->fetchAll();
$pageTitle = 'Chat · ' . $banda['nombre'];
$pageCss = ['CSS/social.css','CSS/bands.css'];
$pageJs = ['js/chat.js'];
$navActivo = 'bandas';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container chat-active-page band-chat-page">
<?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></p><?php endif; ?>
<section class="card chat-hilo" data-live-chat data-group="1" data-transport="banda-chat.php" data-con="<?= $id ?>" data-owner="<?= $yo ?>">
 <header class="chat-head"><a href="banda.php?id=<?= $id ?>" class="chat-volver" aria-label="Volver a la banda">←</a><div class="band-chat-title"><strong><?= e($banda['nombre']) ?></strong><small>Chat de integrantes</small></div><span class="chat-connection" role="status">Conectando…</span></header>
 <button type="button" class="btn btn-ghost btn-sm chat-older" <?= !$estado['mas'] ? 'hidden' : '' ?>>Mensajes anteriores</button>
 <div class="chat-msgs" id="chat-msgs" role="log" aria-live="polite" aria-relevant="additions" aria-label="Chat interno de la banda">
 <?php if (!$estado['mensajes']): ?><p class="vacio">Aquí se organizan las próximas canciones y ensayos.</p><?php endif; ?>
 <?php foreach ($estado['mensajes'] as $m): ?><div class="burbuja <?= $m['de'] === $yo ? 'mia' : 'suya' ?>" data-message-id="<?= $m['id'] ?>" data-message-from="<?= $m['de'] ?>"><?php if ($m['de'] !== $yo): ?><strong class="chat-group-author"><?= e($m['autor']) ?></strong><?php endif; ?><span class="chat-text"><?= nl2br(e($m['texto'])) ?></span><?php if ($m['pista']): ?><a class="chat-track" href="<?= e($m['pista']['url']) ?>">♫ <?= e($m['pista']['titulo']) ?></a><?php endif; ?><small><?= e(hace($m['fecha'])) ?><span class="chat-read"></span></small></div><?php endforeach; ?><span id="fin"></span>
 <noscript><form method="POST" action="<?= e(rutaAplicacion('banda-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="leer"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="hasta" value="<?= $estado['mensajes'] ? (int)max(array_column($estado['mensajes'], 'id')) : 0 ?>"><button class="btn btn-ghost" type="submit">Marcar leído</button></form></noscript>
 </div><p class="chat-typing" hidden></p><p class="chat-error" role="status" hidden></p>
 <form method="POST" action="<?= e(rutaAplicacion('banda-accion.php', 'POST')) ?>" class="chat-form" data-live-composer><?= csrfCampo() ?><input type="hidden" name="accion" value="mensaje"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>">
 <?php if ($pistas): ?><label class="chat-share-track">Compartir pista<select name="pista"><option value="">Sin pista</option><?php foreach ($pistas as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['titulo']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
 <textarea name="texto" rows="1" maxlength="1000" aria-label="Mensaje para la banda" enterkeyhint="send" placeholder="Escribe para tu banda…"></textarea><button type="submit" class="btn btn-primary">Enviar</button></form>
</section>
</main><?php require __DIR__ . '/includes/footer.php'; ?>
