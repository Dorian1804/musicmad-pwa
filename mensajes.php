<?php
// ============================================================
//  MusicMad – Mensajes directos
//  mensajes.php            → lista de conversaciones
//  mensajes.php?con=ID     → conversación con un músico
// ============================================================
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/chat_live.php';

requerirSesion();
$yo = (int) sesion('usuario_id');
$con = idCampo($_GET, 'con');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $conPost = idCampo($_POST, 'con');
    if (textoCampo($_POST, 'accion') === 'preferencias_chat' && chatLiveDisponible()) {
        $prefs = [];
        foreach (['mostrar_presencia','mostrar_escribiendo','confirmar_lectura'] as $key) {
            $prefs[$key] = textoCampo($_POST, $key) === '1';
        }
        guardarPreferenciasChat($yo, $prefs);
        $_SESSION['flash'] = ['success','Preferencias guardadas.'];
    }
    if (textoCampo($_POST, 'accion') === 'leer_hilo' && $conPost > 0 && $conPost !== $yo) {
        try {
            autorizarHiloChat($yo, $conPost);
            if (chatLiveDisponible()) {
                marcarChatHasta($yo, $conPost, idCampo($_POST, 'hasta'));
            } else {
                marcarMensajesLeidos($yo, $conPost);
            }
        } catch (OutOfBoundsException $error) {
        }
    }
    header('Location: mensajes.php' . ($conPost > 0 ? '?con=' . $conPost : ''));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$otro = ($con && $con !== $yo) ? obtenerPerfil($con) : null;
$hilo = [];
$live = chatLiveDisponible();
$estadoChat = [];
if ($otro) {
    try {
        autorizarHiloChat($yo, $con);
    } catch (OutOfBoundsException $error) {
        $otro = null;
    }
    if ($otro) {
        $estadoChat = $live ? leerChatIncremental($yo, $con) : [];
        $hilo = $live ? $estadoChat['mensajes'] : hiloMensajes($yo, $con, 50);
    }
}
$chatPrefs = $live ? preferenciasChat($yo) : [];
$pistasChat = [];
if ($otro && $live) {
    $st = getDB()->prepare('SELECT p.id,p.titulo FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.usuario_id=? ORDER BY p.id DESC LIMIT 20');
    $st->execute([$yo]);
    $pistasChat = $st->fetchAll();
}
$convs = conversaciones($yo);
$paraIniciar = musicosSugeridos($yo, 4);
$borrador = $otro && !$hilo ? mb_substr(trim(textoCampo($_GET, 'txt')), 0, 300) : '';

$pageTitle = 'Mensajes';
$pageCss = ['CSS/social.css'];
$navActivo = 'mensajes';
$pageJs = ['js/chat.js'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="container <?= $otro ? 'chat-active-page' : '' ?>">
  <header class="chat-page-heading">
    <span class="community-kicker">Conexión directa · MusicMad</span>
    <h1>Tu Chat</h1>
    <p>Habla de música, comparte una idea o coordina el próximo ensayo.</p>
  </header>
  <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>" data-autohide="6000"><?= e($flash[1]) ?></div><?php endif; ?>

  <div class="chat-grid <?= $otro ? 'con-hilo' : '' ?>">
    <aside class="card chat-lista">
      <div class="chat-list-heading"><div><span class="chat-overline">TU BANDEJA</span><h2>Conversaciones</h2></div><span class="chat-count"><?= count($convs) ?></span></div>
      <?php if ($convs): ?><label class="chat-search"><span aria-hidden="true">⌕</span><input id="chat-search" type="search" placeholder="Buscar conversación…" aria-label="Buscar conversación"></label><?php endif; ?>
      <ul>
        <?php foreach ($convs as $c): ?>
          <li class="chat-conversation <?= (int) $c['id'] === $con ? 'activa' : '' ?>" data-chat-name="<?= e($c['nombre'] . ' ' . $c['apellido'] . ' ' . $c['texto']) ?>">
            <a href="<?= e(rutaAplicacion('mensajes.php?con=' . (int) $c['id'])) ?>" <?= (int) $c['id'] === $con ? 'aria-current="page"' : '' ?>>
              <img src="<?= e(musicmadPublicImageUrl($c['avatar'])) ?>" alt="" class="post-avatar">
              <span class="chat-previo">
                <strong><?= e($c['nombre'] . ' ' . $c['apellido']) ?></strong>
                <small><?= (int) $c['de_id'] === $yo ? 'Tú: ' : '' ?><?= e(mb_strimwidth($c['texto'], 0, 40, '…')) ?></small>
              </span>
              <?php if ($c['sin_leer'] > 0): ?><span class="badge"><?= (int) $c['sin_leer'] ?></span><?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="chat-no-results" id="chat-no-results" hidden>No encontramos esa conversación.</p>
      <?php if ($paraIniciar): ?>
        <section class="chat-discover">
          <span class="chat-overline">NUEVAS CONEXIONES</span>
          <h3>¿Con quién podrías tocar?</h3>
          <ul>
            <?php foreach ($paraIniciar as $musico): ?>
              <li><a href="<?= e(rutaAplicacion('mensajes.php?con=' . (int) $musico['id'])) ?>" class="chat-suggestion"><img src="<?= e(musicmadPublicImageUrl($musico['avatar'])) ?>" alt="" class="post-avatar"><span><strong><?= e($musico['nombre'] . ' ' . $musico['apellido']) ?></strong><small><?= e($musico['instrumento'] ?: 'Músico') ?></small></span><b aria-hidden="true">↗</b></a></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
    </aside>

    <section class="card chat-hilo" <?= $otro && $live ? 'data-live-chat data-con="' . (int)$con . '" data-owner="' . $yo . '" data-stream="' . (chatStreamDisponible() ? '1' : '0') . '"' : '' ?>>
      <?php if (!$otro): ?>
        <p class="vacio">Elige una conversación para ver los mensajes.</p>
      <?php else: ?>
        <header class="chat-head">
          <a href="<?= e(rutaAplicacion('mensajes.php')) ?>" class="chat-volver" aria-label="Volver">←</a>
          <a href="<?= e(rutaAplicacion('musico.php?id=' . (int) $otro['id'])) ?>" class="post-autor">
            <img src="<?= e(musicmadPublicImageUrl($otro['avatar'])) ?>" alt="" class="post-avatar">
            <strong><?= e($otro['nombre'] . ' ' . $otro['apellido']) ?></strong>
          </a>
          <?php if ($live): ?><span class="chat-connection" role="status">Conectando…</span><?php require __DIR__ . '/includes/chat_preferences_form.php'; endif; ?>
        </header>
        <?php if ($live): ?><button type="button" class="btn btn-ghost btn-sm chat-older" <?= empty($estadoChat['mas']) ? 'hidden' : '' ?>>Mensajes anteriores</button><?php endif; ?>
        <div class="chat-msgs" id="chat-msgs" role="log" aria-label="Mensajes" aria-live="polite" aria-relevant="additions">
          <?php if (!$hilo): ?><p class="vacio">Di hola 👋 y empieza la conversación.</p><?php endif; ?>
          <?php foreach ($hilo as $m): ?>
            <div class="burbuja <?= (int) ($m['de_id'] ?? $m['de']) === $yo ? 'mia' : 'suya' ?>" data-message-id="<?= (int)$m['id'] ?>" data-message-from="<?= (int)($m['de_id'] ?? $m['de']) ?>">
              <?= nl2br(e($m['texto'])) ?>
              <?php if (!empty($m['pista'])): ?><a class="chat-track" href="<?= e($m['pista']['url']) ?>">♫ <?= e($m['pista']['titulo'] ?: 'Escuchar pista') ?></a><?php endif; ?>
              <small><?= e(hace($m['created_at'] ?? $m['fecha'])) ?><span class="chat-read"></span></small>
            </div>
          <?php endforeach; ?>
          <span id="fin"></span>
          <noscript><form method="POST" action="<?= e(rutaAplicacion('mensajes.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="leer_hilo"><input type="hidden" name="con" value="<?= (int)$con ?>"><input type="hidden" name="hasta" value="<?= $hilo ? (int)max(array_column($hilo, 'id')) : 0 ?>"><button type="submit" class="btn btn-ghost btn-sm">Marcar conversación leída</button></form></noscript>
        </div>
        <?php if ($live): ?><p class="chat-typing" role="status" hidden>Está escribiendo…</p><p class="chat-error" role="status" hidden></p><?php endif; ?>
        <form method="POST" action="social-accion.php" class="chat-form" <?= $live ? 'data-live-composer' : '' ?>>
          <?= csrfCampo() ?>
          <input type="hidden" name="accion" value="mensaje">
          <input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>">
          <input type="hidden" name="para" value="<?= (int) $otro['id'] ?>">
          <?php if ($pistasChat): ?><label class="chat-share-track">Compartir pista<select name="pista" aria-label="Pista para compartir"><option value="">Sin pista</option><?php foreach ($pistasChat as $pista): ?><option value="<?= (int)$pista['id'] ?>"><?= e($pista['titulo'] ?: 'Pista ' . $pista['id']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
          <textarea name="texto" rows="1" maxlength="1000" aria-label="Mensaje" enterkeyhint="send" placeholder="Escribe un mensaje…" <?= $live ? '' : 'required' ?>><?= e($borrador) ?></textarea>
          <button type="submit" class="btn btn-primary">Enviar</button>
        </form>
      <?php endif; ?>
    </section>
  </div>
  <?php if ($live && !$otro) {
      require __DIR__ . '/includes/chat_preferences_form.php';
  } ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
