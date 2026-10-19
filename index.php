<?php
// ============================================================
//  MusicMad – Página principal
//  index.php
// ============================================================
ob_start(); // evita errores de cabeceras en hosting gratuito
require_once __DIR__ . '/includes/auth.php';

date_default_timezone_set('America/Mexico_City');

$usuarioNombre = sesion('usuario_nombre') ?? '';
$hora = (int) date('H');
$saludo = match (true) {
    $hora >= 5 && $hora < 12 => 'Buenos días',
    $hora >= 12 && $hora < 19 => 'Buenas tardes',
    default => 'Buenas noches',
};

$pageTitle = 'Inicio';
$pageCss = [];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="home-page">
  <section class="hero">
    <div class="hero-inner">
      <div class="hero-copy">
        <?php if (estaAutenticado()): ?>
          <p class="hero-greeting"><?= e($saludo) ?>, <strong><?= e($usuarioNombre) ?></strong></p>
        <?php endif; ?>
        <span class="hero-kicker">La comunidad independiente de músicos</span>
        <h1>Haz ruido.<br><em>Encuentra tu gente.</em></h1>
        <p>Un lugar para compartir lo que haces, conocer músicos cerca de ti y encontrar con quién armar algo de verdad.</p>
        <div class="actions">
          <?php if (!estaAutenticado()): ?>
            <a href="<?= e(rutaAplicacion('registro.php')) ?>" class="btn btn-accent">Entrar a la comunidad <span aria-hidden="true">↗</span></a>
            <a href="<?= e(rutaAplicacion('login.php')) ?>" class="hero-login">Ya tengo cuenta <span aria-hidden="true">→</span></a>
          <?php else: ?>
            <a href="<?= e(rutaAplicacion('feed.php')) ?>" class="btn btn-accent">Volver a la Comunidad <span aria-hidden="true">↗</span></a>
            <a href="<?= e(rutaAplicacion('busco.php')) ?>" class="hero-login">Buscar músicos <span aria-hidden="true">→</span></a>
          <?php endif; ?>
        </div>
        <div class="hero-footnote"><span class="live-dot" aria-hidden="true"></span> Perfiles reales · Música propia · Colaboraciones sin vueltas</div>
      </div>

      <div class="hero-art" aria-hidden="true">
        <div class="hero-art-label"><span>MM / 001</span><span>HECHO PARA HACER MÚSICA</span></div>
        <div class="hero-vinyl"><div class="hero-vinyl-label"><strong>music<br>mad</strong><span>lado A<br>comunidad</span></div></div>
        <div class="hero-wave"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
        <div class="hero-art-caption"><span>01 — CONECTAR</span><strong>La próxima canción<br>empieza con alguien.</strong></div>
        <span class="hero-star">✳</span>
      </div>
    </div>
    <div class="hero-index"><span>01</span><span>COMUNIDAD / MÚSICA / COLABORACIÓN</span><span>DESLIZA PARA EXPLORAR ↓</span></div>
  </section>

  <div class="container home-content">
    <div class="home-section-head">
      <div><span class="home-kicker">No es solo un feed</span><h2>Es tu punto de encuentro.</h2></div>
      <p>De la primera demo a la banda que llevabas tiempo buscando: aquí empieza el siguiente paso.</p>
    </div>
    <div class="features">
      <article class="feature"><span class="feature-number">01</span><div class="feature-glyph" aria-hidden="true">♫</div><h3>Suena a ti</h3><p>Comparte demos, canciones y procesos. Tu perfil cuenta qué tocas y qué música te mueve.</p><a href="<?= e(rutaAplicacion('registro.php')) ?>">Crea tu perfil <span aria-hidden="true">↗</span></a></article>
      <article class="feature"><span class="feature-number">02</span><div class="feature-glyph feature-glyph-lime" aria-hidden="true">⌁</div><h3>Encuentra tu banda</h3><p>Busca vocalistas, bateristas y músicos por instrumento, género y proyecto.</p><a href="<?= e(rutaAplicacion('busco.php')) ?>">Explora “Se busca” <span aria-hidden="true">↗</span></a></article>
      <article class="feature"><span class="feature-number">03</span><div class="feature-glyph feature-glyph-orange" aria-hidden="true">✳</div><h3>Hazlo en comunidad</h3><p>Conversa, sigue a otros músicos y convierte una idea suelta en algo compartido.</p><a href="<?= e(rutaAplicacion('bandas.php')) ?>">Conoce las bandas <span aria-hidden="true">↗</span></a></article>
    </div>

    <section class="nosotros" id="nosotros">
      <span class="home-kicker">La escena se construye entre todos</span>
      <div><h2>Menos algoritmo.<br><em>Más conexión real.</em></h2><p>MusicMad es una red hecha alrededor de lo que importa: encontrar personas con quienes tocar. Publica lo que estás creando, descubre músicos y empieza una conversación que puede terminar en ensayo.</p></div>
      <a href="<?= e(rutaAplicacion('registro.php')) ?>" class="nosotros-link">Arma tu espacio <span aria-hidden="true">↗</span></a>
    </section>
  </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
