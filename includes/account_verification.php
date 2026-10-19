<?php
require_once __DIR__ . '/email_account.php';
function verificacionArtisticaDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $st->execute(['musicmad_verification_review']);
    return $ready = (int)$st->fetchColumn() === 1 && controlesCuentaDisponibles() && correoCuentaDisponible() && social3Disponible();
}
function evidenciaVerificacionSegura(string $url): bool
{
    return strlen($url) <= 500 && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https'
        && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null
        && !preg_match('/[\x00-\x20\x7f]/', $url);
}
/** Explicit state, not toggle. Approval is an administrator's manual review, never an automatic identity claim. */
function revisarVerificacionArtistica(int $admin, int $target, string $action, string $proof, string $reason, bool $criteria, string $REPLACE_WITH_LOCAL_VALUE, string $code): void
{
    if (!verificacionArtisticaDisponible()) {
        throw new RuntimeException('Actualización de verificación no disponible.');
    }
    if ($target < 1 || $target === $admin || !in_array($action, ['conceder','retirar'], true)) {
        throw new InvalidArgumentException('Elige otra cuenta y una decisión válida.');
    }
    $proof = trim($proof);
    if ($action === 'conceder' && (!$criteria || $reason !== 'identidad_artistica' || !evidenciaVerificacionSegura($proof))) {
        throw new InvalidArgumentException('Confirma la revisión manual y una referencia pública HTTPS.');
    }
    if ($action === 'retirar' && !in_array($reason, ['revision_pendiente','incumplimiento','solicitud_titular'], true)) {
        throw new InvalidArgumentException('Elige el motivo de retirada.');
    }
    operacionCuentaConfirmada($admin, $REPLACE_WITH_LOCAL_VALUE, $code, function (PDO $db, array $actor) use ($admin, $target, $action, $proof, $reason) {
        if ($actor['rol'] !== 'administrador') {
            throw new OutOfBoundsException('Solo administración puede revisar la identidad artística.');
        }
        $st = $db->prepare('SELECT id,email,bio FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$target]);
        $user = $st->fetch();
        if (!$user) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        $st = $db->prepare('SELECT slug,verificado FROM perfiles_publicos WHERE usuario_id=? FOR UPDATE');
        $st->execute([$target]);
        $profile = $st->fetch();
        if (!$profile) {
            throw new InvalidArgumentException('La cuenta necesita configurar su alias de portafolio.');
        }
        if ($action === 'conceder') {
            if (!correoCuentaVerificado($target)) {
                throw new InvalidArgumentException('La cuenta debe confirmar su correo antes de recibir la insignia.');
            }
            $st = $db->prepare('SELECT instrumento FROM perfiles_musicales WHERE usuario_id=?');
            $st->execute([$target]);
            if (trim((string)$st->fetchColumn()) === '' || mb_strlen(trim($user['bio'])) < 20) {
                throw new InvalidArgumentException('La cuenta necesita instrumento y una biografía de al menos 20 caracteres.');
            }
        }
        $st = $db->prepare('SELECT estado,slug_revisado,email_hash,evidencia,motivo FROM musicmad_verification_review WHERE usuario_id=? FOR UPDATE');
        $st->execute([$target]);
        $old = $st->fetch();
        $state = $action === 'conceder' ? 'concedida' : 'retirada';
        $emailHash = hashCorreoCuenta($user['email']);
        $proof = $action === 'conceder' ? $proof : '';
        if ($old && $old['estado'] === $state && $old['slug_revisado'] === $profile['slug'] && hash_equals($old['email_hash'], $emailHash) && $old['evidencia'] === $proof && $old['motivo'] === $reason && (int)$profile['verificado'] === ($action === 'conceder' ? 1 : 0)) {
            return;
        }
        $db->prepare('INSERT INTO musicmad_verification_review(usuario_id,estado,slug_revisado,email_hash,evidencia,motivo,revisado_por,revisado_en) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE estado=VALUES(estado),slug_revisado=VALUES(slug_revisado),email_hash=VALUES(email_hash),evidencia=VALUES(evidencia),motivo=VALUES(motivo),revisado_por=VALUES(revisado_por),revisado_en=VALUES(revisado_en)')->execute([$target,$state,$profile['slug'],$emailHash,$proof,$reason,$admin]);
        $db->prepare('UPDATE perfiles_publicos SET verificado=? WHERE usuario_id=?')->execute([$action === 'conceder' ? 1 : 0,$target]);
        auditarCuenta($admin, $action === 'conceder' ? 'verificacion_otorgada' : 'verificacion_retirada', 'cuenta', $target);
    });
}
function cuentaConIdentidadRevisada(int $uid, int $viewer): bool
{
    if (!verificacionArtisticaDisponible()) {
        return false;
    }
    [$policy,$params] = filtroAccesoContenido($viewer, 'u.id', 'verified_profile');
    $st = getDB()->prepare("SELECT u.email,r.email_hash FROM musicmad_verification_review r JOIN usuarios u ON u.id=r.usuario_id AND u.activo=1 JOIN perfiles_publicos p ON p.usuario_id=r.usuario_id AND p.verificado=1 AND BINARY p.slug=BINARY r.slug_revisado WHERE r.usuario_id=:verified_uid AND r.estado='concedida' AND " . $policy);
    $st->execute([':verified_uid' => $uid] + $params);
    $row = $st->fetch();
    return $row && hash_equals($row['email_hash'], hashCorreoCuenta($row['email'])) && correoCuentaVerificado($uid);
}
function renderInsigniaIdentidad(int $uid, int $viewer): void
{
    if (!cuentaConIdentidadRevisada($uid, $viewer)) {
        return;
    }
    ?><span class="identity-badge" title="Identidad artística revisada manualmente por MusicMad"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="m12 1 3 2 3.6.4 1.2 3.4L22 10l-.6 3.6.6 3.4-3 2-1.4 3.2L14 22l-2 1-3-2-3.6-.4-1.2-3.4L2 14l.6-3.6L2 7l3-2 1.4-3.2L10 2Z"/><path d="m7 12 3 3 7-7" fill="none" stroke="var(--card)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="sr-only">Identidad artística revisada manualmente</span></span><?php
}
