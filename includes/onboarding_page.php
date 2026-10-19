<?php

requerirSesion();
if ($pendingEmail = destinoCorreoPendiente((int) sesion('usuario_id'))) {
    header('Location: ' . $pendingEmail);
    exit;
}
header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$uid = (int) sesion('usuario_id');
$ready = onboardingDisponible();
$state = estadoOnboarding($uid);
$step = (int) ($state['paso'] ?? 1);
$music = obtenerPerfilMusical($uid);
$profile = opcionesPortafolio($uid);
$suggested = [];
if ($step === 3) {
    [$policy, $params] = filtroAccesoContenido($uid, 'u.id', 'onboard_people');
    $st = getDB()->prepare("SELECT u.id,u.nombre,u.apellido,u.avatar,pm.instrumento,pm.generos,
        EXISTS(SELECT 1 FROM seguidores s WHERE s.seguidor_id=:self AND s.seguido_id=u.id) AS seguido
        FROM usuarios u LEFT JOIN perfiles_musicales pm ON pm.usuario_id=u.id
        LEFT JOIN perfiles_privacidad pr ON pr.usuario_id=u.id
        WHERE u.activo=1 AND u.id<>:other AND COALESCE(pr.visibilidad,'publico')='publico' AND ($policy)
        ORDER BY seguido DESC,u.created_at DESC,u.id DESC LIMIT 100");
    $st->execute([':self' => $uid, ':other' => $uid] + $params);
    $suggested = $st->fetchAll();
    $genres = array_map('trim', explode(',', $music['generos']));
    foreach ($suggested as &$person) {
        $person['afinidad'] = count(array_intersect($genres, array_map('trim', explode(',', $person['generos'] ?? ''))));
    }
    unset($person);
    usort($suggested, static fn ($a, $b) => [$b['seguido'], $b['afinidad'], $b['id']] <=> [$a['seguido'], $a['afinidad'], $a['id']]);
    $suggested = array_slice($suggested, 0, 24);
    $preselected = 0;
    foreach ($suggested as &$person) {
        $person['selected'] = (bool) $person['seguido'] && $preselected < 12;
        if ($person['selected']) {
            $preselected++;
        }
    }
    unset($person);
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$configured = $step === 5 && pushConfigurado();
$bound = false;
if ($configured) {
    $st = getDB()->prepare('SELECT 1 FROM push_subscriptions p JOIN musicmad_push_bindings b ON b.subscription_id=p.id WHERE p.usuario_id=? AND b.device_selector=? LIMIT 1');
    $st->execute([$uid, (string) sesion('device_selector')]);
    $bound = (bool) $st->fetchColumn();
}
$pageTitle = 'Prepara tu escena';
$pageCss = ['CSS/social.css', 'CSS/account.css', 'CSS/onboarding.css'];
$pageJs = ['js/push.js', 'js/onboarding.js'];
$onboardingLayout = $step < 6;
require __DIR__ . '/header.php';
require __DIR__ . '/navbar.php';
?>
<main class="container onboarding-page" data-required-onboarding>
  <header class="onboarding-hero"><div>
    <span><?= e(mmTexto('TU MÚSICA / TU ESCENA')) ?></span>
    <h1><?= e(mmTexto('Tu escena empieza contigo.')) ?></h1>
    <p><?= e(mmTexto('Prepara tu perfil para encontrar músicos y música que te interesen. Tus elecciones se guardan en cada paso.')) ?></p>
  </div></header>
  <?php selectorIdiomaMusicMad('bienvenida'); ?>
  <?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e(mmTexto($flash[1])) ?></p><?php endif; ?>
  <?php if (!$ready): ?>
    <section class="onboarding-card"><h2><?= e(mmTexto('Falta actualizar el tutorial')) ?></h2><p><?= e(mmTexto('El administrador debe importar la actualización 033. Tus datos se conservan.')) ?></p></section>
  <?php elseif ($step < 6): ?>
    <ol class="onboarding-steps" aria-label="<?= e(mmTexto('Tu recorrido')) ?>">
      <?php foreach (['Instrumento','Géneros','Músicos','Alias','Dispositivo'] as $index => $label): ?>
      <li class="<?= $index + 1 === $step ? 'is-current' : ($index + 1 < $step ? 'is-done' : '') ?>" <?= $index + 1 === $step ? 'aria-current="step"' : '' ?>><b><?= $index + 1 ?></b><span><?= e(mmTexto($label)) ?></span></li>
      <?php endforeach; ?>
    </ol>
    <section class="onboarding-card">
      <span class="onboarding-kicker"><?= e(mmTexto('Paso {step} de 5', ['step' => $step])) ?></span>
      <h2><?= e(mmTexto(['', '¿Qué instrumento tocas?', 'Elige tus sonidos', 'Encuentra a tu gente', 'Tu nombre en MusicMad', 'Lleva tu música contigo'][$step])) ?></h2>
      <form action="<?= e(rutaAplicacion('bienvenida-accion.php', 'POST')) ?>" method="POST" class="onboarding-form" data-onboarding-form>
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="paso" value="<?= $step ?>">
        <input type="hidden" name="revision" value="<?= (int) $state['revision'] ?>">
        <input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>">
        <?php if ($step === 1): ?>
          <p><?= e(mmTexto('Indica al menos un instrumento. Puedes escribir varios. La ciudad es opcional.')) ?></p>
          <label for="onboard-instrument"><?= e(mmTexto('Tus instrumentos')) ?></label>
          <input id="onboard-instrument" name="instrumento" list="onboard-instruments" minlength="2" maxlength="60" required value="<?= e($music['instrumento']) ?>" placeholder="Guitarra, voz, batería…">
          <datalist id="onboard-instruments"><?php foreach (['Guitarra','Batería','Voz','Piano','Bajo','Violín','Saxofón','Producción musical'] as $instrument): ?><option value="<?= e($instrument) ?>"></option><?php endforeach; ?></datalist>
          <label for="onboard-city"><?= e(mmTexto('Ciudad (opcional)')) ?></label><input id="onboard-city" name="ciudad" maxlength="80" value="<?= e($music['ciudad']) ?>">
        <?php elseif ($step === 2): $selected = array_map('trim', explode(',', $music['generos'])); ?>
          <p><?= e(mmTexto('Elige de tres a seis géneros. Usaremos estos sonidos en tu feed y tus sugerencias.')) ?></p>
          <p data-onboarding-counter role="status"></p>
          <fieldset class="onboarding-genres"><legend class="sr-only"><?= e(mmTexto('Tus géneros musicales')) ?></legend>
          <?php foreach (GENEROS as $genre): ?><label><input type="checkbox" name="generos[]" value="<?= e($genre) ?>" <?= in_array($genre, $selected, true) ? 'checked' : '' ?>><span><?= e($genre) ?></span></label><?php endforeach; ?>
          </fieldset>
        <?php elseif ($step === 3): ?>
          <p><?= e(mmTexto('Selecciona de tres a doce perfiles para seguir. Primero verás tus conexiones y músicos con géneros afines. Desmarcar aquí no deja de seguir a tus contactos actuales.')) ?></p>
          <p data-onboarding-counter role="status"></p>
          <?php if (count($suggested) < 3): ?><p class="alert alert-info"><?= e(mmTexto('La comunidad necesita al menos tres perfiles públicos disponibles para este paso. Pide al administrador que invite más músicos; no crearemos seguimientos ficticios.')) ?></p><?php endif; ?>
          <fieldset class="onboarding-people"><legend class="sr-only"><?= e(mmTexto('Perfiles a los que quieres seguir')) ?></legend>
          <?php foreach ($suggested as $person): ?><label><input type="checkbox" name="musicos[]" value="<?= (int) $person['id'] ?>" <?= $person['selected'] ? 'checked' : '' ?>><img src="<?= e(musicmadPublicImageUrl($person['avatar'])) ?>" width="44" height="44" alt="" loading="lazy"><span><strong><?= e($person['nombre'] . ' ' . $person['apellido']) ?></strong><small><?= e($person['instrumento'] ?: mmTexto('Músico de la comunidad')) ?><?= $person['afinidad'] ? ' · ' . e(mmTexto('Géneros afines')) : '' ?></small></span></label><?php endforeach; ?>
          </fieldset>
        <?php elseif ($step === 4): ?>
          <p><?= e(mmTexto('Tu alias identificará tu perfil como /@usuario. No publica tu correo ni cambia tu privacidad.')) ?></p>
          <label for="onboard-slug"><?= e(mmTexto('Alias único')) ?></label><input id="onboard-slug" name="slug" pattern="[a-z][a-z0-9_]{2,29}" minlength="3" maxlength="30" autocapitalize="none" autocomplete="nickname" spellcheck="false" value="<?= e($profile['slug']) ?>" required placeholder="tu_nombre">
          <p><?= e(mmTexto('De 3 a 30 letras minúsculas, números o guion bajo. Empieza con una letra.')) ?></p>
        <?php else: ?>
          <div data-onboarding-device data-push-settings data-push-configured="<?= $configured ? '1' : '0' ?>" data-push-bound="<?= $bound ? '1' : '0' ?>">
            <section class="onboard-device-card"><h3><?= e(mmTexto('Instala MusicMad')) ?></h3><p><?= e(mmTexto('En Android usa Instalar aplicación. En iPhone: Safari → Compartir → Agregar a pantalla de inicio. Después abre MusicMad desde su icono.')) ?></p>
              <button type="button" class="btn btn-secondary" data-pwa-install data-onboard-install><?= e(mmTexto('Instalar o ver instrucciones')) ?></button><p data-onboarding-install-status role="status"></p>
            </section>
            <section class="onboard-device-card"><h3><?= e(mmTexto('Activa tus avisos')) ?></h3><p><?= e(mmTexto('Recibe novedades de tus mensajes y tu música. El permiso lo decides tú en el navegador.')) ?></p>
              <button type="button" class="btn btn-secondary" data-push-enable <?= $configured ? '' : 'disabled' ?>><?= e(mmTexto('Activar avisos')) ?></button><p data-push-status role="status"><?= e(mmTexto($configured ? 'Pulsa Activar avisos para revisar el permiso.' : 'El administrador todavía debe activar el servicio push.')) ?></p>
            </section>
            <input type="hidden" name="notificaciones" value="<?= $bound ? 'activa' : ($configured ? 'sin_activar' : 'servicio_pendiente') ?>">
            <input type="hidden" name="instalacion" value="instrucciones">
            <label class="onboard-device-confirm"><input type="checkbox" name="dispositivo_revisado" value="1" required><?= e(mmTexto('He revisado la instalación y los avisos de este dispositivo.')) ?></label>
            <p><?= e(mmTexto('Si tu navegador no los admite o bloqueaste un permiso, conservamos ese estado. No diremos que la app está instalada o que recibes push si no se confirmó.')) ?></p>
          </div>
        <?php endif; ?>
        <div class="onboarding-actions"><button type="submit" class="btn btn-primary" data-onboarding-next><?= e(mmTexto($step === 5 ? 'Entrar a mi escena' : 'Guardar y continuar')) ?> →</button></div>
      </form>
      <?php if ($step > 1): ?><form action="<?= e(rutaAplicacion('bienvenida-accion.php', 'POST')) ?>" method="POST" class="onboarding-restart"><?= csrfCampo() ?><input type="hidden" name="accion" value="comenzar"><input type="hidden" name="paso" value="<?= $step ?>"><input type="hidden" name="revision" value="<?= (int) $state['revision'] ?>"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><button type="submit" class="btn btn-ghost"><?= e(mmTexto('Revisar mis datos desde el principio')) ?></button><p><?= e(mmTexto('Conservaremos lo que ya escribiste y a quienes sigues.')) ?></p></form><?php endif; ?>
    </section>
  <?php else: ?><section class="onboarding-card"><h2><?= e(mmTexto('Tu escena está lista.')) ?></h2><a class="btn btn-primary" href="feed.php"><?= e(mmTexto('Entrar a Comunidad')) ?></a></section><?php endif; ?>
</main>
<?php require __DIR__ . '/footer.php'; ?>
