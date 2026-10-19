<?php

require_once __DIR__ . '/email_account.php';
require_once __DIR__ . '/email_policy.php';

function verificacionObligatoriaDisponible(): bool
{
    static $ready;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $st->execute(['musicmad_email_enrollment']);
    return $ready = (int) $st->fetchColumn() === 1 && correoCuentaDisponible();
}

/** Called only on account creation or authenticated login, never by guest GET. */
function iniciarPlazoVerificacion(int $uid): void
{
    if (!verificacionObligatoriaDisponible() || correoCuentaVerificado($uid)) {
        return;
    }
    $db = getDB();
    $st = $db->prepare('SELECT email FROM usuarios WHERE id=? AND activo=1');
    $st->execute([$uid]);
    $email = $st->fetchColumn();
    if (!is_string($email)) {
        return;
    }
    // A changed email never inherits verification or the previous deadline.
    $db->prepare('INSERT INTO musicmad_email_enrollment(usuario_id,email_hash,iniciada_en,vence_en)
        VALUES(?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))
        ON DUPLICATE KEY UPDATE iniciada_en=IF(email_hash<>VALUES(email_hash),VALUES(iniciada_en),iniciada_en),
        vence_en=IF(email_hash<>VALUES(email_hash),VALUES(vence_en),vence_en),email_hash=VALUES(email_hash)')
        ->execute([$uid, hashCorreoCuenta($email)]);
}

function destinoCorreoPendiente(int $uid): ?string
{
    return !verificacionObligatoriaDisponible() || !correoCuentaVerificado($uid) ? 'verificar-correo.php' : null;
}

function marcarCorreoGoogleVerificado(int $uid, string $email, bool $verified): void
{
    if (!$verified || !filter_var($email, FILTER_VALIDATE_EMAIL) || !verificacionObligatoriaDisponible()) {
        throw new InvalidArgumentException('Google no confirmó este correo.');
    }
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT email FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$uid]);
        $current = $st->fetchColumn();
        if (!is_string($current) || !hash_equals(hashCorreoCuenta($email), hashCorreoCuenta($current))) {
            throw new InvalidArgumentException('El correo de Google no coincide con la cuenta.');
        }
        $db->prepare('INSERT INTO musicmad_email_verified(usuario_id,email_hash,verificado_en) VALUES(?,?,UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE email_hash=VALUES(email_hash),verificado_en=VALUES(verificado_en)')
            ->execute([$uid, hashCorreoCuenta($current)]);
        $db->prepare("UPDATE email_tokens SET usado_en=UTC_TIMESTAMP() WHERE usuario_id=? AND tipo='verificar' AND usado_en IS NULL")
            ->execute([$uid]);
        if ($owns) {
            $db->commit();
        }
    } catch (Throwable $error) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

/** All unsafe web requests pass this guard, before endpoint-specific handlers. */
function exigirCorreoEnEscrituraWeb(): void
{
    if (PHP_SAPI === 'cli' || empty($_SESSION['usuario_id'])
        || in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowed = ['idioma.php', 'login.php', 'registro.php', 'logout.php', 'dos-pasos.php', 'google-inicio.php',
        'confirmar-correo.php', 'verificar-correo.php', 'recuperar.php', 'restablecer.php',
        'cambiar-REPLACE_WITH_LOCAL_VALUE.php', 'eliminar-cuenta.php', 'exportar-datos.php'];
    if (in_array($page, $allowed, true)) {
        return;
    }
    if ($page === 'configuracion.php' && in_array(
        $_POST['accion'] ?? '',
        ['verificar_correo', 'revocar_dispositivo', 'revocar_otros'],
        true
    )) {
        return;
    }
    if (!estaAutenticado() || !destinoCorreoPendiente((int) $_SESSION['usuario_id'])) {
        return;
    }
    // Plain HTML submissions return to a usable screen; fetch endpoints get JSON.
    $json = isset($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_SERVER['HTTP_X_MUSICMAD_CSRF'])
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if (!$json) {
        header('Location: verificar-correo.php', true, 303);
        exit;
    }
    http_response_code(403);
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'email_verification_required',
        'mensaje' => 'Confirma tu correo para realizar esta acción.',
        'message' => 'Confirma tu correo para realizar esta acción.',
        'redirect' => 'verificar-correo.php'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** CLI maintenance, same removal pipeline as account deletion, no media traversal. */
function vencerCuentasSinVerificar(int $maximum = 2): int
{
    if (PHP_SAPI !== 'cli' || !verificacionObligatoriaDisponible() || !controlesCuentaDisponibles()) {
        return 0;
    }
    $db = getDB();
    $ids = $db->query("SELECT e.usuario_id FROM musicmad_email_enrollment e
        JOIN usuarios u ON u.id=e.usuario_id AND u.activo=1
        LEFT JOIN musicmad_email_verified v ON v.usuario_id=e.usuario_id AND v.email_hash=e.email_hash
        WHERE e.vence_en<=UTC_TIMESTAMP() AND v.usuario_id IS NULL
        AND BINARY e.email_hash=BINARY SHA2(LOWER(TRIM(u.email)),256)
        AND (u.rol<>'administrador' OR (SELECT COUNT(*) FROM usuarios a WHERE a.activo=1 AND a.rol='administrador')>1)
        ORDER BY e.vence_en LIMIT " . max(1, min(10, $maximum)))
        ->fetchAll(PDO::FETCH_COLUMN);
    $done = 0;
    foreach ($ids as $uid) {
        $db->beginTransaction();
        try {
            $db->query('SELECT id FROM musicmad_security_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            $st = $db->prepare('SELECT id,rol,email FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
            $st->execute([$uid]);
            $user = $st->fetch();
            $st = $db->prepare('SELECT email_hash FROM musicmad_email_enrollment WHERE usuario_id=? AND vence_en<=UTC_TIMESTAMP() FOR UPDATE');
            $st->execute([$uid]);
            $hash = $st->fetchColumn();
            if (!$user || !$hash || !hash_equals($hash, hashCorreoCuenta($user['email'])) || correoCuentaVerificado((int) $uid)) {
                $db->commit();
                continue;
            }
            // Preserve the last administrator. Their writing access stays blocked.
            if ($user['rol'] === 'administrador'
                && (int) $db->query("SELECT COUNT(*) FROM usuarios WHERE activo=1 AND rol='administrador'")->fetchColumn() <= 1) {
                $db->commit();
                continue;
            }
            $st = $db->prepare('SELECT banda_id FROM banda_miembros WHERE usuario_id=? UNION SELECT id FROM bandas WHERE creador_id=? ORDER BY banda_id');
            $st->execute([$uid, $uid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $band) {
                $db->prepare('SELECT id FROM bandas WHERE id=? FOR UPDATE')->execute([$band]);
                $db->prepare('DELETE FROM banda_miembros WHERE banda_id=? AND usuario_id=?')->execute([$band, $uid]);
                mantenerAdministracionBanda($db, (int) $band, (int) $uid);
            }
            $db->prepare("UPDATE musicmad_band_invites SET estado='cancelada',respondida_en=UTC_TIMESTAMP()
                WHERE estado='pendiente' AND (invitado_id=? OR invitado_por=?)")->execute([$uid, $uid]);
            $db->prepare("INSERT INTO musicmad_account_deletions(usuario_id,solicitada_en,ejecutar_en)
                VALUES(?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE ejecutar_en=UTC_TIMESTAMP(),estado='pendiente'")
                ->execute([$uid]);
            $db->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$uid]);
            revocarAccesosCuenta((int) $uid);
            $db->commit();
            $done++;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
    return $done;
}
