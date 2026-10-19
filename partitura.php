<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
$id = idCampo($_GET, 'id');
$st = getDB()->prepare('SELECT pp.archivo,p.usuario_id FROM publicacion_partitura pp JOIN publicaciones p ON p.id=pp.publicacion_id JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 WHERE p.id=:id LIMIT 1');
$st->execute([':id' => $id]);
$partitura = $st->fetch();
$archivo = $partitura['archivo'] ?? false;
if ($partitura && social3Disponible() && !puedeVerPublicacionSocial3((int) sesion('usuario_id'), $id)) {
    http_response_code(404);
    exit('Partitura no encontrada.');
}
if (!is_string($archivo) || !preg_match('/^[a-f0-9]{24}\.pdf$/', $archivo)) {
    http_response_code(404);
    exit('Partitura no encontrada.');
}
$dir = musicmadMediaDirectoryForFile('partituras', $archivo);
if (!is_file($dir . '/' . $archivo)) {
    recuperarMedioDesdeRespaldo('partituras', $archivo);
}
$base = realpath($dir);
$ruta = realpath($dir . '/' . $archivo);
if (is_link($dir) || $base === false || $ruta === false || dirname($ruta) !== $base || is_link($dir . '/' . $archivo) || !is_file($ruta)) {
    http_response_code(404);
    exit('Partitura no encontrada.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="partitura-' . $id . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($ruta));
readfile($ruta);
