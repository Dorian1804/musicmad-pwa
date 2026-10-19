<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/publicacion.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
requerirSesion();
$uid = (int)sesion('usuario_id');
$id = idCampo($_GET, 'id');
$posts = listarFeed($uid, 'todo', ['ids' => [$id]], 1);
$post = $posts[0] ?? null;
$pageTitle = $post ? $post['titulo'] : 'Publicación no disponible';
$pageCss = ['CSS/social.css'];
$pageProfileIds = $post ? [$post['usuario_id']] : [];
$navActivo = 'feed';
if (!$post) {
    http_response_code(404);
}
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container narrow-wide">
<p><a class="btn btn-ghost" href="<?= e(rutaAplicacion('feed.php')) ?>">← Comunidad</a></p>
<?php if (!$post): ?><section class="card vacio"><h1>Esta publicación no está disponible.</h1><p>Pudo eliminarse o cambiar su privacidad.</p></section>
<?php else:
    $comments = comentariosDe([$id], 50);
    $ids = array_column($comments[$id] ?? [], 'id');
    $replies = social3Disponible() ? respuestasComentarios($ids) : [];
    $moments = social3Disponible() ? comentariosTiempoDe([$id]) : [];
    renderPublicacion($post, $comments[$id] ?? [], $uid, 'publicacion.php?id=' . $id, $replies, $moments[$id] ?? []);
endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
