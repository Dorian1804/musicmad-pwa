<section class="global-player" id="global-player" data-global-player aria-label="Reproductor de MusicMad" hidden>
  <div class="player-screen-heading"><button type="button" data-player-collapse aria-label="Volver al minirreproductor">⌄</button><span>AHORA SUENA · MUSICMAD</span><a href="biblioteca.html" data-native-navigation>Sin conexión</a></div>
  <div class="player-artwork" aria-hidden="true"><span>MM</span></div>
  <div class="global-player-inner">
    <button class="player-toggle" type="button" data-player-toggle aria-label="Reproducir" title="Reproducir">
      <svg data-player-play-icon viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 12 7-12 7z"/></svg>
      <svg data-player-pause-icon viewBox="0 0 24 24" aria-hidden="true" hidden><path d="M7 5h4v14H7zM15 5h4v14h-4z"/></svg>
    </button>
    <button type="button" class="player-track-info" data-player-expand aria-label="Abrir reproductor a pantalla completa"><strong data-player-title>Elige una pista</strong><small data-player-artist>MusicMad</small></button>
    <button class="player-step" type="button" data-player-prev aria-label="Pista anterior" title="Anterior">‹</button>
    <button class="player-step" type="button" data-player-next aria-label="Siguiente pista" title="Siguiente">›</button>
    <label class="player-seek-label"><span class="sr-only">Posición de reproducción</span><input type="range" min="0" max="1000" value="0" data-player-seek aria-label="Posición de reproducción"><span class="player-time" data-player-time>0:00 / 0:00</span></label>
    <button class="player-queue-toggle" type="button" data-player-queue-toggle aria-expanded="false" aria-controls="player-queue" title="Ver cola">Cola <span data-player-queue-count>0</span></button>
    <button class="player-offline" type="button" data-player-offline aria-label="Guardar la pista actual para escuchar sin conexión" title="Guardar sin conexión">↓</button>
    <button class="player-close" type="button" data-player-close aria-label="Cerrar reproductor" title="Cerrar">×</button>
    <canvas class="player-visualizer" data-player-visualizer width="360" height="22" aria-hidden="true"></canvas>
  </div>
  <div class="player-screen-options"><label>Volumen<input type="range" min="0" max="1" step="0.05" value="1" data-player-volume></label><label>Velocidad<select data-player-rate><option value="0.75">0.75×</option><option value="1" selected>1×</option><option value="1.25">1.25×</option><option value="1.5">1.5×</option></select></label><button type="button" data-player-shuffle aria-pressed="false">Aleatorio</button><button type="button" data-player-repeat aria-pressed="false">Repetir pista</button><button type="button" data-player-clear>Vaciar cola</button></div>
  <audio data-global-audio preload="metadata"></audio>
  <ol class="player-queue" id="player-queue" data-player-queue hidden></ol>
</section>
<footer class="footer"><small>2026 &copy; MusicMad</small></footer>
<dialog class="photo-viewer" id="photo-viewer" aria-label="Foto de perfil ampliada">
  <button type="button" class="photo-viewer-close" data-photo-close aria-label="Cerrar foto ampliada">×</button>
  <img id="photo-viewer-image" src="" alt="Foto de perfil ampliada">
</dialog>
</body>
</html>
