<?php

function estadisticasArtistaDisponibles(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?)');
    $st->execute(['musicmad_listening_receipts','musicmad_confirmed_plays','musicmad_artist_awards','musicmad_artist_refresh']);
    return $ready = (int)$st->fetchColumn() === 4;
}
function medioParaEstadisticas(int $uid, int $pub): array
{
    if (!puedeVerPublicacionSocial3($uid, $pub)) {
        throw new OutOfBoundsException('Pista no disponible.');
    }
    $st = getDB()->prepare("SELECT p.usuario_id,m.sha256,m.duracion,IF(a.archivo IS NULL,'video','audio') AS medio,COALESCE(a.archivo,v.archivo) AS archivo FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 JOIN publicacion_medios m ON m.publicacion_id=p.id LEFT JOIN publicacion_audio a ON a.publicacion_id=p.id LEFT JOIN publicacion_video v ON v.publicacion_id=p.id WHERE p.id=?");
    $st->execute([$pub]);
    $row = $st->fetch();
    if (!$row || !preg_match('/^[a-f0-9]{64}$/D', (string)$row['sha256']) || !is_numeric($row['duracion']) || !is_finite((float)$row['duracion']) || (float)$row['duracion'] < .5 || (float)$row['duracion'] > 21600) {
        throw new OutOfBoundsException('No hay metadatos de escucha disponibles.');
    }
    $file = (string)$row['archivo'];
    $folder = musicmadMediaDirectoryForFile($row['medio'] === 'audio' ? 'audio' : 'videos', $file);
    $pattern = $row['medio'] === 'audio' ? '/^[A-Za-z0-9][A-Za-z0-9_-]{0,70}\.(mp3|m4a|ogg|wav)$/Di' : '/^[A-Za-z0-9][A-Za-z0-9_-]{0,70}\.(mp4|webm)$/Di';
    if (!preg_match($pattern, $file) || is_link($folder) || is_link($folder . '/' . $file) || !($base = realpath($folder)) || !($path = realpath($folder . '/' . $file)) || dirname($path) !== $base || !is_file($path)) {
        throw new OutOfBoundsException('Medio no disponible.');
    }
    return $row;
}
function transaccionEstadistica(int $uid, int $pub, callable $operation): mixed
{
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$uid]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        $st = $db->prepare('SELECT p.id FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 WHERE p.id=? FOR UPDATE');
        $st->execute([$pub]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Pista no disponible.');
        }
        $result = $operation($db, medioParaEstadisticas($uid, $pub));
        if ($owns) {
            $db->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function iniciarEscuchaEstadistica(int $uid, int $pub, string $request): array
{
    if (!estadisticasArtistaDisponibles()) {
        throw new RuntimeException('Estadísticas no instaladas.');
    }
    if (!preg_match('/^[a-f0-9]{32}$/D', $request) || $pub < 1) {
        throw new InvalidArgumentException('Solicitud inválida.');
    }
    limiteChat($uid, 'listen_start', 60);
    return transaccionEstadistica($uid, $pub, function (PDO $db, array $media) use ($uid, $pub, $request) {
        if ((int)$media['usuario_id'] === $uid) {
            return ['ignorada' => true];
        }
        $st = $db->prepare('SELECT publicacion_id,sha256,umbral_ms,confirmada_en,expira_en>UTC_TIMESTAMP(6) AS vigente FROM musicmad_listening_receipts WHERE usuario_id=? AND solicitud=?');
        $st->execute([$uid,$request]);
        $old = $st->fetch();
        if ($old) {
            if ((int)$old['publicacion_id'] !== $pub || !hash_equals($old['sha256'], $media['sha256'])) {
                throw new UnexpectedValueException('Ese envío corresponde a otra escucha.');
            }
            if (!$old['vigente']) {
                throw new DomainException('La escucha venció.');
            }
            return ['solicitud' => $request,'umbral_ms' => (int)$old['umbral_ms'],'confirmada' => $old['confirmada_en'] !== null];
        }
        $st = $db->prepare('SELECT COUNT(*) FROM musicmad_listening_receipts WHERE usuario_id=? AND expira_en>UTC_TIMESTAMP(6) AND confirmada_en IS NULL');
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() >= 30) {
            throw new OverflowException('Demasiadas escuchas pendientes.');
        }
        $threshold = max(250, min(5000, (int)floor((float)$media['duracion'] * 800)));
        $db->prepare('INSERT INTO musicmad_listening_receipts(usuario_id,solicitud,publicacion_id,sha256,umbral_ms,emitida_en,expira_en) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))')->execute([$uid,$request,$pub,$media['sha256'],$threshold]);
        return ['solicitud' => $request,'umbral_ms' => $threshold,'confirmada' => false];
    });
}
function confirmarEscuchaEstadistica(int $uid, int $pub, string $request, int $elapsed): array
{
    if (!estadisticasArtistaDisponibles()) {
        throw new RuntimeException('Estadísticas no instaladas.');
    }
    if (!preg_match('/^[a-f0-9]{32}$/D', $request) || $elapsed < 0 || $elapsed > 21600000) {
        throw new InvalidArgumentException('Solicitud inválida.');
    }
    limiteChat($uid, 'listen_confirm', 60);
    return transaccionEstadistica($uid, $pub, function (PDO $db, array $media) use ($uid, $pub, $request, $elapsed) {
        $st = $db->prepare('SELECT *,expira_en>UTC_TIMESTAMP(6) AS vigente,TIMESTAMPDIFF(MICROSECOND,emitida_en,UTC_TIMESTAMP(6)) DIV 1000 AS transcurrido_ms FROM musicmad_listening_receipts WHERE usuario_id=? AND solicitud=? FOR UPDATE');
        $st->execute([$uid,$request]);
        $row = $st->fetch();
        if (!$row || (int)$row['publicacion_id'] !== $pub || !hash_equals($row['sha256'], $media['sha256'])) {
            throw new OutOfBoundsException('Escucha no disponible.');
        }
        if (!$row['vigente']) {
            throw new DomainException('La escucha venció.');
        }
        if ($row['confirmada_en']) {
            return ['confirmada' => true,'nueva' => false];
        }
        if ($elapsed < (int)$row['umbral_ms']) {
            throw new InvalidArgumentException('Falta tiempo de escucha.');
        }
        $wait = (int)$row['umbral_ms'] - (int)$row['transcurrido_ms'];
        if ($wait > 0) {
            return ['confirmada' => false,'esperar_ms' => $wait];
        }
        $st = $db->prepare('INSERT IGNORE INTO musicmad_confirmed_plays(publicacion_id,usuario_id,fecha,confirmada_en) VALUES(?,?,UTC_DATE(),UTC_TIMESTAMP())');
        $st->execute([$pub,$uid]);
        $new = $st->rowCount() === 1;
        $db->prepare('UPDATE musicmad_listening_receipts SET confirmada_en=UTC_TIMESTAMP(6) WHERE usuario_id=? AND solicitud=?')->execute([$uid,$request]);
        if ($new) {
            logrosArtista((int)$media['usuario_id'], true);
        }
        return ['confirmada' => true,'nueva' => $new];
    });
}
function datosEstadisticasArtista(int $uid, int $days = 30): array
{
    if (!estadisticasArtistaDisponibles()) {
        throw new RuntimeException('Estadísticas no instaladas.');
    }
    $days = in_array($days, [7,30,90], true) ? $days : 30;
    $db = getDB();
    $st = $db->prepare('SELECT 1 FROM usuarios WHERE id=? AND activo=1');
    $st->execute([$uid]);
    if (!$st->fetchColumn()) {
        throw new OutOfBoundsException('Cuenta no disponible.');
    }
    $start = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-' . ($days - 1) . ' days');
    $since = $start->format('Y-m-d');
    $st = $db->prepare('SELECT r.fecha,COUNT(*) AS escuchas FROM musicmad_confirmed_plays r JOIN publicaciones p ON p.id=r.publicacion_id WHERE p.usuario_id=? AND r.fecha>=? GROUP BY r.fecha ORDER BY r.fecha');
    $st->execute([$uid,$since]);
    $counts = array_column($st->fetchAll(), 'escuchas', 'fecha');
    $series = [];
    for ($i = 0;$i < $days;$i++) {
        $day = $start->modify('+' . $i . ' days')->format('Y-m-d');
        $series[] = ['fecha' => $day,'escuchas' => (int)($counts[$day] ?? 0)];
    }
    $query = function (string $sql, array $args) use ($db) {
        $st = $db->prepare($sql);
        $st->execute($args);
        return (int)$st->fetchColumn();
    };
    $summary = ['escuchas' => array_sum(array_column($series, 'escuchas')),'oyentes' => $query('SELECT COUNT(DISTINCT r.usuario_id) FROM musicmad_confirmed_plays r JOIN publicaciones p ON p.id=r.publicacion_id WHERE p.usuario_id=? AND r.fecha>=?', [$uid,$since]),'aplausos' => $query('SELECT COUNT(*) FROM aplausos a JOIN publicaciones p ON p.id=a.publicacion_id JOIN usuarios u ON u.id=a.usuario_id AND u.activo=1 WHERE p.usuario_id=?', [$uid]),'seguidores' => $query('SELECT COUNT(*) FROM seguidores s JOIN usuarios u ON u.id=s.seguidor_id AND u.activo=1 WHERE s.seguido_id=?', [$uid])];
    $st = $db->prepare("SELECT p.id,p.titulo,p.tipo,m.duracion,(SELECT COUNT(*) FROM musicmad_confirmed_plays r WHERE r.publicacion_id=p.id AND r.fecha>=?) AS escuchas,(SELECT COUNT(*) FROM aplausos a JOIN usuarios u ON u.id=a.usuario_id AND u.activo=1 WHERE a.publicacion_id=p.id) AS aplausos FROM publicaciones p JOIN publicacion_medios m ON m.publicacion_id=p.id WHERE p.usuario_id=? AND EXISTS(SELECT 1 FROM publicacion_audio a WHERE a.publicacion_id=p.id UNION ALL SELECT 1 FROM publicacion_video v WHERE v.publicacion_id=p.id) ORDER BY escuchas DESC,p.id DESC LIMIT 30");
    $st->execute([$since,$uid]);
    $tracks = $st->fetchAll();
    return ['dias' => $days,'desde' => $since,'resumen' => $summary,'serie' => $series,'pistas' => $tracks];
}
const INSIGNIAS_MUSICMAD = [
 'primera_pista' => ['Primera pista','Publica tu primer audio.','pistas',1,100],
 'cinco_pistas' => ['Repertorio propio','Publica cinco audios.','pistas',5,200],
 '100_aplausos' => ['Resuena en la escena','Recibe 100 aplausos.','aplausos',100,300],
 '10_seguidores' => ['Tu primer público','Llega a 10 seguidores activos.','seguidores',10,150],
 'primer_aporte' => ['Una capa más','Aporta audio a una colaboración.','aportes',1,150],
 '100_escuchas' => ['En la rotación','Alcanza 100 escuchas confirmadas.','escuchas',100,300]
];
function logrosArtista(int $uid, bool $persist = false): array
{
    if (!estadisticasArtistaDisponibles()) {
        return ['insignias' => [],'puntos' => 0,'nivel' => 1];
    }
    $db = getDB();
    $owns = $persist && !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1' . ($persist ? ' FOR UPDATE' : ''));
        $st->execute([$uid]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        $q = function (string $sql) use ($db, $uid) {
            $st = $db->prepare($sql);
            $st->execute([$uid]);
            return (int)$st->fetchColumn();
        };
        $counts = ['pistas' => $q('SELECT COUNT(*) FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.usuario_id=?'),'aplausos' => $q('SELECT COUNT(*) FROM aplausos a JOIN publicaciones p ON p.id=a.publicacion_id JOIN usuarios u ON u.id=a.usuario_id AND u.activo=1 WHERE p.usuario_id=?'),'seguidores' => $q('SELECT COUNT(*) FROM seguidores s JOIN usuarios u ON u.id=s.seguidor_id AND u.activo=1 WHERE s.seguido_id=?'),'aportes' => estudioCreativoDisponible() ? $q('SELECT COUNT(*) FROM colaboracion_stems s JOIN musicmad_stem_tracks t ON t.stem_id=s.id WHERE s.usuario_id=?') : 0,'escuchas' => $q('SELECT COUNT(*) FROM musicmad_confirmed_plays r JOIN publicaciones p ON p.id=r.publicacion_id WHERE p.usuario_id=?')];
        foreach (INSIGNIAS_MUSICMAD as $code => $award) {
            if ($persist && $counts[$award[2]] >= $award[3]) {
                $db->prepare('INSERT IGNORE INTO musicmad_artist_awards(usuario_id,codigo,obtenida_en) VALUES(?,?,UTC_TIMESTAMP())')->execute([$uid,$code]);
            }
        }
        $st = $db->prepare('SELECT codigo,obtenida_en FROM musicmad_artist_awards WHERE usuario_id=?');
        $st->execute([$uid]);
        $earned = array_column($st->fetchAll(), 'obtenida_en', 'codigo');
        $badges = [];
        $points = 0;
        foreach (INSIGNIAS_MUSICMAD as $code => $award) {
            $date = $earned[$code] ?? null;
            $achieved = $date !== null || $counts[$award[2]] >= $award[3];
            if ($achieved) {
                $points += $award[4];
            }
            $badges[] = ['codigo' => $code,'titulo' => $award[0],'descripcion' => $award[1],'actual' => $counts[$award[2]],'objetivo' => $award[3],'puntos' => $award[4],'obtenida_en' => $date,'alcanzada' => $achieved];
        }
        if ($owns) {
            $db->commit();
        }
        return ['insignias' => $badges,'puntos' => $points,'nivel' => 1 + intdiv($points, 250)];
    } catch (Throwable $e) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function limpiarRecibosEscucha(): void
{
    if (estadisticasArtistaDisponibles()) {
        getDB()->exec('DELETE FROM musicmad_listening_receipts WHERE expira_en<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) LIMIT 1000');
    }
}


function solicitarHitosArtista(int $uid): void
{
    if (estadisticasArtistaDisponibles() && $uid > 0) {
        getDB()->prepare('INSERT INTO musicmad_artist_refresh(usuario_id,solicitada_en) SELECT id,UTC_TIMESTAMP(6) FROM usuarios WHERE id=? AND activo=1 ON DUPLICATE KEY UPDATE solicitada_en=VALUES(solicitada_en)')->execute([$uid]);
    }
}
function procesarHitosArtistas(int $limit = 20, int $seconds = 3): int
{
    if (!estadisticasArtistaDisponibles()) {
        return 0;
    }
    $db = getDB();
    $name = 'mm_artist_' . substr(hash('sha256', DB_NAME), 0, 16);
    $st = $db->prepare('SELECT GET_LOCK(?,0)');
    $st->execute([$name]);
    if ((int)$st->fetchColumn() !== 1) {
        return 0;
    }
    $done = 0;
    $deadline = microtime(true) + max(1, min(10, $seconds));
    try {
        $st = $db->query('SELECT usuario_id,solicitada_en FROM musicmad_artist_refresh ORDER BY solicitada_en LIMIT ' . max(1, min(50, $limit)));
        $rows = $st->fetchAll();
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                logrosArtista((int)$row['usuario_id'], true);
            } catch (OutOfBoundsException $e) {
            }
            $db->prepare('DELETE FROM musicmad_artist_refresh WHERE usuario_id=? AND solicitada_en<=?')->execute([$row['usuario_id'],$row['solicitada_en']]);
            $done++;
        }
        return $done;
    } finally {
        $st = $db->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute([$name]);
    }
}
function insigniasVisiblesArtista(int $viewer, int $owner): array
{
    if (!estadisticasArtistaDisponibles() || !puedeVerPerfilSocial3($viewer, $owner)) {
        return [];
    }
    return array_map(fn ($a) => ['codigo' => $a['codigo'],'titulo' => $a['titulo']], array_values(array_filter(logrosArtista($owner)['insignias'], fn ($a) => $a['alcanzada'])));
}
