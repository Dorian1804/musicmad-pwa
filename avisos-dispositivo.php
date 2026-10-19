<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$ready = pushSchemaDisponible();
$configured = $ready && pushConfigurado();
$prefs = $ready ? preferenciasPush((int)sesion('usuario_id')) : array_fill_keys(PUSH_CATEGORIES, 1);
$quiet = horarioSilenciosoPush((int)sesion('usuario_id'));
$controls = controlesPushDisponibles();
$pageTitle = 'Avisos en tu dispositivo';
$pageCss = ['CSS/social.css','CSS/push-settings.css'];
$pageJs = ['js/push.js'];
$navActivo = 'configuracion';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container narrow-wide" data-push-settings>
 <a href="<?= e(rutaAplicacion('configuracion.php')) ?>" class="btn btn-ghost btn-sm">← Configuración</a>
 <header class="page-heading"><span class="community-kicker">TU MÚSICA, A TIEMPO</span><h1>Avisos en tu dispositivo</h1><p>Entérate de las novedades aunque MusicMad esté cerrada. Tú eliges qué recibir.</p></header>
 <section class="card settings-section"><h2>Este dispositivo</h2><p>Los avisos muestran un mensaje general. El contenido de tus conversaciones se queda dentro de MusicMad.</p><p data-push-status role="status"><?= $configured ? 'Revisando los permisos del dispositivo…' : 'Los avisos del dispositivo no están habilitados todavía.' ?></p><div class="form-actions"><button type="button" class="btn btn-primary" data-push-enable <?= $configured ? '' : 'disabled' ?>>Activar avisos</button><button type="button" class="btn btn-ghost" data-push-disable <?= $ready ? '' : 'disabled' ?>>Desactivar aquí</button><button type="button" class="btn btn-secondary" data-push-test <?= $configured && $controls ? '' : 'disabled' ?>>Enviar aviso de prueba</button></div></section>
 <section class="card settings-section"><h2>¿Qué quieres recibir?</h2><form data-push-preferences>
 <?php foreach (['mensajes' => 'Mensajes nuevos','aplausos' => 'Aplausos a tus publicaciones','comentarios' => 'Comentarios','seguidores' => 'Nuevos seguidores','bandas' => 'Novedades de tus bandas','eventos' => 'Eventos y recordatorios'] as $key => $label): ?><label class="settings-check"><input type="checkbox" name="<?= e($key) ?>" <?= $prefs[$key] ? 'checked' : '' ?>> <?= e($label) ?></label><?php endforeach; ?>
 <fieldset class="push-quiet" <?= $controls ? '' : 'disabled' ?>><legend>Horario silencioso</legend>
 <label class="settings-check"><input type="checkbox" name="silencio_activo" <?= $quiet['activo'] ? 'checked' : '' ?>> Pausar avisos en este horario</label>
 <div class="push-hours"><label>Desde<input type="time" name="desde" required value="<?= e($quiet['desde']) ?>"></label><label>Hasta<input type="time" name="hasta" required value="<?= e($quiet['hasta']) ?>"></label></div>
 <label>Zona horaria<select name="zona"><?php foreach (DateTimeZone::listIdentifiers() as $zone): ?><option value="<?= e($zone) ?>" <?= $zone === $quiet['zona'] ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $zone)) ?></option><?php endforeach; ?></select></label>
 <p class="muted">Se aplica a todos tus dispositivos. Las novedades siguen en MusicMad, pero los avisos de este horario no se envían después.</p></fieldset>
 <button type="submit" class="btn btn-secondary" <?= $ready ? '' : 'disabled' ?>>Guardar preferencias</button><p role="status"></p></form></section>
 <section class="card settings-section"><h2>Comprueba tus avisos</h2><ol><li>Activa los avisos en este dispositivo.</li><li>Toca Enviar aviso de prueba. Espera hasta un minuto y revisa la pantalla de bloqueo.</li><li>Al tocar el aviso volverás a esta pantalla. Si no llega, revisa los permisos y contacta al administrador.</li></ol><p class="muted"><?= cronPushReciente() ? 'El proceso de envío se ejecutó recientemente.' : 'El proceso de envío no registra una ejecución reciente. El administrador debe revisar el cron.' ?></p></section>
 <p class="muted">En iPhone, instala MusicMad desde Safari con Compartir → Agregar a la pantalla de inicio y abre la app instalada para activar los avisos. Los permisos y el ahorro de batería dependen de tu dispositivo.</p>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
