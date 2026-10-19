<?php
$con = $con ?? 0;
$chatPrefs = $chatPrefs ?? preferenciasChat((int) sesion('usuario_id'));
?>
<details class="chat-preferences" data-sheet data-sheet-title="Privacidad del chat">
 <summary aria-label="Privacidad del chat" title="Privacidad del chat"><?php if (!empty($otro)): ?><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 3-1 3-3 1-2 3 2 2-1 3 3 2 2-1 3 1 2-2 3-1-1-3 1-3-2-2-3-1Z"/><circle cx="12" cy="12" r="3"/></svg><?php else: ?>Privacidad del chat<?php endif; ?></summary>
 <form method="POST" action="<?= e(rutaAplicacion('mensajes.php', 'POST')) ?>" data-chat-preferences>
  <?= csrfCampo() ?><input type="hidden" name="accion" value="preferencias_chat"><input type="hidden" name="con" value="<?= (int)$con ?>">
  <label><input type="checkbox" name="mostrar_presencia" value="1" <?= $chatPrefs['mostrar_presencia'] ? 'checked' : '' ?>> Mostrar cuando estoy en línea</label>
  <label><input type="checkbox" name="mostrar_escribiendo" value="1" <?= $chatPrefs['mostrar_escribiendo'] ? 'checked' : '' ?>> Mostrar cuando escribo</label>
  <label><input type="checkbox" name="confirmar_lectura" value="1" <?= $chatPrefs['confirmar_lectura'] ? 'checked' : '' ?>> Compartir confirmaciones de lectura</label>
  <button type="submit" class="btn btn-secondary btn-sm">Guardar preferencias</button><p role="status"></p>
 </form>
</details>
