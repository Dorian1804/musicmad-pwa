<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método no permitido.');
}
requerirSesion();
exigirCsrf();
$yo = (int)sesion('usuario_id');
$id = idCampo($_POST, 'id');
$action = textoCampo($_POST, 'accion');
$target = 'banda.php?id=' . $id;
$flash = ['success','Cambios guardados.'];
$status = 200;
try {
    if (!bandasWorkspaceDisponible()) {
        throw new RuntimeException('Actualiza la base de datos de bandas.');
    }
    limiteChat($yo, 'bandas_accion', 30);
    switch ($action) {
        case 'crear':$db = getDB();
            $db->beginTransaction();
            try {
                $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
                $st->execute([$yo]);
                if (!$st->fetchColumn()) {
                    throw new OutOfBoundsException('Cuenta no disponible.');
                }
                $st = $db->prepare("SELECT COUNT(*) FROM banda_miembros WHERE usuario_id=? AND rol='admin'");
                $st->execute([$yo]);
                if ((int)$st->fetchColumn() >= 20) {
                    throw new OverflowException('Puedes administrar hasta veinte bandas.');
                }
                $r = crearBanda($yo, $_POST);
                if (!$r['ok']) {
                    throw new LogicException($r['mensaje']);
                }
                $id = (int)$r['id'];
                editarBandaWorkspace($yo, $id, $_POST + ['ingreso' => 'abierto','color' => 'violeta']);
                $db->commit();
                $target = 'banda.php?id=' . $id;
                $flash = ['success','Tu banda está lista. Invita a tus músicos.'];
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }break;
        case 'editar':editarBandaWorkspace($yo, $id, $_POST);
            $target .= '&tab=gestion';
            break;
        case 'unirse':if (!unirseBandaWorkspace($yo, $id, textoCampo($_POST, 'instrumento'))) {
            throw new LogicException('Esta banda requiere una invitación o ya eres integrante.');
        }
            $flash = ['success','Ya eres parte de la banda.'];
            break;
        case 'salir':salirBandaWorkspace($yo, $id);
            $target = 'bandas.php';
            $flash = ['success','Saliste de la banda. Sus archivos se conservan.'];
            break;
        case 'archivar':archivarBandaWorkspace($yo, $id, false, esAdmin());
            $target = 'bandas.php';
            $flash = ['success','Banda archivada. Puedes restaurarla desde Bandas.'];
            break;
        case 'restaurar':archivarBandaWorkspace($yo, $id, true, esAdmin());
            $flash = ['success','Banda restaurada.'];
            break;
        case 'invitar':invitarABanda($yo, $id, idCampo($_POST, 'usuario'));
            $target .= '&tab=gestion';
            $flash = ['success','Invitación enviada. Vence en siete días.'];
            break;
        case 'cancelar_invitacion':cancelarInvitacionBanda($yo, $id, idCampo($_POST, 'usuario'));
            $target .= '&tab=gestion';
            break;
        case 'aceptar':case 'rechazar':responderInvitacionBanda($yo, $id, $action === 'aceptar', textoCampo($_POST, 'instrumento'));
            if ($action === 'rechazar') {
                $target = 'bandas.php';
            }
            $flash = ['success',$action === 'aceptar' ? 'Bienvenido a la banda.' : 'Invitación rechazada.'];
            break;
        case 'rol':cambiarRolBanda($yo, $id, idCampo($_POST, 'usuario'), textoCampo($_POST, 'rol'));
            $target .= '&tab=integrantes';
            break;
        case 'expulsar':expulsarMiembroWorkspace($yo, $id, idCampo($_POST, 'usuario'));
            $target .= '&tab=integrantes';
            break;
        case 'instrumento':transaccionBanda($yo, $id, function (PDO $db) use ($yo, $id) {
            exigirIntegranteBanda($yo, $id);
            $instrument = trim(textoCampo($_POST, 'instrumento'));
            if (mb_strlen($instrument) > 60) {
                throw new InvalidArgumentException('Instrumento demasiado largo.');
            }
            $db->prepare('UPDATE banda_miembros SET instrumento=? WHERE banda_id=? AND usuario_id=?')->execute([$instrument,$id,$yo]);
        });
            $target .= '&tab=integrantes';
            break;
        case 'ensayo':guardarEnsayoBanda($yo, $id, $_POST, idCampo($_POST, 'ensayo'));
            $target .= '&tab=agenda';
            $flash = ['success','Ensayo guardado. Los integrantes recibirán un aviso.'];
            break;
        case 'cancelar_ensayo':cancelarEnsayoBanda($yo, $id, idCampo($_POST, 'ensayo'));
            $target .= '&tab=agenda';
            $flash = ['success','Ensayo cancelado.'];
            break;
        case 'rsvp':responderEnsayoBanda($yo, $id, idCampo($_POST, 'ensayo'), textoCampo($_POST, 'estado'), textoCampo($_POST, 'aviso') === '1');
            $target .= '&tab=agenda';
            $flash = ['success','Tu respuesta quedó guardada.'];
            break;
        case 'mensaje':enviarChatBanda($yo, $id, textoCampo($_POST, 'texto'), textoCampo($_POST, 'solicitud'), idCampo($_POST, 'pista'));
            $target = 'banda-chat-vista.php?id=' . $id;
            break;
        case 'leer':marcarChatBanda($yo, $id, idCampo($_POST, 'hasta'));
            $target = 'banda-chat-vista.php?id=' . $id;
            break;
        default:throw new InvalidArgumentException('Acción no válida.');
    }
} catch (OutOfBoundsException $error) {
    $flash = ['error',$error->getMessage()];
    $status = 404;
} catch (LogicException|OverflowException $error) {
    $flash = ['error',$error->getMessage()];
    $status = 422;
} catch (Throwable $error) {
    error_log('Band action: ' . get_class($error));
    $flash = ['error','No pudimos guardar. Conserva tus datos y reintenta.'];
    $status = 503;
}
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $flash[0] !== 'error','redirect' => rutaAplicacion($target),'flash' => $flash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$_SESSION['flash'] = $flash;
header('Location: ' . $target, true, 303);
exit;
