<?php
if(!defined('MUSICMAD_TEST_RUN')||MUSICMAD_TEST_RUN!==true){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/auth.php';require dirname(__DIR__).'/includes/social.php';requerirSesion();
$pageTitle='Comprobar navegación al mapa';require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/navbar.php';
?>
<main class="container"><h1>Mapa desde otra sección · QA</h1><p>Prueba de navegación parcial, con datos ficticios y sin ubicación del dispositivo.</p><a class="btn btn-primary" href="qa-map-external.php">Abrir mapa de prueba</a></main>
<?php require dirname(__DIR__).'/includes/footer.php'; ?>
