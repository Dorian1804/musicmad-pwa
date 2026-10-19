<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/account_forms.php';
requerirAdmin();
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}
$ready = controlesCuentaDisponibles();
$message = '';
$ok = false;
$uid = (int)sesion('usuario_id');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    try {
        asignarRolSitio($uid, idCampo($_POST, 'usuario'), textoCampo($_POST, 'rol'), textoCampo($_POST, 'REPLACE_WITH_LOCAL_VALUE'), textoCampo($_POST, 'codigo'));
        $message = 'Permiso guardado. Esa cuenta debe iniciar sesión de nuevo.';
        $ok = true;
        if (!estaAutenticado()) {
            header('Location: login.php', true, 303);
            exit;
        }
    } catch (DomainException|LogicException|OverflowException $error) {
        $message = $error->getMessage();
    } catch (Throwable $error) {
        error_log('Site role assignment: ' . get_class($error));
        $message = 'No se pudo guardar el cambio.';
    }
}
$q = mb_substr(trim(textoCampo($_GET, 'q')), 0, 80);
$users = [];
$team = [];
if ($ready) {
    $st = getDB()->prepare("SELECT u.id,u.nombre,u.apellido,u.rol,r.rol AS permiso FROM usuarios u LEFT JOIN musicmad_site_roles r ON r.usuario_id=u.id WHERE u.activo=1 AND (CONCAT(u.nombre,' ',u.apellido) LIKE ? OR CAST(u.id AS CHAR)=?) ORDER BY u.nombre,u.id LIMIT 30");
    $st->execute(['%' . addcslashes($q, '%_\\') . '%',$q]);
    $users = $st->fetchAll();
    $team = getDB()->query("SELECT u.id,u.nombre,u.apellido,u.rol,r.rol AS permiso FROM usuarios u LEFT JOIN musicmad_site_roles r ON r.usuario_id=u.id WHERE u.activo=1 AND (u.rol='administrador' OR r.rol='moderador') ORDER BY u.id LIMIT 100")->fetchAll();
}
$pageTitle = 'Equipo y permisos';
$pageCss = ['CSS/social.css','CSS/account.css'];
$navActivo = 'moderacion';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container account-roles-page"><a class="account-back" href="<?= e(rutaAplicacion('moderacion.php')) ?>">← Moderación</a><header><span class="account-data-step">EQUIPO MUSICMAD</span><h1>Equipo y permisos</h1><p>Moderación revisa reportes y retira contenido. Administración también gestiona permisos. Cada cambio cierra los accesos de la cuenta afectada.</p></header><?php if ($message): ?><p class="alert alert-<?= $ok ? 'success' : 'error' ?>" role="status"><?= e($message) ?></p><?php endif; ?>
<?php if (!$ready): ?><p>Importa la migración017 para gestionar los permisos.</p><?php else: ?><section class="card"><h2>Equipo actual</h2><ul class="account-role-list"><?php foreach ($team as $member): ?><li><span><strong><?= e($member['nombre'] . ' ' . $member['apellido']) ?></strong><small>Cuenta #<?= (int)$member['id'] ?></small></span><b><?= $member['rol'] === 'administrador' ? 'Administración' : 'Moderación' ?></b></li><?php endforeach; ?></ul></section>
<section class="card"><h2>Cambiar un permiso</h2><form method="GET" action="<?= e(rutaAplicacion('permisos.php', 'GET')) ?>" class="account-confirm-form"><label for="equipo-q">Buscar cuenta por nombre o número</label><input id="equipo-q" name="q" value="<?= e($q) ?>" maxlength="80"><button type="submit" class="btn btn-ghost">Buscar cuenta</button></form>
<?php if ($users): ?><form method="POST" action="<?= e(rutaAplicacion('permisos.php', 'POST')) ?>" class="account-confirm-form"><?= csrfCampo() ?><label for="equipo-usuario">Cuenta</label><select id="equipo-usuario" name="usuario" required><?php foreach ($users as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e($member['nombre'] . ' ' . $member['apellido']) ?> · #<?= (int)$member['id'] ?></option><?php endforeach; ?></select><label for="equipo-rol">Permiso</label><select id="equipo-rol" name="rol"><option value="usuario">Usuario</option><option value="moderador">Moderador</option><option value="administrador">Administrador</option></select><?php camposConfirmacionCuenta($uid, 'permisos'); ?><button type="submit" class="btn btn-primary">Confirmar permiso</button></form><?php else: ?><p>No se encontraron cuentas con esa búsqueda.</p><?php endif; ?></section><?php endif; ?></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
