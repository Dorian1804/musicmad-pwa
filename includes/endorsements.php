<?php

function recomendacionesDisponibles(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?)');
    $st->execute(['musicmad_endorsement_hidden','musicmad_endorsement_receipts']);
    return $ready = (int)$st->fetchColumn() === 2;
}
function accionRecomendacion(int $uid, int $target, string $action, string $text, string $request): void
{
    if (!recomendacionesDisponibles()) {
        throw new RuntimeException('Recomendaciones no instaladas.');
    }
    if ($uid < 1 || $target < 1 || $uid === $target || !in_array($action, ['guardar','retirar','ocultar','mostrar'], true) || !preg_match('/^[a-f0-9]{32}$/D', $request)) {
        throw new InvalidArgumentException('Revisa el formulario de recomendación.');
    }
    $text = trim($text);
    if ($action === 'guardar' && (mb_strlen($text) < 12 || mb_strlen($text) > 300)) {
        throw new InvalidArgumentException('Escribe entre 12 y 300 caracteres.');
    }
    $hash = hash('sha256', json_encode([$action,$target,$action === 'guardar' ? $text : '']));
    limiteChat($uid, 'endorse_change', 10);
    $db = getDB();
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id,activo FROM usuarios WHERE id IN (?,?) ORDER BY id FOR UPDATE');
        $st->execute([$uid,$target]);
        $accounts = array_column($st->fetchAll(), 'activo', 'id');
        if (!isset($accounts[$uid],$accounts[$target]) || !$accounts[$uid]) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        if ($action === 'guardar' && (!$accounts[$target] || !puedeVerPerfilSocial3($uid, $target))) {
            throw new OutOfBoundsException('Este músico no está disponible para tu cuenta.');
        }
        $st = $db->prepare('SELECT payload_hash FROM musicmad_endorsement_receipts WHERE usuario_id=? AND solicitud=?');
        $st->execute([$uid,$request]);
        $old = $st->fetchColumn();
        if ($old !== false) {
            if (!hash_equals($old, $hash)) {
                throw new UnexpectedValueException('Ese envío ya contiene otra recomendación.');
            }
            if ($own) {
                $db->commit();
            }
            return;
        }
        if ($action === 'guardar') {
            $st = $db->prepare('SELECT COUNT(*) FROM recomendaciones_musicos WHERE autor_id=?');
            $st->execute([$uid]);
            $count = (int)$st->fetchColumn();
            $st = $db->prepare('SELECT 1 FROM recomendaciones_musicos WHERE autor_id=? AND musico_id=?');
            $st->execute([$uid,$target]);
            if ($count >= 500 && !$st->fetchColumn()) {
                throw new OverflowException('Puedes conservar hasta 500 recomendaciones.');
            }
            $db->prepare('INSERT INTO recomendaciones_musicos(autor_id,musico_id,texto,created_at) VALUES(?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE texto=VALUES(texto),created_at=UTC_TIMESTAMP()')->execute([$uid,$target,$text]);
        } elseif ($action === 'retirar') {
            $db->prepare('DELETE FROM recomendaciones_musicos WHERE autor_id=? AND musico_id=?')->execute([$uid,$target]);
        } elseif ($action === 'ocultar') {
            $db->prepare('INSERT INTO musicmad_endorsement_hidden(musico_id,autor_id,ocultada_en) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE ocultada_en=VALUES(ocultada_en)')->execute([$uid,$target]);
        } else {
            $db->prepare('DELETE FROM musicmad_endorsement_hidden WHERE musico_id=? AND autor_id=?')->execute([$uid,$target]);
        }
        $db->prepare('INSERT INTO musicmad_endorsement_receipts(usuario_id,solicitud,payload_hash,created_at) VALUES(?,?,?,UTC_TIMESTAMP())')->execute([$uid,$request,$hash]);
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function recomendacionesMusico(int $viewer, int $owner, int $page = 1, bool $hidden = false): array
{
    $st = getDB()->prepare('SELECT COUNT(*) FROM usuarios WHERE id IN (?,?) AND activo=1');
    $st->execute([$viewer,$owner]);
    if ((int)$st->fetchColumn() !== ($viewer === $owner ? 1 : 2)) {
        return [];
    }
    if (!recomendacionesDisponibles() || !puedeVerPerfilSocial3($viewer, $owner) || ($hidden && $viewer !== $owner)) {
        return [];
    }[$access,$params] = filtroAccesoContenido($viewer, 'r.autor_id', 'endorsement_author');
    $params[':owner'] = $owner;
    $st = getDB()->prepare("SELECT r.autor_id,r.texto,r.created_at,CONCAT(u.nombre,' ',u.apellido) AS autor,u.avatar FROM recomendaciones_musicos r JOIN usuarios u ON u.id=r.autor_id AND u.activo=1 LEFT JOIN musicmad_endorsement_hidden h ON h.musico_id=r.musico_id AND h.autor_id=r.autor_id WHERE r.musico_id=:owner AND r.autor_id<>r.musico_id AND h.autor_id IS " . ($hidden ? 'NOT NULL' : 'NULL') . ' AND (' . $access . ') ORDER BY r.created_at DESC,r.autor_id DESC LIMIT 20 OFFSET ' . ((max(1, min(25, $page)) - 1) * 20));
    $st->execute($params);
    return $st->fetchAll();
}
function miRecomendacion(int $uid, int $owner): string
{
    if (!recomendacionesDisponibles() || !puedeVerPerfilSocial3($uid, $owner)) {
        return '';
    }
    $st = getDB()->prepare('SELECT texto FROM recomendaciones_musicos WHERE autor_id=? AND musico_id=?');
    $st->execute([$uid,$owner]);
    return (string)($st->fetchColumn() ?: '');
}
function limpiarRecibosRecomendaciones(): void
{
    if (recomendacionesDisponibles()) {
        getDB()->exec('DELETE FROM musicmad_endorsement_receipts WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY) LIMIT 1000');
    }
}


function recomendacionesEscritas(int $uid, int $page = 1): array
{
    if (!recomendacionesDisponibles()) {
        return [];
    }
    $st = getDB()->prepare("SELECT r.musico_id AS autor_id,r.texto,r.created_at,CONCAT(u.nombre,' ',u.apellido) AS autor,u.avatar,u.activo FROM recomendaciones_musicos r JOIN usuarios u ON u.id=r.musico_id WHERE r.autor_id=? ORDER BY r.created_at DESC,r.musico_id DESC LIMIT 20 OFFSET " . ((max(1, min(25, $page)) - 1) * 20));
    $st->execute([$uid]);
    $list = $st->fetchAll();
    foreach ($list as &$item) {
        if (!$item['activo'] || !puedeVerPerfilSocial3($uid, (int)$item['autor_id'])) {
            $item['autor'] = 'Músico no disponible';
            $item['avatar'] = AVATAR_DEFECTO;
        }
    }
    unset($item);
    return $list;
}
