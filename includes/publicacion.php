<?php
// ============================================================
//  MusicMad – Tarjeta de publicación (la usan feed.php y musico.php)
//  includes/publicacion.php
// ============================================================

function renderPublicacion(array $p, array $comentarios, int $yo, string $volver, array $respuestas = [], array $comentariosTiempo = []): void
{
    [$icono, $etiqueta] = TIPOS_PUBLICACION[$p['tipo']] ?? ['💬', 'Publicación'];
    $enlace = infoEnlace($p['enlace']);
    $esMia = (int) $p['usuario_id'] === $yo;
    $puedeBorrar = $esMia || esAdmin();
    $duplicados = $esMia ? duplicadosMedioPropio($yo, (int)$p['id']) : [];
    $aplaudi = (int) $p['yo_aplaudi'] > 0;
    $feedback = feedbackDisponible() && !empty($p['audio']) && pistaPideFeedback((int)$p['id']);
    $repostControl = 'repost-text-' . (int)$p['id'] . '-' . bin2hex(random_bytes(4));
    ?>
    <article class="post card" id="pub-<?= (int) $p['id'] ?>" data-profile-style="<?= (int) $p['usuario_id'] ?>">
      <header class="post-head">
        <?php if (!empty($p['repost_usuario_id'])): ?><div class="repost-note">↻ <?= e($p['repost_nombre'] . ' ' . $p['repost_apellido']) ?> compartió esto</div><?php endif; ?>
        <a href="<?= e(rutaAplicacion('musico.php?id=' . (int) $p['usuario_id'])) ?>" class="post-autor">
          <img src="<?= e(musicmadPublicImageUrl($p['avatar'])) ?>" alt="" class="post-avatar" width="40" height="40" loading="lazy">
          <span>
            <strong><?= e($p['nombre'] . ' ' . $p['apellido']) ?></strong>
            <small><?= e($p['instrumento'] ?: 'Músico') ?> · <?= e(hace($p['created_at'])) ?></small>
          </span>
        </a>
        <span class="chip chip-tipo"><?= $icono ?> <?= e($etiqueta) ?></span>
      </header>
      <?php if (!empty($p['repost_usuario_id'])): $sharedText = notaRepostPara((int)$p['repost_usuario_id'], (int)$p['id'], $yo);
          if ($sharedText !== ''): ?><blockquote class="repost-comment"><p><?= textoSocialConEnlaces($sharedText, $yo) ?></p><small>Comentario de <?= e($p['repost_nombre'] . ' ' . $p['repost_apellido']) ?></small></blockquote><?php endif;endif; ?>

      <?php if (!empty($p['banda_nombre'])): ?><a class="band-post-label" href="<?= e(rutaAplicacion('banda.php?id=' . (int)$p['banda_id'])) ?>">Banda · <?= e($p['banda_nombre']) ?></a><?php endif; ?>
      <?php if ($duplicados): ?><details class="media-duplicate-notice" data-sheet data-sheet-title="Tus copias de este archivo"><summary>Este archivo ya aparece en otra publicación tuya</summary><p>Comparamos los mismos bytes; puedes conservar ambas o usar Compartir.</p><ul><?php foreach ($duplicados as $duplicate): ?><li><a href="<?= e(rutaAplicacion('publicacion.php?id=' . (int)$duplicate['id'])) ?>"><?= e($duplicate['titulo']) ?></a></li><?php endforeach; ?></ul></details><?php endif; ?>
      <h2 class="post-titulo"><a href="<?= e(rutaAplicacion('publicacion.php?id=' . (int)$p['id'])) ?>"><?= e($p['titulo']) ?></a></h2>
      <?php if ($feedback): ?><p class="track-feedback-label"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v12H9l-5 4V4Z"/><path d="M8 8h8M8 12h5"/></svg><strong>Pido feedback</strong><span>Comparte una idea útil en los comentarios.</span></p><?php endif; ?>
      <?php if ($esMia && !empty($p['audio']) && feedbackDisponible()): ?><details class="post-feedback-settings" data-sheet data-sheet-title="Comentarios sobre mi música"><summary><?= $feedback ? 'Dejar de pedir feedback' : 'Pedir feedback sobre mi pista' ?></summary><p>Invita a otros músicos a comentar tu interpretación, sonido o composición.</p><form method="POST" action="<?= e(rutaAplicacion('feedback-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="activar" value="<?= $feedback ? '0' : '1' ?>"><button type="submit" class="btn btn-primary"><?= $feedback ? 'Retirar solicitud' : 'Activar Pido feedback' ?></button></form></details><?php endif; ?>
      <?php if ($p['texto'] !== ''): ?>
        <p class="post-texto"><?= textoSocialConEnlaces($p['texto'], $yo) ?></p>
      <?php endif; ?>

      <?php if (!empty($p['audio'])): ?>
        <div class="post-track" data-track-card>
          <button class="track-play" type="button" data-track-play
                  data-track-id="<?= (int)$p['id'] ?>" data-track-src="audio.php?id=<?= (int)$p['id'] ?>"
                  data-track-title="<?= e($p['titulo']) ?>" data-track-artist="<?= e($p['nombre'] . ' ' . $p['apellido']) ?>"
                  aria-label="Reproducir <?= e($p['titulo']) ?>" title="Reproducir pista">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 12 7-12 7z"/></svg>
          </button>
          <div class="track-card-copy"><strong><?= e($p['titulo']) ?></strong><small><?= e($p['nombre'] . ' ' . $p['apellido']) ?><?= $p['genero'] !== '' ? ' · ' . e($p['genero']) : '' ?></small></div>
          
          <span class="track-card-label">Pista</span>
        </div>
        <section class="track-waveform" data-waveform="<?= (int)$p['id'] ?>" aria-label="Forma de onda de <?= e($p['titulo']) ?>">
          <button type="button" class="btn btn-ghost" data-wave-load>Ver forma de onda ↗</button>
          <div data-wave-view hidden><canvas width="640" height="72" aria-hidden="true"></canvas><div class="wave-controls"><input type="range" min="0" max="1000" step="1" value="0" aria-label="Elegir momento en la forma de onda"><button class="btn btn-ghost" type="button" data-wave-jump disabled>Escuchar aquí</button></div><output class="wave-time" data-wave-position>0:00</output></div>
          <p data-wave-status role="status">Sin descargas adicionales hasta que lo abras.</p>
        </section>
        <?php if (social3Disponible()): ?>
          <section class="audio-time-comments" aria-label="Comentarios en la pista"><details data-sheet data-sheet-title="Comentar un momento de la pista"><summary class="audio-moment-summary">Comentar un momento <span aria-hidden="true">↗</span></summary>
            <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" class="audio-time-form">
              <?= csrfCampo() ?><input type="hidden" name="accion" value="comentario_tiempo"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="segundo" value="0" data-audio-time-value><input type="hidden" name="volver" value="<?= e($volver) ?>">
              <button type="button" class="audio-time-capture" data-capture-audio-time data-pub-id="<?= (int) $p['id'] ?>">＋ Marcar momento</button>
              <input type="text" name="texto" maxlength="300" placeholder="Comenta este momento…" aria-label="Comentario en la pista" required>
              <button type="submit" class="btn btn-ghost btn-sm">Comentar</button>
            </form>
            <?php if ($comentariosTiempo): ?><ol class="audio-time-list">
              <?php foreach ($comentariosTiempo as $ct): ?><li><button type="button" data-seek-audio data-pub-id="<?= (int) $p['id'] ?>" data-second="<?= e((string)$ct['segundo']) ?>"><?= e(etiquetaMomentoAudio((float)$ct['segundo'])) ?></button><span><strong><?= e($ct['nombre']) ?></strong> <?= e($ct['texto']) ?></span></li><?php endforeach; ?>
            </ol><?php endif; ?>
          </details></section>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($p['partitura'])): ?>
        <a class="partitura-download" href="<?= e(rutaAplicacion('partitura.php?id=' . (int) $p['id'])) ?>" rel="nofollow">🎼 Ver / descargar partitura PDF</a>
      <?php endif; ?>
      <?php if (!empty($p['video'])): ?>
        <video class="post-video" controls playsinline preload="none">
          <source src="video.php?id=<?= (int)$p['id'] ?>" type="<?= ($p['video_formato'] ?? '') === 'webm' ? 'video/webm' : 'video/mp4' ?>">
          Tu navegador no puede reproducir este video.
        </video>
      <?php endif; ?>
      <?php if ($esMia && $p['tipo'] === 'video' && (empty($p['video']) || !nombreMedioRespaldoValido('videos', (string)$p['video']) || !is_file(musicmadMediaDirectoryForFile('videos', $p['video']) . '/' . $p['video']))): ?><p class="post-media-missing">El archivo de este video no está disponible. <a href="<?= e(rutaAplicacion('restaurar-video.php?id=' . (int)$p['id'])) ?>">Restaurar mi video</a></p><?php endif; ?>

      <?php if ($enlace && $enlace['modo'] !== 'enlace'): ?>
        <div class="post-embed <?= $enlace['modo'] === 'spotify' ? 'post-embed-spotify' : '' ?>">
          <iframe src="<?= e($enlace['src']) ?>" loading="lazy" title="<?= e($p['titulo']) ?>"
                  allow="encrypted-media; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin"
                  allowfullscreen></iframe>
        </div>
      <?php elseif ($enlace): ?>
        <a class="post-enlace" href="<?= e($enlace['url']) ?>" target="_blank" rel="noopener noreferrer">
          🔗 Escuchar en <?= e($enlace['host']) ?>
        </a>
      <?php endif; ?>

      <?php if ($p['genero'] !== ''): ?>
        <p class="post-tags"><span class="chip">#<?= e($p['genero']) ?></span></p>
      <?php endif; ?>
      <?php if (!empty($p['licencia'])): ?><p class="post-license"><span aria-hidden="true">©</span> <?= e(etiquetaLicenciaPublicacion($p['licencia'])) ?></p><?php endif; ?>

      <?php if ($esMia && (!empty($p['audio']) || !empty($p['video']) || !empty($p['partitura']))): ?><details class="post-owned-download" data-sheet data-sheet-title="Descarga tus archivos"><summary>Descargar mis archivos <span aria-hidden="true">↓</span></summary><p>Guarda una copia en tu dispositivo antes de eliminar tu cuenta o esta publicación.</p><?php foreach (['audio' => ['audio.php','Audio'],'video' => ['video.php','Vídeo'],'partitura' => ['partitura.php','Partitura PDF']] as $key => [$endpoint,$label]): if (empty($p[$key])) {
          continue;
      } ?><a class="btn btn-ghost" href="<?= $endpoint ?>?id=<?= (int)$p['id'] ?>" download data-native-navigation><?= $label ?> ↓</a><?php endforeach; ?></details><?php endif; ?>
      <footer class="post-acciones">
        <div class="post-reaction-control">
        <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" data-sync-action="aplaudir">
          <?= csrfCampo() ?>
          <input type="hidden" name="accion" value="aplaudir">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="volver" value="<?= e($volver) ?>">
          <button type="submit" class="accion <?= $aplaudi ? 'on' : '' ?>" aria-label="<?= $aplaudi ? 'Quitar aplauso' : 'Aplaudir publicación' ?>" aria-pressed="<?= $aplaudi ? 'true' : 'false' ?>">
            <svg class="social-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 12 2.7-7.4a1.4 1.4 0 0 1 2.7.7L11.3 10h5.1a2 2 0 0 1 1.9 2.6l-1.8 6A2 2 0 0 1 14.6 20H9l-4-2v-6Z"/><path d="M2.5 11.5h2.4v7H2.5z"/></svg> <span data-applause-count><?= (int) $p['aplausos'] ?></span> <span class="solo-lg"><?= $aplaudi ? 'Aplaudido' : 'Aplaudir' ?></span>
          </button>
        </form>
        <?php if (array_key_exists('yo_guardada', $p)): $reactionChoices = ['fuego' => '🔥','guitarra' => '🎸','rock' => '🤘'];
            $selectedReaction = $reactionChoices[$p['yo_reaccion'] ?? ''] ?? ''; ?>
          <details class="post-reactions" data-reaction-menu>
            <summary class="accion reaction-toggle <?= $selectedReaction !== '' ? 'on' : '' ?>" aria-label="Elegir reacción<?= $selectedReaction !== '' ? ' · ' . $selectedReaction : '' ?>" title="Elegir reacción">
              <?php if ($selectedReaction !== ''): ?><span class="reaction-selected" aria-hidden="true"><?= $selectedReaction ?></span><?php else: ?><svg class="social-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 14a4 4 0 0 0 8 0M8 9h.01M16 9h.01"/></svg><?php endif; ?>
              <svg class="reaction-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 10 4 4 4-4"/></svg>
            </summary>
            <div class="reaction-palette" role="group" aria-label="Elige una reacción; toca otra vez para quitarla">
            <?php foreach ($reactionChoices as $key => $emoji): ?>
              <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>">
                <?= csrfCampo() ?><input type="hidden" name="accion" value="reaccion"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
                <button type="submit" name="reaccion" value="<?= e($key) ?>" class="reaction-button <?= ($p['yo_reaccion'] ?? '') === $key ? 'on' : '' ?>" aria-pressed="<?= ($p['yo_reaccion'] ?? '') === $key ? 'true' : 'false' ?>" aria-label="<?= ($p['yo_reaccion'] ?? '') === $key ? 'Quitar' : 'Reaccionar con' ?> <?= e($key) ?>, <?= (int) ($p['reacciones_' . $key] ?? 0) ?> reacciones"><span aria-hidden="true"><?= $emoji ?></span><small><?= (int) ($p['reacciones_' . $key] ?? 0) ?></small></button>
              </form>
            <?php endforeach; ?>
            </div>
          </details>
        <?php endif; ?>
        </div>
        <?php if (array_key_exists('yo_guardada', $p)): ?>
          <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" class="post-save-form" data-sync-action="guardar">
            <?= csrfCampo() ?><input type="hidden" name="accion" value="guardar_publicacion"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
            <button type="submit" class="accion <?= !empty($p['yo_guardada']) ? 'on' : '' ?>" aria-pressed="<?= !empty($p['yo_guardada']) ? 'true' : 'false' ?>" aria-label="<?= !empty($p['yo_guardada']) ? 'Quitar de guardados' : 'Guardar publicación' ?>"><span aria-hidden="true">🔖</span><span class="solo-lg"><?= !empty($p['yo_guardada']) ? 'Guardada' : 'Guardar' ?></span></button>
          </form>
        <?php endif; ?>
        <a class="accion accion-comment" href="<?= e(anclaAplicacion('#comentario-form-' . (int)$p['id'])) ?>" aria-label="Ir a comentarios, <?= (int)$p['comentarios'] ?> comentarios"><svg class="social-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5a7.5 7.5 0 0 1-8 7.5 8.8 8.8 0 0 1-3.5-.7L4 20l1.1-3.5A7.2 7.2 0 0 1 4 12c0-4.1 3.6-7.5 8-7.5s8 3.1 8 7Z"/></svg> <?= (int) $p['comentarios'] ?> <span class="solo-lg">Comentar</span></a>
        <?php if (!$esMia && repostComentariosDisponible()): ?><details class="post-share-sheet" data-sheet data-sheet-title="Compartir con mi escena"><summary class="accion" aria-label="Compartir publicación"><svg class="social-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m17 3 4 4-4 4M3 7h18M7 21l-4-4 4-4m14 4H3"/></svg><?= (int)($p['reposts'] ?? 0) ?><span class="solo-lg">Compartir</span></summary><form method="POST" action="<?= e(rutaAplicacion('repost-accion.php', 'POST')) ?>" class="post-share-form"><?= csrfCampo() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><label for="<?= e($repostControl) ?>">Tu comentario (opcional)</label><textarea id="<?= e($repostControl) ?>" name="texto" rows="4" maxlength="500" placeholder="Qué te gustó o por qué la recomiendas…"><?= e(notaRepostPara($yo, (int)$p['id'], $yo)) ?></textarea><p>Se comparte una referencia a la publicación original. Si cambia su privacidad, el contenido deja de mostrarse a quienes no tengan acceso.</p><button class="btn btn-primary" type="submit" name="accion" value="guardar"><?= !empty($p['yo_reposte']) ? 'Guardar comentario' : 'Compartir en mi perfil' ?></button><?php if (!empty($p['yo_reposte'])): ?><button class="btn btn-ghost" type="submit" name="accion" value="retirar" formnovalidate>Retirar de mi perfil</button><?php endif; ?></form></details><?php elseif (!$esMia): ?><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>">
          <?= csrfCampo() ?><input type="hidden" name="accion" value="repostear"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
          <button type="submit" class="accion <?= !empty($p['yo_reposte']) ? 'on' : '' ?>" aria-label="Compartir publicación" aria-pressed="<?= !empty($p['yo_reposte']) ? 'true' : 'false' ?>"><svg class="social-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m17 3 4 4-4 4M3 7h18M7 21l-4-4 4-4m14 4H3"/></svg> <?= (int) ($p['reposts'] ?? 0) ?> <span class="solo-lg"><?= !empty($p['yo_reposte']) ? 'Compartido' : 'Compartir' ?></span></button>
        </form><?php endif; ?>
        <?php if (!empty($p['audio']) && social3Disponible()): ?><a class="accion" href="<?= e(rutaAplicacion('listas.php?agregar=' . (int) $p['id'])) ?>" aria-label="Añadir pista a una playlist" title="Añadir a playlist">＋ <span class="solo-lg">Playlist</span></a><?php endif; ?>
        <?php if (!$esMia && social3Disponible()): ?><details class="post-more-actions" data-sheet data-sheet-title="Reportar publicación"><summary aria-label="Más opciones de publicación" title="Más opciones">•••</summary>
          <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" class="post-report-form">
            <?= csrfCampo() ?><input type="hidden" name="accion" value="reportar_publicacion"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
            <label>Reportar publicación<select name="motivo" required><option value="spam">Spam</option><option value="acoso">Acoso</option><option value="suplantacion">Suplantación</option><option value="derechos">Derechos de autor</option><option value="contenido">Contenido inapropiado</option><option value="otro">Otro</option></select></label>
            <textarea name="detalle" maxlength="500" rows="2" placeholder="Contexto (opcional)"></textarea><button type="submit" class="btn btn-ghost btn-sm">Enviar reporte</button>
          </form>
        </details><?php endif; ?>
        <?php if ($puedeBorrar): ?>
          <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" class="push-right" data-confirm="¿Eliminar esta publicación?">
            <?= csrfCampo() ?>
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="volver" value="<?= e($volver) ?>">
            <button type="submit" class="accion accion-borrar" aria-label="Eliminar publicación"><svg class="social-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg><span class="solo-lg">Eliminar</span></button>
          </form>
        <?php endif; ?>
      </footer>

      <details class="post-comments" data-sheet data-sheet-title="Comentarios" id="comentario-form-<?= (int)$p['id'] ?>"><summary>Comentarios <span><?= (int)$p['comentarios'] ?></span></summary>
      <div data-comment-thread data-post-id="<?= (int)$p['id'] ?>">
      <?php if ($comentarios): ?>
        <ul class="comentarios" data-comment-list>
          <?php foreach ($comentarios as $c): ?>
            <li>
              <img src="<?= e(musicmadPublicImageUrl($c['avatar'])) ?>" alt="" class="com-avatar" width="28" height="28" loading="lazy">
              <div><a href="<?= e(rutaAplicacion('musico.php?id=' . (int) $c['uid'])) ?>"><strong><?= e($c['nombre']) ?></strong></a>
                <?= e($c['texto']) ?> <small><?= e(hace($c['created_at'])) ?></small>
                <div data-comment-thread data-post-id="<?= (int)$p['id'] ?>" data-parent-id="<?= (int)$c['id'] ?>"><div data-comment-list>
                <?php foreach (($respuestas[(int) $c['id']] ?? []) as $respuesta): ?>
                  <div class="comment-reply"><img src="<?= e(musicmadPublicImageUrl($respuesta['avatar'])) ?>" alt="" class="com-avatar"><span><a href="<?= e(rutaAplicacion('musico.php?id=' . (int) $respuesta['uid'])) ?>"><strong><?= e($respuesta['nombre']) ?></strong></a> <?= e($respuesta['texto']) ?> <small><?= e(hace($respuesta['created_at'])) ?></small></span></div>
                <?php endforeach; ?>
                </div><?php if (count($respuestas[(int)$c['id']] ?? []) >= 3): ?><button type="button" class="btn btn-ghost btn-sm" data-comments-more data-before="<?= min(array_column($respuestas[(int)$c['id']], 'id')) ?>">Ver respuestas anteriores</button><?php endif; ?></div>
                <?php if (array_key_exists('yo_guardada', $p)): ?>
                  <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" class="reply-form" data-sync-action="responder">
                    <?= csrfCampo() ?><input type="hidden" name="accion" value="responder_comentario"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="comentario" value="<?= (int) $c['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
                    <input type="text" name="texto" maxlength="400" placeholder="Responder…" aria-label="Responder a <?= e($c['nombre']) ?>" required><button type="submit">Responder</button>
                  </form>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?><ul class="comentarios" data-comment-list></ul><?php endif; ?>
      <?php if ((int)$p['comentarios'] > count($comentarios)): ?><button type="button" class="btn btn-ghost btn-sm" data-comments-more data-before="<?= $comentarios ? min(array_column($comentarios, 'id')) : 0 ?>">Ver comentarios anteriores</button><?php endif; ?>
      </div>

      <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" class="comentar" data-sync-action="comentar">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="comentar">
        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <input type="hidden" name="volver" value="<?= e($volver) ?>">
        <input type="text" name="texto" maxlength="400" placeholder="Escribe un comentario…" aria-label="Comentario" required>
        <button type="submit" class="btn btn-primary btn-sm" aria-label="Publicar comentario"><svg class="social-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m4 4 17 8-17 8 3-8Z"/><path d="M7 12h14"/></svg><span class="solo-lg">Comentar</span></button>
      </form>
      </details>
    </article>
    <?php
}
