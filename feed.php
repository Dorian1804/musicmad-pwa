<?php
// ============================================================
//  MusicMad – Comunidad (feed de la red social)
//  feed.php
// ============================================================
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/publicacion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}

requerirSesion();

$yo = (int) sesion('usuario_id');
$onboardingPendiente = estadoOnboarding($yo);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$shareDraft = $_SESSION['share_draft'] ?? null;
unset($_SESSION['share_draft']);

$modoSolicitado = textoCampo($_GET, 'ver');
$modo = in_array($modoSolicitado, ['siguiendo', 'guardadas'], true) ? $modoSolicitado : 'todo';
$genero = textoCampo($_GET, 'genero');
$tipo = textoCampo($_GET, 'tipo');
$instrumento = mb_substr(trim(textoCampo($_GET, 'instrumento')), 0, 60);
$feedback = textoCampo($_GET, 'feedback') === '1' ? '1' : '';
$esFragmento = ($_GET['fragmento'] ?? null) === 'feed';
$cursorSolicitado = mb_substr(textoCampo($_GET, 'cursor'), 0, 180);
try {
    $paginaFeed = paginaComunidad($yo, $modo, ['genero' => $genero,'tipo' => $tipo,'instrumento' => $instrumento,'feedback' => $feedback], $esFragmento ? $cursorSolicitado : '', $esFragmento ? 6 : 3);
} catch (InvalidArgumentException|OutOfBoundsException $error) {
    http_response_code($error instanceof OutOfBoundsException ? 410 : 422);
    header('Content-Type: text/plain; charset=utf-8');
    echo e($error->getMessage());
    exit;
}
$publicaciones = $paginaFeed['publicaciones'];
$hayMas = $paginaFeed['hay_mas'];
$feedCursor = $paginaFeed['cursor'];
$comentarios = comentariosDe(array_column($publicaciones, 'id'));
$comentarioIds = [];
foreach ($comentarios as $listaComentarios) {
    foreach ($listaComentarios as $comentario) {
        $comentarioIds[] = (int) $comentario['id'];
    }
}
$respuestas = social3Disponible() ? respuestasComentarios($comentarioIds) : [];
$comentariosTiempo = social3Disponible() ? comentariosTiempoDe(array_column($publicaciones, 'id')) : [];
$sugeridos = $esFragmento ? [] : musicosSugeridos($yo);

// Para conservar filtros al volver de una acción
$volver = 'feed.php' . (($qs = http_build_query(array_filter(['ver' => $modo !== 'todo' ? $modo : '', 'genero' => $genero, 'tipo' => $tipo, 'instrumento' => $instrumento,'feedback' => $feedback]))) ? '?' . $qs : '');

if ($esFragmento) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<div data-feed-fragment data-has-more="' . ($hayMas ? '1' : '0') . '" data-feed-cursor="' . e($feedCursor) . '">';
    foreach ($publicaciones as $p) {
        renderPublicacion($p, $comentarios[$p['id']] ?? [], $yo, $volver, $respuestas, $comentariosTiempo[$p['id']] ?? []);
    }
    echo '</div>';
    exit;
}

$pageTitle = 'Comunidad';
$pageCss = ['CSS/social.css','CSS/creative.css','CSS/onboarding.css'];
$pageProfileIds = array_column($publicaciones, 'usuario_id');
$pageJs = ['js/upload-progress.js', 'js/infinite-feed.js'];
$navActivo = 'feed';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="container">
  <div class="social-grid <?= $sugeridos ? '' : 'social-grid-full' ?>">

    <section class="social-main">
      <header class="community-hero">
        <div class="community-hero-copy">
          <span class="community-kicker"><?= e(mmTexto('HECHO POR MÚSICOS')) ?></span>
          <h1><?= e(mmTexto('Comunidad')) ?></h1>
          <p><?= e(mmTexto('Que suene lo que estás creando.')) ?></p>
        </div>
        <svg class="scene-vinyl" viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="47"/><circle cx="50" cy="50" r="35"/><circle cx="50" cy="50" r="27"/><circle cx="50" cy="50" r="16"/><circle cx="50" cy="50" r="4"/><path d="M54 6a44 44 0 0 1 38 38M8 58a44 44 0 0 0 35 35"/></svg>
      </header>
      <nav class="scene-shortcuts" aria-label="Explora tu escena"><a href="<?= e(rutaAplicacion('feed.php?tipo=cancion')) ?>"><?= e(mmTexto('Pistas')) ?> <span aria-hidden="true">♫</span></a><a href="<?= e(rutaAplicacion('bandas.php')) ?>"><?= e(mmTexto('Bandas')) ?> <span aria-hidden="true">↗</span></a><a href="<?= e(rutaAplicacion('busco.php')) ?>"><?= e(mmTexto('Colaborar')) ?> <span aria-hidden="true">＋</span></a><a href="<?= e(rutaAplicacion('mercado.php')) ?>"><?= e(mmTexto('Equipo')) ?> <span aria-hidden="true">↗</span></a></nav>

      <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash[0]) ?>" data-autohide="6000"><?= e(mmTexto($flash[1])) ?></div>
      <?php endif; ?>

      <?php if ($onboardingPendiente && (int)$onboardingPendiente['paso'] < 4): ?><aside class="onboarding-reminder"><strong>Tu escena en tres pasos</strong><a href="<?= e(rutaAplicacion('bienvenida.php')) ?>">Continuar →</a></aside><?php endif; ?>
      <?php $clipsHoy = listarClipsMusicales($yo, 12);
if ($clipsHoy): ?><nav class="clips-strip" aria-label="Clips de 24 horas"><a href="<?= e(rutaAplicacion('clips.php#nuevo-clip')) ?>"><span class="clips-new" aria-hidden="true">＋</span>Tu clip</a><?php foreach ($clipsHoy as $clipHoy): ?><a href="<?= e(rutaAplicacion('clip.php?id=' . (int)$clipHoy['id'])) ?>"><img src="<?= e(musicmadPublicImageUrl($clipHoy['avatar'])) ?>" width="54" height="54" alt="" loading="lazy"><span><?= e(mb_substr($clipHoy['autor'], 0, 18)) ?></span></a><?php endforeach; ?><a href="<?= e(rutaAplicacion('clips.php')) ?>">Ver todos ↗</a></nav><?php endif; ?>
      <details class="composer-disclosure card" id="compartir" data-sheet data-sheet-title="<?= e(mmTexto('Comparte tu música')) ?>" <?= $shareDraft ? 'open' : '' ?>>
        <summary class="composer-summary">
          <img src="<?= e(musicmadPublicImageUrl(sesion('usuario_avatar') ?: AVATAR_DEFECTO)) ?>" alt="" class="post-avatar">
          <span><strong><?= e(mmTexto('Comparte tu música')) ?></strong><small><?= e(mmTexto('Una canción, una idea o lo que andas creando')) ?></small></span>
          <span class="composer-plus" aria-hidden="true">＋</span>
        </summary>
        <form method="POST" action="social-accion.php" class="composer" enctype="multipart/form-data" data-upload-form data-video-limit="<?= VIDEO_MAX_BYTES ?>" data-chunk-upload>
          <?= csrfCampo() ?>
          <input type="hidden" name="accion" value="publicar">
          <input type="hidden" name="volver" value="feed.php">
          <div class="composer-top">
          <?php if ($shareDraft): ?><p class="share-review-note">Revisa el contenido que compartiste desde otra app antes de publicarlo.</p><?php endif; ?>
          <input type="text" name="titulo" maxlength="120" aria-label="<?= e(mmTexto('Título de la publicación')) ?>" required value="<?= e($shareDraft['titulo'] ?? '') ?>"
                   placeholder="<?= e(mmTexto('¿Qué estás tocando, {name}?', ['name' => sesion('usuario_nombre')])) ?>">
          </div>
          <textarea name="texto" maxlength="1000" rows="3" aria-label="<?= e(mmTexto('Texto de la publicación')) ?>" aria-describedby="social-text-help" placeholder="<?= e(mmTexto('Cuenta algo sobre tu canción, tu proceso o lo que buscas…')) ?>"><?= e($shareDraft['texto'] ?? '') ?></textarea>
          <p id="social-text-help" class="muted">Usa hasta 8 #etiquetas y menciona músicos con su @alias de portafolio. Encuentra el alias en Buscar.</p>
          <input type="text" name="enlace" maxlength="300" aria-label="<?= e(mmTexto('Enlace opcional')) ?>" inputmode="url" value="<?= e($shareDraft['enlace'] ?? '') ?>"
                 placeholder="Enlace (https://…) de YouTube, Spotify, SoundCloud… (opcional)">
          <label class="audio-pick">
            <span>🎙️ Subir audio propio <small>(MP3, M4A, OGG o WAV · máx. 64 MB)</small></span>
            <input type="file" name="audio" accept=".mp3,.m4a,.ogg,.wav,audio/*">
          </label>
          <label class="audio-pick">
            <span>🎼 Compartir partitura <small>(PDF · máx. 20 MB; adjunta audio o partitura, uno por publicación)</small></span>
            <input type="file" name="partitura" accept="application/pdf,.pdf">
          </label>
          <label class="audio-pick">
            <span>🎬 Subir video <small>(MP4 o WebM · hasta 256 MB · subida reanudable)</small></span>
            <input type="file" name="video" accept="video/mp4,video/webm,.mp4,.webm" data-video-input><small class="upload-file-hint" data-video-hint role="status"></small>
          </label>
          <div class="upload-progress" data-upload-progress hidden role="status" aria-live="polite">
            <div><strong data-upload-message><?= e(mmTexto('Preparando la subida…')) ?></strong><span data-upload-percent></span></div>
            <progress max="100" value="0" data-upload-bar></progress>
            <div class="upload-controls"><button type="button" class="btn btn-ghost" data-upload-pause hidden><?= e(mmTexto('Pausar')) ?></button><button type="button" class="btn btn-ghost" data-upload-resume hidden><?= e(mmTexto('Reanudar')) ?></button><button type="button" class="btn btn-ghost" data-upload-cancel hidden><?= e(mmTexto('Cancelar subida')) ?></button></div>
          </div>
          <div class="composer-fila">
            <select name="tipo" aria-label="<?= e(mmTexto('Tipo de publicación')) ?>">
              <?php foreach (TIPOS_PUBLICACION as $k => [$ic, $et]): ?>
                <option value="<?= e($k) ?>"><?= $ic ?> <?= e(mmTexto($et)) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="genero" aria-label="<?= e(mmTexto('Género')) ?>">
              <option value=""><?= e(mmTexto('Género (opcional)')) ?></option>
              <?php foreach (GENEROS as $g): ?>
                <option value="<?= e($g) ?>"><?= e($g) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (social3Disponible()): ?><select name="licencia" aria-label="Licencia de uso">
              <option value="todos_derechos">Todos los derechos reservados</option>
              <option value="cc_by">Creative Commons BY · requiere atribución</option>
              <option value="cc_by_sa">Creative Commons BY-SA · compartir igual</option>
              <option value="cc_by_nc">Creative Commons BY-NC · uso no comercial</option>
              <option value="cc0">CC0 · dedicar al dominio público</option>
            </select><small class="license-help">Elige una licencia solo para obras tuyas o con autorización. <a href="https://creativecommons.org/share-your-work/use-remix/cc-licenses/" target="_blank" rel="noopener noreferrer">Ver términos de Creative Commons</a> · <a href="https://creativecommons.org/public-domain/cc0/" target="_blank" rel="noopener noreferrer">Qué significa CC0</a></small><?php endif; ?>
            <button type="submit" class="btn btn-primary"><?= e(mmTexto('Publicar')) ?></button>
          </div>
        </form>
      </details>

      <div class="feed-controls">
        <form method="GET" class="feed-tabs">
          <input type="hidden" name="feedback" value="<?= e($feedback) ?>"><input type="hidden" name="genero" value="<?= e($genero) ?>"><input type="hidden" name="tipo" value="<?= e($tipo) ?>"><input type="hidden" name="instrumento" value="<?= e($instrumento) ?>">
          <div class="tabs-mini">
            <?php foreach (['todo' => 'Para ti','siguiendo' => 'Siguiendo','guardadas' => 'Guardadas'] as $key => $label): ?>
              <button type="submit" name="ver" value="<?= $key ?>" class="<?= $modo === $key ? 'active' : '' ?>" <?= $modo === $key ? 'aria-current="page"' : '' ?>><?= e(mmTexto($label)) ?></button>
            <?php endforeach; ?>
          </div>
        </form>
        <details class="feed-filters" id="filtros" data-sheet data-sheet-title="<?= e(mmTexto('Afina tu comunidad')) ?>">
          <summary aria-label="<?= e(mmTexto('Filtrar comunidad')) ?>"><svg class="social-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M6 12h12M10 18h4"/></svg><?php if ($genero !== '' || $tipo !== '' || $instrumento !== '' || $feedback === '1'): ?><b class="filter-dot" aria-label="<?= e(mmTexto('Filtros activos')) ?>"></b><?php endif; ?></summary>
          <form method="GET" class="filtros">
            <input type="hidden" name="ver" value="<?= e($modo) ?>">
            <label><?= e(mmTexto('Género')) ?><select name="genero"><option value=""><?= e(mmTexto('Todos los géneros')) ?></option><?php foreach (GENEROS as $g): ?><option value="<?= e($g) ?>" <?= $genero === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></label>
            <label><?= e(mmTexto('Tipo')) ?><select name="tipo"><option value=""><?= e(mmTexto('Todo tipo')) ?></option><?php foreach (TIPOS_PUBLICACION as $key => [$icon,$label]): ?><option value="<?= e($key) ?>" <?= $tipo === $key ? 'selected' : '' ?>><?= $icon ?> <?= e(mmTexto($label)) ?></option><?php endforeach; ?></select></label>
            <label><?= e(mmTexto('Instrumento')) ?><input type="search" name="instrumento" maxlength="60" value="<?= e($instrumento) ?>" placeholder="Guitarra, batería…" enterkeyhint="search"></label>
            <?php if (feedbackDisponible()): ?><label class="feedback-filter"><input type="checkbox" name="feedback" value="1" <?= $feedback === '1' ? 'checked' : '' ?>><span><?= e(mmTexto('Solo pistas que piden feedback')) ?></span></label><?php endif; ?>
            <button type="submit" class="btn btn-primary"><?= e(mmTexto('Aplicar filtros')) ?></button><a href="<?= e(rutaAplicacion('feed.php')) ?>" class="btn btn-ghost"><?= e(mmTexto('Ver toda la comunidad')) ?></a>
          </form>
        </details>
      </div>

      <?php if (!$publicaciones): ?>
        <div class="card vacio">
          <p><strong><?= e(mmTexto('Aquí todavía no hay nada.')) ?></strong></p>
          <p><?= e(mmTexto($modo === 'guardadas' ? 'Las publicaciones que guardes aparecerán aquí.' : ($modo === 'siguiendo'
        ? 'Sigue a otros músicos para ver sus publicaciones aquí.'
        : '¡Sé el primero en compartir algo con la comunidad!'))) ?></p>
        </div>
      <?php endif; ?>

      <div class="feed-posts" data-feed-list data-feed-has-more="<?= $hayMas ? '1' : '0' ?>" data-feed-cursor="<?= e($feedCursor) ?>">
      <?php foreach ($publicaciones as $p): ?>
        <?php renderPublicacion($p, $comentarios[$p['id']] ?? [], $yo, $volver, $respuestas, $comentariosTiempo[$p['id']] ?? []); ?>
      <?php endforeach; ?>
      </div>
      <?php if ($hayMas): ?><div class="feed-loading" data-feed-sentinel><div class="feed-skeleton" data-feed-skeleton hidden aria-hidden="true"><span></span><span></span><span></span></div><button type="button" class="btn btn-ghost" data-feed-more><?= e(mmTexto('Ver más publicaciones')) ?></button><p data-feed-status role="status" aria-live="polite"></p></div><?php endif; ?>

    </section>

    <aside class="social-side">
      <?php if ($sugeridos): ?>
        <section class="card sugeridos">
          <div class="sugeridos-heading"><h3><?= e(mmTexto('Músicos para seguir')) ?></h3><a href="<?= e(rutaAplicacion('musicos.php')) ?>">Buscar todos ↗</a></div>
          <ul>
            <?php foreach ($sugeridos as $s): ?>
              <li>
                <a href="<?= e(rutaAplicacion('musico.php?id=' . (int) $s['id'])) ?>" class="sug-info">
                  <img src="<?= e(musicmadPublicImageUrl($s['avatar'])) ?>" alt="" class="post-avatar">
                  <span><strong><?= e($s['nombre'] . ' ' . $s['apellido']) ?></strong>
                    <small><?= e($s['instrumento'] ?: 'Músico') ?></small></span>
                </a>
                <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>">
                  <?= csrfCampo() ?>
                  <input type="hidden" name="accion" value="seguir">
                  <input type="hidden" name="usuario" value="<?= (int) $s['id'] ?>">
                  <input type="hidden" name="volver" value="<?= e($volver) ?>">
                  <button type="submit" class="btn btn-accent btn-sm"><?= e(mmTexto('Seguir')) ?></button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

    </aside>

  </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
