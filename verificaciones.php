<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/account_forms.php';
require_once __DIR__ . '/includes/account_verification.php';
requerirAdmin();
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
$uid = (int)sesion('usuario_id');
$message = '';
$ok = false;
$ready = verificacionArtisticaDisponible();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    try {
        revisarVerificacionArtistica($uid, idCampo($_POST, 'usuario'), textoCampo($_POST, 'decision'), textoCampo($_POST, 'evidencia'), textoCampo($_POST, 'motivo'), textoCampo($_POST, 'criterios') === '1', textoCampo($_POST, 'REPLACE_WITH_LOCAL_VALUE'), textoCampo($_POST, 'codigo'));
        $message = 'Decisión guardada. La insignia respeta la privacidad de la cuenta.';
        $ok = true;
    } catch (LogicException|OutOfBoundsException|OverflowException $e) {
        $message = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Artist verification: ' . get_class($e));
        $message = 'No se pudo guardar la revisión. Inténtalo de nuevo.';
    }
}
$q = mb_substr(trim(textoCampo($_GET, 'q')), 0, 80);
$users = [];
if ($ready && $q !== '') {
    $st = getDB()->prepare("SELECT u.id,u.nombre,u.apellido,p.slug,r.estado,r.revisado_en FROM usuarios u LEFT JOIN perfiles_publicos p ON p.usuario_id=u.id LEFT JOIN musicmad_verification_review r ON r.usuario_id=u.id WHERE u.activo=1 AND u.id<>? AND (CONCAT_WS(' ',u.nombre,u.apellido) LIKE ? OR CAST(u.id AS CHAR)=?) ORDER BY u.nombre,u.id LIMIT 20");
    $st->execute([$uid,'%' . addcslashes($q, '%_\\') . '%',$q]);
    $users = $st->fetchAll();
}
$pageTitle = 'Identidad artística';
$pageCss = ['CSS/social.css','CSS/account.css','CSS/verification.css'];
$navActivo = 'moderacion';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container verification-page"><a class="account-back" href="<?= e(rutaAplicacion('moderacion.php')) ?>">← Moderación</a><header class="verification-heading"><span class="community-kicker">CONFIANZA EN LA ESCENA</span><h1>Identidad artística</h1><p>La insignia indica una revisión manual del músico por el equipo. No certifica ventas, calidad musical ni documentos oficiales.</p></header>
<?php if ($message): ?><p class="alert alert-<?= $ok ? 'success' : 'error' ?>" role="status"><?= e($message) ?></p><?php endif; ?>
<section class="card verification-criteria"><h2>Antes de conceder la insignia</h2><ol><li>La persona confirmó su correo y completó instrumento, biografía y alias.</li><li>Revisaste una referencia pública de su proyecto y contrastaste que corresponde a esta cuenta.</li><li>Obtuviste confirmación directa de la persona por un canal que ya conozcas. No solicites documentos ni contraseñas.</li></ol><p>Un cambio de correo o alias oculta la insignia hasta una nueva revisión. Se puede retirar por petición del titular o incumplimiento. No se concede automáticamente por popularidad.</p></section>
<?php if (!$ready): ?><p class="card">La revisión estará disponible cuando se instale la actualización de cuentas.</p><?php else: ?>
<form method="GET" action="<?= e(rutaAplicacion('verificaciones.php', 'GET')) ?>" class="card account-confirm-form" role="search"><label for="verification-q">Buscar por nombre o número de cuenta</label><input id="verification-q" name="q" value="<?= e($q) ?>" maxlength="80" required><button class="btn btn-primary" type="submit">Buscar cuenta</button></form>
<?php if ($q !== '' && !$users): ?><p class="card">No encontramos esa cuenta activa.</p><?php endif; ?>
<?php if ($users): ?><section class="card"><h2>Revisar una cuenta</h2><form method="POST" action="verificaciones.php?q=<?= rawurlencode($q) ?>" class="account-confirm-form"><?= csrfCampo() ?><label for="verification-user">Cuenta a revisar</label><select id="verification-user" name="usuario"><?php foreach ($users as $user): ?><option value="<?= (int)$user['id'] ?>"><?= e($user['nombre'] . ' ' . $user['apellido']) ?> · #<?= (int)$user['id'] ?><?= $user['estado'] ? ' · ' . e($user['estado']) : '' ?></option><?php endforeach; ?></select><label for="verification-decision">Decisión</label><select id="verification-decision" name="decision"><option value="conceder">Conceder la insignia</option><option value="retirar">Retirar la insignia</option></select><label for="verification-proof">Referencia pública HTTPS (para conceder)</label><input id="verification-proof" type="url" name="evidencia" maxlength="500" placeholder="https://..." autocomplete="off"><label for="verification-reason">Motivo</label><select id="verification-reason" name="motivo"><option value="identidad_artistica">Identidad artística contrastada</option><option value="revision_pendiente">Necesita nueva revisión</option><option value="incumplimiento">Incumplimiento</option><option value="solicitud_titular">Solicitud del titular</option></select><label class="verification-check"><input type="checkbox" name="criterios" value="1">He completado la revisión manual descrita arriba.</label><?php camposConfirmacionCuenta($uid, 'verification'); ?><button class="btn btn-primary" type="submit">Confirmar decisión</button></form></section>
<section class="card"><h2>Perfiles encontrados</h2><ul class="verification-results"><?php foreach ($users as $user): ?><li><a href="<?= e(rutaAplicacion('musico.php?id=' . (int)$user['id'])) ?>"><?= e($user['nombre'] . ' ' . $user['apellido']) ?></a><small><?= $user['slug'] ? '@' . e($user['slug']) : 'Sin alias de portafolio' ?> · <?= e($user['estado'] ?: 'Sin revisión') ?></small></li><?php endforeach; ?></ul></section><?php endif;endif; ?></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
