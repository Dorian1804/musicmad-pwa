<?php
require_once __DIR__ . '/navigation.php';
(static function (string $navActivo, bool $onboardingLayout): void {
    $logged = estaAutenticado();
    $uid = $logged ? (int) sesion('usuario_id') : 0;
    $role = $logged ? rolSitio($uid) : '';
    $items = navegacionPermitida($uid, $role);
    $byId = array_column($items, null, 'id');
    $active = seccionNavegacionActual((string) ($_SERVER['SCRIPT_NAME'] ?? '')) ?: $navActivo;
    if (!empty($onboardingLayout)) {
        ?><body class="onboarding-layout"><header class="navbar"><div class="navbar-inner">
      <a class="brand" href="<?= e(rutaAplicacion('bienvenida.php')) ?>"><img src="img/icon-192.png" width="32" height="32" alt=""><span>MusicMad</span></a>
      <?php renderEnlaceNavegacion($byId['logout'], $active); ?>
    </div></header><?php
        return;
    }
    $badgeNotif = $logged && function_exists('contarNoLeidas') ? contarNoLeidas($uid) : 0;
    $badgeMsg = $logged && function_exists('contarMensajesSinLeer') ? contarMensajesSinLeer($uid) : 0;
    if ($logged && function_exists('social3Disponible') && social3Disponible()) {
        $badgeNotif += contarSolicitudesPendientesSeguimiento($uid);
    }
    $badges = ['messages' => $badgeMsg, 'notifications' => $badgeNotif];
    ?>
<body class="<?= $logged ? 'has-mobile-dock has-app-sidebar' : '' ?>">
<header class="navbar" data-unread-messages="<?= $badgeMsg ?>" data-unread-notifications="<?= $badgeNotif ?>">
  <div class="navbar-inner">
    <a href="<?= e(rutaAplicacion($logged ? 'feed.php' : 'index.php')) ?>" class="brand">
      <svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#d7f05a"/><path d="M5 23V9L11 18L16 9L21 18L27 9V23" fill="none" stroke="#25162e" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span class="brand-wordmark"><strong>MusicMad</strong><small>MÚSICOS · EN ESCENA</small></span>
    </a>
    <?php if ($logged): ?>
    <div class="mobile-header-actions">
      <a href="<?= e(rutaAplicacion($byId['notificaciones']['href'])) ?>" aria-label="Notificaciones<?= $badgeNotif ? ', ' . $badgeNotif . ' nuevas' : '' ?>"><?= iconoNavegacion('bell') ?><?php if ($badgeNotif): ?><b class="dock-badge"><?= $badgeNotif > 99 ? '99+' : $badgeNotif ?></b><?php endif; ?></a>
      <a href="<?= e(rutaAplicacion($byId['menu']['href'])) ?>" aria-label="Abrir opciones de cuenta"><img src="<?= e(musicmadPublicImageUrl(sesion('usuario_avatar') ?: AVATAR_DEFECTO)) ?>" alt=""></a>
    </div>
    <?php endif; ?>
    <button class="nav-toggle" id="navToggle" type="button" aria-label="Abrir navegación" aria-controls="navLinks" aria-expanded="false"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    <nav class="nav-links" id="navLinks" aria-label="Accesos de cabecera">
      <?php if ($logged): ?>
        <div class="nav-tools"><?php foreach (['musicos','mensajes','notificaciones'] as $key) {
            renderEnlaceNavegacion($byId[$key], $active, $badges);
        } ?></div>
        <details class="nav-account"><summary><img class="nav-avatar" src="<?= e(musicmadPublicImageUrl(sesion('usuario_avatar') ?: AVATAR_DEFECTO)) ?>" alt=""><span><?= e(sesion('usuario_nombre')) ?></span><b aria-hidden="true">⌄</b></summary>
          <div class="nav-account-menu"><?php foreach (['perfil','perfil_publico','configuracion','menu'] as $key) {
              renderEnlaceNavegacion($byId[$key], $active);
          } ?>
            <button class="nav-theme-toggle" type="button" data-theme-toggle aria-pressed="false">Tema claro / oscuro</button>
            <?php renderEnlaceNavegacion($byId['logout'], $active); ?>
          </div>
        </details>
      <?php else: ?><div class="nav-tools"><a href="<?= e(rutaAplicacion('index.php#nosotros')) ?>">Acerca de</a><a href="<?= e(rutaAplicacion('login.php')) ?>">Iniciar sesión</a></div><?php endif; ?>
      <button type="button" class="nav-install" data-pwa-install hidden>Instalar app</button>
    </nav>
  </div>
</header>
<?php if ($logged): ?>
<aside class="app-sidebar" aria-label="Todas las secciones">
  <nav aria-label="Barra lateral de MusicMad">
  <?php foreach (['Escena','Tu música','Tu cuenta','Administración'] as $group): $groupItems = array_filter($items, static fn ($item) => $item['group'] === $group && !in_array($item['id'], ['subir','menu'], true));
      if (!$groupItems) {
          continue;
      } ?>
    <section class="sidebar-section"><h2><?= e(mmTexto($group)) ?></h2><?php foreach ($groupItems as $item) {
        renderEnlaceNavegacion($item, $active, $badges);
    } ?></section>
  <?php endforeach; ?>
  </nav>
</aside>
<nav class="mobile-dock" aria-label="Navegación principal">
  <?php foreach ($items as $item) {
      if (!empty($item['dock'])) {
          renderEnlaceNavegacion($item, $active, $badges, 'dock');
      }
  } ?>
</nav>
<?php endif; ?>

<?php
})($navActivo ?? '', !empty($onboardingLayout));
