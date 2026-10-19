<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/navigation.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$pageTitle = 'Todo MusicMad';
$navActivo = 'menu';
$pageCss = ['CSS/social.css'];
$menuItems = navegacionPermitida((int)sesion('usuario_id'), rolSitio((int)sesion('usuario_id')));
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container account-hub">
  <header><span class="community-kicker">TU ESPACIO</span><h1>Todo MusicMad</h1><p>Tu música, tus conexiones y tu cuenta.</p></header>
  <nav aria-label="Más secciones">
  <?php foreach (['Escena','Tu música','Tu cuenta','Administración'] as $group):$sectionItems = array_filter($menuItems, static fn ($item) => $item['group'] === $group && !in_array($item['id'], ['menu','subir','logout'], true));
      if (!$sectionItems) {
          continue;
      } ?>
    <section class="hub-section"><h2><?= e(mmTexto($group)) ?></h2><div class="account-hub-grid">
      <?php foreach ($sectionItems as $item): ?><a class="card" href="<?= e($item['href']) ?>"><span class="hub-icon"><?= iconoNavegacion($item['icon']) ?></span><strong><?= e(mmTexto($item['label'])) ?></strong><small><?= e($item['description']) ?></small></a><?php endforeach; ?>
    </div></section>
  <?php endforeach; ?>
  </nav>
  <label class="theme-choice">Apariencia<select data-theme-choice><option value="system">Usar el tema del teléfono</option><option value="light">Claro</option><option value="dark">Oscuro</option></select></label>
  <p><a class="btn btn-ghost" href="biblioteca.html" data-native-navigation>Mi música sin conexión</a></p>
  <div class="account-hub-actions"><button type="button" class="btn btn-ghost" data-theme-toggle>Tema claro / oscuro</button><button type="button" class="btn btn-ghost" data-pwa-install hidden>Instalar MusicMad</button>
    <?php foreach ($menuItems as $item) {
        if ($item['id'] === 'logout') {
            renderEnlaceNavegacion($item, '');
        }
    } ?>
  </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
