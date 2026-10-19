<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/account_forms.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
$uid = (int)sesion('usuario_id');
$ready = controlesCuentaDisponibles();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$labels = ['datos_exportados' => 'Descarga de tus datos','eliminacion_solicitada' => 'Eliminación solicitada','eliminacion_cancelada' => 'Eliminación cancelada','rol_asignado' => 'Permiso de moderación asignado','rol_retirado' => 'Permiso de moderación retirado','REPLACE_WITH_LOCAL_VALUE_cambiado' => 'Contraseña actualizada','dispositivo_revocado' => 'Dispositivo cerrado','otros_revocados' => 'Otros accesos cerrados','reporte_resuelto' => 'Reporte revisado','anuncio_moderado' => 'Anuncio revisado'];
$history = $ready ? historialSeguridadCuenta($uid, idCampo($_GET, 'antes') ?: PHP_INT_MAX) : [];
$next = count($history) > 30 ? (int)$history[29]['id'] : 0;
$history = array_slice($history, 0, 30);
$pageTitle = 'Tus datos';
$pageCss = ['CSS/social.css','CSS/account.css'];
$navActivo = 'configuracion';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container account-data-page">
 <a class="account-back" href="<?= e(rutaAplicacion('configuracion.php')) ?>">← Configuración</a>
 <header class="account-data-hero"><span>TU ESPACIO, TUS DECISIONES</span><h1>Tus datos</h1><p>Tu información y tu actividad de seguridad, en un solo lugar.</p><svg viewBox="0 0 120 100" aria-hidden="true"><path d="M20 18h62v66H20zM36 35h32M36 48h24M36 61h18"/><circle cx="85" cy="70" r="19"/><path d="M85 58v24M77 74l8 8 8-8"/></svg></header>
 <?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></p><?php endif; ?>
 <?php if (!$ready): ?><section class="card"><h2>Falta activar esta actualización</h2><p>Importa la migración017 de controles de cuenta.</p></section><?php else: ?>
 <div class="account-data-grid">
 <section class="card account-data-card"><span class="account-data-step">01 · TU COPIA</span><h2>Descarga tu información</h2><p>Perfil, publicaciones, mensajes, listas, bandas, agenda y preferencias en un archivo JSON.</p><p class="account-caption">El archivo contiene datos personales: guárdalo en un lugar privado. Tus audios, fotos y vídeos se incluyen como referencias; descarga los archivos desde tus publicaciones antes de eliminar la cuenta.</p>
 <details data-sheet data-sheet-title="Confirma tu descarga" id="exportar-datos"><summary class="btn btn-primary">Preparar descarga <span aria-hidden="true">↓</span></summary><div class="sheet-panel"><form method="POST" action="<?= e(rutaAplicacion('exportar-datos.php', 'POST')) ?>" data-native-navigation class="account-confirm-form"><?= csrfCampo() ?><?php camposConfirmacionCuenta($uid, 'exportar'); ?><button type="submit" class="btn btn-primary">Descargar mis datos</button></form><p class="account-caption">Descarga privada, sin contraseñas ni claves de acceso.</p></div></details>
 </section>
 <section class="card account-data-card account-delete-card"><span class="account-data-step">02 · TU CUENTA</span><h2>Eliminar mi cuenta</h2><p>Tu cuenta se oculta y los accesos se cierran inmediatamente. Tendrás siete días para cancelar la eliminación.</p><p class="account-caption">Saldrás de tus bandas: la administración pasa a otro integrante o se archiva la banda si queda vacía. Recuperar la cuenta no recupera esos permisos. Después de siete días, el proceso del servidor borra la cuenta, su contenido y sus archivos sin referencias compartidas.</p>
 <details data-sheet data-sheet-title="Eliminar tu cuenta" id="eliminar-cuenta"><summary class="btn btn-ghost">Revisar eliminación <span aria-hidden="true">→</span></summary><div class="sheet-panel"><form method="POST" action="<?= e(rutaAplicacion('eliminar-cuenta.php', 'POST')) ?>" data-native-navigation class="account-confirm-form"><?= csrfCampo() ?><?php camposConfirmacionCuenta($uid, 'eliminar'); ?><label for="confirmar-eliminar">Escribe ELIMINAR para confirmar</label><input id="confirmar-eliminar" name="confirmacion" autocomplete="off" pattern="ELIMINAR" required><label class="account-check"><input type="checkbox" name="entiendo" value="1" required><span>Entiendo el plazo de siete días y la salida de mis bandas.</span></label><button type="submit" class="btn btn-danger">Solicitar eliminación</button></form></div></details>
 </section></div>
 <section class="card account-history"><header><div><span class="account-data-step">ACTIVIDAD DE SEGURIDAD</span><h2>Movimientos de tu cuenta</h2></div><a href="<?= e(rutaAplicacion('configuracion.php#dispositivos')) ?>">Ver dispositivos →</a></header>
 <?php if (!$history): ?><p>Aquí aparecerán tus descargas y cambios de seguridad.</p><?php else: ?><ol><?php foreach ($history as $row): ?><li><span><?= e($labels[$row['accion']] ?? 'Cambio de cuenta') ?></span><time datetime="<?= e(str_replace(' ', 'T', $row['created_at']) . 'Z') ?>"><?= e((new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('d/m/Y · H:i')) ?></time></li><?php endforeach; ?></ol><?php endif; ?><?php if ($next): ?><a class="btn btn-ghost" href="datos.php?antes=<?= $next ?>">Ver movimientos anteriores</a><?php endif; ?>
 </section><?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
