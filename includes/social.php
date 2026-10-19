<?php

// ============================================================
//  MusicMad – Funciones de la red social
//  includes/social.php
//  (requiere includes/auth.php ya cargado)
// ============================================================

require_once __DIR__ . '/social2.php';

const TIPOS_PUBLICACION = [
    'cancion' => ['🎵', 'Canción'],
    'cover' => ['🎤', 'Cover'],
    'demo' => ['🎧', 'Demo / beat'],
    'letra' => ['📝', 'Letra / composición'],
    'texto' => ['💬', 'Publicación'],
    'partitura' => ['🎼', 'Partitura'],
    'video' => ['🎬', 'Video'],
];

const GENEROS = [
    'Rock', 'Pop', 'Jazz', 'Blues', 'Clásica', 'Folk', 'Regional mexicano',
    'Hip-hop / Rap', 'Electrónica', 'Metal', 'Reggae', 'Salsa / Latina', 'Otro',
];

// ── Utilidades ──────────────────────────────────────────────

/** Tiempo relativo en español ("hace 5 min"). */
function hace(string $fecha): string
{
    static $databaseZone = null;
    if ($databaseZone === null) {
        $clock = getDB()->prepare('SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW())');
        $clock->execute();
        $offset = (int) $clock->fetchColumn();
        $databaseZone = new DateTimeZone(sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60)));
    }
    $moment = new DateTimeImmutable($fecha, $databaseZone);
    $seg = max(0, time() - $moment->getTimestamp());
    return match (true) {
        $seg < 60 => 'hace un momento',
        $seg < 3600 => 'hace ' . intdiv($seg, 60) . ' min',
        $seg < 86400 => 'hace ' . intdiv($seg, 3600) . ' h',
        $seg < 604800 => 'hace ' . intdiv($seg, 86400) . ' d',
        default => date('d/m/Y', strtotime($fecha)),
    };
}

/**
 * Convierte un enlace en algo seguro de mostrar.
 * Solo https; YouTube y Spotify se incrustan, el resto es un enlace.
 */
function infoEnlace(string $enlace): ?array
{
    if ($enlace === '') {
        return null;
    }
    $p = parse_url($enlace);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || preg_match('/[\x00-\x20\x7f]/', $enlace)) {
        return null;
    }
    $host = strtolower(preg_replace('/^www\./', '', $p['host']));
    $path = $p['path'] ?? '';
    parse_str($p['query'] ?? '', $q);

    $yt = null;
    if ($host === 'youtu.be') {
        $yt = ltrim($path, '/');
    } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
        $yt = $q['v'] ?? (preg_match('#^/(shorts|embed)/([^/]+)#', $path, $m) ? $m[2] : null);
    }
    if (is_string($yt) && preg_match('/^[A-Za-z0-9_-]{11}$/', $yt)) {
        return ['modo' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/' . $yt, 'url' => $enlace, 'host' => $host];
    }
    if ($host === 'open.spotify.com' && preg_match('#^/(track|album|playlist|episode)/([A-Za-z0-9]+)#', $path, $m)) {
        return ['modo' => 'spotify', 'src' => 'https://open.spotify.com/embed/' . $m[1] . '/' . $m[2], 'url' => $enlace, 'host' => $host];
    }
    return ['modo' => 'enlace', 'src' => '', 'url' => $enlace, 'host' => $host];
}

// ── Publicaciones ───────────────────────────────────────────

function crearPublicacion(int $usuarioId, array $d): array
{
    $band = idCampo($d, 'banda_id');
    if ($band > 0) {
        if (!bandasWorkspaceDisponible()) {
            return ['ok' => false,'mensaje' => 'Actualiza la base de datos para publicar en una banda.'];
        }
        try {
            return transaccionBanda($usuarioId, $band, function (PDO $db) use ($usuarioId, $band, $d) {
                exigirIntegranteBanda($usuarioId, $band);
                unset($d['banda_id']);
                $result = crearPublicacion($usuarioId, $d);
                if ($result['ok']) {
                    $db->prepare('INSERT INTO musicmad_band_posts(publicacion_id,banda_id) VALUES(?,?)')->execute([$result['id'],$band]);
                }
                return $result;
            });
        } catch (OutOfBoundsException $error) {
            return ['ok' => false,'mensaje' => $error->getMessage()];
        }
    }
    $tipo = textoCampo($d, 'tipo') ?: 'texto';
    $titulo = trim(textoCampo($d, 'titulo'));
    $texto = trim(textoCampo($d, 'texto'));
    $enlace = trim(textoCampo($d, 'enlace'));
    $genero = trim(textoCampo($d, 'genero'));

    if (!isset(TIPOS_PUBLICACION[$tipo])) {
        return ['ok' => false, 'mensaje' => 'Tipo de publicación no válido.'];
    }
    if ($titulo === '' || mb_strlen($titulo) > 120) {
        return ['ok' => false, 'mensaje' => 'Escribe un título (máximo 120 caracteres).'];
    }
    if (mb_strlen($texto) > 1000) {
        return ['ok' => false, 'mensaje' => 'El texto es demasiado largo (máximo 1000 caracteres).'];
    }
    if ($enlace !== '' && (mb_strlen($enlace) > 300 || infoEnlace($enlace) === null)) {
        return ['ok' => false, 'mensaje' => 'El enlace debe empezar con https:// y ser válido.'];
    }
    if ($genero !== '' && !in_array($genero, GENEROS, true)) {
        $genero = '';
    }

    getDB()->prepare(
        'INSERT INTO publicaciones (usuario_id, tipo, titulo, texto, enlace, genero)

         VALUES (:u, :t, :ti, :tx, :en, :ge)'
    )->execute([':u' => $usuarioId, ':t' => $tipo, ':ti' => $titulo, ':tx' => $texto, ':en' => $enlace, ':ge' => $genero]);

    $pub = (int)getDB()->lastInsertId();
    solicitarHitosArtista($usuarioId);
    indexarEtiquetasPublicacion($pub, $titulo . ' ' . $texto);
    return ['ok' => true, 'mensaje' => '¡Publicado! Tu comunidad ya lo puede escuchar.', 'id' => $pub];
}

function eliminarPublicacion(int $pubId, int $usuarioId, bool $esAdmin): void
{
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        if (controlesCuentaDisponibles()) {
            $db->query('SELECT id FROM musicmad_security_guard WHERE id=1 FOR UPDATE')->fetchColumn();
        }
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$usuarioId]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        $privileged = $esAdmin && puedeModerar($usuarioId);
        $st = $db->prepare('SELECT usuario_id FROM publicaciones WHERE id=? FOR UPDATE');
        $st->execute([$pubId]);
        $owner = (int)$st->fetchColumn();
        if (!$owner || (!$privileged && $owner !== $usuarioId)) {
            if ($owns) {
                $db->commit();
            }
            return;
        }
        if (controlesCuentaDisponibles()) {
            foreach (['audio' => 'audio','video' => 'videos','partitura' => 'partituras'] as $type => $folder) {
                $db->prepare('INSERT IGNORE INTO musicmad_media_gc(carpeta,archivo) SELECT ?,archivo FROM publicacion_' . $type . ' WHERE publicacion_id=?')->execute([$folder,$pubId]);
            }
        } else {
            borrarAudioDePublicacion($pubId);
            borrarPartituraDePublicacion($pubId);
            borrarVideoDePublicacion($pubId);
        }
        retirarVinculosCreativosPub($pubId);
        $db->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$pubId]);
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

/**
 * Feed.  $modo: 'todo' | 'siguiendo'.  $filtros: genero, tipo, autor (id).
 */
function listarFeed(int $yo, string $modo = 'todo', array $filtros = [], int $limite = 30, ?int $antesId = null): array
{
    if (!empty($filtros['feedback']) && !feedbackDisponible()) {
        return [];
    }
    $where = [];
    if (!empty($filtros['feedback'])) {
        $where[] = 'EXISTS(SELECT 1 FROM musicmad_track_feedback fb WHERE fb.publicacion_id=p.id)';
    }
    $params = [':yo' => $yo, ':yo2' => $yo];
    $social3 = social3Disponible();

    if ($modo === 'siguiendo') {
        $where[] = '(p.usuario_id = :yo3 OR p.usuario_id IN (SELECT seguido_id FROM seguidores WHERE seguidor_id = :yo4))';
        $params[':yo3'] = $yo;
        $params[':yo4'] = $yo;
    }
    if ($modo === 'guardadas') {
        if (!$social3) {
            return [];
        }
        $where[] = 'EXISTS (SELECT 1 FROM publicaciones_guardadas pg WHERE pg.publicacion_id = p.id AND pg.usuario_id = :yo_guardadas)';
        $params[':yo_guardadas'] = $yo;
    }
    if ($social3) {
        [$s3Sql, $s3Params] = filtroSocial3Feed($yo, 'p');
        if ($s3Sql !== '') {
            $where[] = trim($s3Sql);
        }
        $params = array_merge($params, $s3Params);
    }
    if (!empty($filtros['genero']) && in_array($filtros['genero'], GENEROS, true)) {
        $where[] = 'p.genero = :genero';
        $params[':genero'] = $filtros['genero'];
    }
    if (!empty($filtros['instrumento'])) {
        $where[] = 'pm.instrumento LIKE :instrumento';
        $params[':instrumento'] = '%' . addcslashes($filtros['instrumento'], '%_\\') . '%';
    }
    if (!empty($filtros['tipo']) && isset(TIPOS_PUBLICACION[$filtros['tipo']])) {
        $where[] = 'p.tipo = :tipo';
        $params[':tipo'] = $filtros['tipo'];
    }
    if (!empty($filtros['banda'])) {
        $where[] = 'p.usuario_id IN (SELECT usuario_id FROM banda_miembros WHERE banda_id = :banda)';
        $params[':banda'] = (int) $filtros['banda'];
    }
    if (!empty($filtros['grupo'])) {
        if (!bandasWorkspaceDisponible() || !bandaDisponible($yo, (int)$filtros['grupo'])) {
            return [];
        }
        $where[] = 'EXISTS(SELECT 1 FROM musicmad_band_posts bp WHERE bp.publicacion_id=p.id AND bp.banda_id=:grupo)';
        $params[':grupo'] = (int)$filtros['grupo'];
    }
    if (!empty($filtros['autor'])) {
        $where[] = 'p.usuario_id = :autor';
        $params[':autor'] = (int) $filtros['autor'];
    }
    if (isset($filtros['ids']) && is_array($filtros['ids'])) {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $filtros['ids']), static fn ($id) => $id > 0)));
        if (!$wanted) {
            return [];
        }
        $names = [];
        foreach (array_slice($wanted, 0, 100) as $i => $id) {
            $name = ':selected_' . $i;
            $names[] = $name;
            $params[$name] = $id;
        }
        $where[] = 'p.id IN (' . implode(',', $names) . ')';
    }
    if ($antesId !== null && $antesId > 0) {
        $where[] = 'p.id < :antes_id';
        $params[':antes_id'] = $antesId;
    }

    $selectSocial3 = $social3
        ? ', EXISTS(SELECT 1 FROM publicaciones_guardadas pg WHERE pg.publicacion_id=p.id AND pg.usuario_id=:yo_guardado) AS yo_guardada,

              (SELECT pr.reaccion FROM publicacion_reacciones pr WHERE pr.publicacion_id=p.id AND pr.usuario_id=:yo_reaccion LIMIT 1) AS yo_reaccion,

              (SELECT COUNT(*) FROM publicacion_reacciones pr WHERE pr.publicacion_id=p.id AND pr.reaccion=\'fuego\') AS reacciones_fuego,

              (SELECT COUNT(*) FROM publicacion_reacciones pr WHERE pr.publicacion_id=p.id AND pr.reaccion=\'guitarra\') AS reacciones_guitarra,

              (SELECT COUNT(*) FROM publicacion_reacciones pr WHERE pr.publicacion_id=p.id AND pr.reaccion=\'rock\') AS reacciones_rock,

              (SELECT lp.licencia FROM licencias_publicacion lp WHERE lp.publicacion_id=p.id LIMIT 1) AS licencia'
        : '';
    $sql = 'SELECT p.id, p.usuario_id, p.tipo, p.titulo, p.texto, p.enlace, p.genero, p.created_at,

                   u.nombre, u.apellido, u.avatar, u.color_perfil,

                   pm.instrumento, pa.archivo AS audio, pp.archivo AS partitura, pv.archivo AS video, pv.formato AS video_formato,

                   (SELECT COUNT(*) FROM aplausos a WHERE a.publicacion_id = p.id)  AS aplausos,

                   (SELECT COUNT(*) FROM comentarios c WHERE c.publicacion_id = p.id) AS comentarios,

                   (SELECT COUNT(*) FROM aplausos a WHERE a.publicacion_id = p.id AND a.usuario_id = :yo) AS yo_aplaudi,

                   (SELECT COUNT(*) FROM reposts r WHERE r.publicacion_id = p.id) AS reposts,

                   EXISTS(SELECT 1 FROM reposts r WHERE r.publicacion_id = p.id AND r.usuario_id = :yo2) AS yo_reposte'
                   . $selectSocial3 . '

              FROM publicaciones p

              JOIN usuarios u ON u.id = p.usuario_id AND u.activo = 1

              LEFT JOIN perfiles_musicales pm ON pm.usuario_id = p.usuario_id

              LEFT JOIN publicacion_audio pa ON pa.publicacion_id = p.id

              LEFT JOIN publicacion_partitura pp ON pp.publicacion_id = p.id

              LEFT JOIN publicacion_video pv ON pv.publicacion_id = p.id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY p.created_at DESC, p.id DESC LIMIT ' . (int) $limite;

    if ($social3) {
        $params[':yo_guardado'] = $yo;
        $params[':yo_reaccion'] = $yo;
    }
    $st = getDB()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Reposts visibles en el perfil del músico o en el feed de seguidos. */
function listarReposts(int $yo, ?int $perfil = null, int $limite = 30): array
{
    $where = $perfil !== null ? 'r.usuario_id = :actor' : '(r.usuario_id = :actor OR r.usuario_id IN (SELECT seguido_id FROM seguidores WHERE seguidor_id = :seguidores))';
    $params = [':actor' => $perfil ?? $yo];
    if (social3Disponible()) {
        [$originalPolicy, $originalParams] = filtroAccesoContenido($yo, 'p.usuario_id', 'repost_original');
        [$actorPolicy, $actorParams] = filtroAccesoContenido($yo, 'r.usuario_id', 'repost_actor');
        $where .= ' AND (' . $originalPolicy . ') AND (' . $actorPolicy . ')';
        $params += $originalParams + $actorParams;
    }
    if ($perfil === null) {
        $params[':seguidores'] = $yo;
    }
    $st = getDB()->prepare(
        'SELECT p.id, p.usuario_id, p.tipo, p.titulo, p.texto, p.enlace, p.genero, p.created_at,

                u.nombre, u.apellido, u.avatar, u.color_perfil, pm.instrumento,

                pa.archivo AS audio, pp.archivo AS partitura, pv.archivo AS video, pv.formato AS video_formato,

                (SELECT COUNT(*) FROM aplausos a WHERE a.publicacion_id = p.id) AS aplausos,

                (SELECT COUNT(*) FROM comentarios c WHERE c.publicacion_id = p.id) AS comentarios,

                (SELECT COUNT(*) FROM aplausos a WHERE a.publicacion_id = p.id AND a.usuario_id = :yo) AS yo_aplaudi,

                r.usuario_id AS repost_usuario_id, ru.nombre AS repost_nombre, ru.apellido AS repost_apellido,

                r.created_at AS repost_created_at,

                (SELECT COUNT(*) FROM reposts rx WHERE rx.publicacion_id = p.id) AS reposts,

                EXISTS(SELECT 1 FROM reposts ry WHERE ry.publicacion_id = p.id AND ry.usuario_id = :yo2) AS yo_reposte

           FROM reposts r JOIN publicaciones p ON p.id = r.publicacion_id

           JOIN usuarios u ON u.id = p.usuario_id AND u.activo = 1

           JOIN usuarios ru ON ru.id = r.usuario_id AND ru.activo = 1

           LEFT JOIN perfiles_musicales pm ON pm.usuario_id = p.usuario_id

           LEFT JOIN publicacion_audio pa ON pa.publicacion_id = p.id

           LEFT JOIN publicacion_partitura pp ON pp.publicacion_id = p.id

           LEFT JOIN publicacion_video pv ON pv.publicacion_id = p.id

          WHERE ' . $where . ' ORDER BY r.created_at DESC LIMIT ' . (int) $limite
    );
    $params[':yo'] = $yo;
    $params[':yo2'] = $yo;
    $st->execute($params);
    return $st->fetchAll();
}

function alternarRepost(int $usuarioId, int $pubId): void
{
    if (social3Disponible() && !puedeVerPublicacionSocial3($usuarioId, $pubId)) {
        return;
    }
    $db = getDB();
    $st = $db->prepare('DELETE FROM reposts WHERE usuario_id = :u AND publicacion_id = :p');
    $st->execute([':u' => $usuarioId, ':p' => $pubId]);
    if ($st->rowCount() === 0) {
        $db->prepare('INSERT IGNORE INTO reposts (usuario_id, publicacion_id) SELECT :u, id FROM publicaciones WHERE id = :p AND usuario_id <> :propio')
           ->execute([':u' => $usuarioId, ':p' => $pubId, ':propio' => $usuarioId]);
    }
}

// ── Aplausos y comentarios ──────────────────────────────────

function alternarAplauso(int $usuarioId, int $pubId): void
{
    if (social3Disponible() && !puedeVerPublicacionSocial3($usuarioId, $pubId)) {
        return;
    }
    $db = getDB();
    $st = $db->prepare('DELETE FROM aplausos WHERE usuario_id = :u AND publicacion_id = :p');
    $st->execute([':u' => $usuarioId, ':p' => $pubId]);
    if ($st->rowCount() === 0) {
        $ins = $db->prepare('INSERT IGNORE INTO aplausos (usuario_id, publicacion_id) SELECT :u, id FROM publicaciones WHERE id = :p');
        $ins->execute([':u' => $usuarioId, ':p' => $pubId]);
        if ($ins->rowCount() > 0) {
            notificar(duenoPublicacion($pubId), $usuarioId, 'aplauso', $pubId);
            solicitarHitosArtista(duenoPublicacion($pubId));
        }
    }
}

function agregarComentario(int $usuarioId, int $pubId, string $texto): bool
{
    $texto = trim($texto);
    if ($texto === '' || mb_strlen($texto) > 400 || (social3Disponible() && !puedeVerPublicacionSocial3($usuarioId, $pubId))) {
        return false;
    }
    $st = getDB()->prepare(
        'INSERT INTO comentarios (publicacion_id, usuario_id, texto)

         SELECT id, :u, :t FROM publicaciones WHERE id = :p'
    );
    $st->execute([':u' => $usuarioId, ':t' => $texto, ':p' => $pubId]);
    if ($st->rowCount() > 0) {
        notificar(duenoPublicacion($pubId), $usuarioId, 'comentario', $pubId);
        return true;
    }
    return false;
}

/** Últimos comentarios de varias publicaciones, agrupados por id. */
function comentariosDe(array $pubIds, int $porPub = 3, ?int $viewer = null): array
{
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $pubIds), static fn ($id) => $id > 0))), 0, 100);
    if (!$ids) {
        return [];
    }
    $limit = max(1, min(100, $porPub));
    $result = [];
    $params = [];
    $policy = '';
    if (social3Disponible()) {
        [$access,$params] = filtroAccesoContenido($viewer ?? (int)sesion('usuario_id'), 'c.usuario_id', 'initial_comment');
        $policy = ' AND (' . $access . ')';
    }
    $query = getDB()->prepare('SELECT c.id,c.publicacion_id,c.texto,c.created_at,u.id AS uid,u.nombre,u.avatar FROM comentarios c JOIN usuarios u ON u.id=c.usuario_id AND u.activo=1 WHERE c.publicacion_id=:p' . $policy . ' ORDER BY c.id DESC LIMIT :n');
    foreach ($params as $key => $value) {
        $query->bindValue($key, $value, PDO::PARAM_INT);
    }
    foreach ($ids as $id) {
        $query->bindValue(':p', $id, PDO::PARAM_INT);
        $query->bindValue(':n', $limit, PDO::PARAM_INT);
        $query->execute();
        $rows = $query->fetchAll();
        if ($rows) {
            $result[$id] = array_reverse($rows);
        }
    }
    return $result;
}

// ── Seguidores y perfil musical ─────────────────────────────

function alternarSeguir(int $yo, int $otro): void
{
    if ($yo === $otro) {
        return;
    }
    if (social3Disponible() && estadoAccesoPerfilSocial3($yo, $otro) === 'bloqueado') {
        return;
    }
    $db = getDB();
    if (social3Disponible()) {
        $privacy = $db->prepare('SELECT visibilidad FROM perfiles_privacidad WHERE usuario_id=:u LIMIT 1');
        $privacy->execute([':u' => $otro]);
        if ($privacy->fetchColumn() === 'privado') {
            $remove = $db->prepare('DELETE FROM seguidores WHERE seguidor_id=:a AND seguido_id=:b');
            $remove->execute([':a' => $yo, ':b' => $otro]);
            if ($remove->rowCount() > 0) {
                return;
            }
            $db->prepare('INSERT IGNORE INTO solicitudes_seguimiento (solicitante_id,destino_id,estado) SELECT :a,id,\'pendiente\' FROM usuarios WHERE id=:b AND activo=1')
               ->execute([':a' => $yo, ':b' => $otro]);
            return;
        }
    }
    $st = $db->prepare('DELETE FROM seguidores WHERE seguidor_id = :a AND seguido_id = :b');
    $st->execute([':a' => $yo, ':b' => $otro]);
    if ($st->rowCount() === 0) {
        $ins = $db->prepare('INSERT IGNORE INTO seguidores (seguidor_id, seguido_id) SELECT :a, id FROM usuarios WHERE id = :b AND activo = 1');
        $ins->execute([':a' => $yo, ':b' => $otro]);
        if ($ins->rowCount() > 0) {
            notificar($otro, $yo, 'seguidor');
            solicitarHitosArtista($otro);
        }
    }
}

function estadisticasMusico(int $uid, int $yo): array
{
    $db = getDB();
    $q = fn (string $sql, array $p) => (function () use ($db, $sql, $p) {
        $st = $db->prepare($sql);
        $st->execute($p);
        return (int) $st->fetchColumn();
    })();

    return [
        'seguidores' => $q('SELECT COUNT(*) FROM seguidores WHERE seguido_id = :u', [':u' => $uid]),
        'siguiendo' => $q('SELECT COUNT(*) FROM seguidores WHERE seguidor_id = :u', [':u' => $uid]),
        'publicaciones' => $q('SELECT COUNT(*) FROM publicaciones WHERE usuario_id = :u', [':u' => $uid]),
        'aplausos' => $q('SELECT COUNT(*) FROM aplausos a JOIN publicaciones p ON p.id = a.publicacion_id WHERE p.usuario_id = :u', [':u' => $uid]),
        'lo_sigo' => $yo ? $q('SELECT COUNT(*) FROM seguidores WHERE seguidor_id = :a AND seguido_id = :b', [':a' => $yo, ':b' => $uid]) : 0,
    ];
}

/** Días de los últimos 90 en que el músico publicó algo (gráfica de actividad). */
function actividadMusico(int $uid, int $dias = 91): array
{
    $st = getDB()->prepare(
        'SELECT DATE(created_at) AS d, COUNT(*) AS n FROM publicaciones

          WHERE usuario_id = :u AND created_at >= (CURDATE() - INTERVAL :dias DAY)

          GROUP BY DATE(created_at)'
    );
    $st->bindValue(':u', $uid, PDO::PARAM_INT);
    $st->bindValue(':dias', $dias, PDO::PARAM_INT);
    $st->execute();
    $r = [];
    foreach ($st->fetchAll() as $f) {
        $r[$f['d']] = (int) $f['n'];
    }
    return $r;
}

function obtenerPerfilMusical(int $uid): array
{
    $st = getDB()->prepare('SELECT instrumento, generos, ciudad FROM perfiles_musicales WHERE usuario_id = :u');
    $st->execute([':u' => $uid]);
    return $st->fetch() ?: ['instrumento' => '', 'generos' => '', 'ciudad' => ''];
}

/** Portadas locales permitidas para evitar URLs arbitrarias en perfiles. */
function portadasPerfilDisponibles(): array
{
    return [
        'img/portadas/portada-01.svg' => 'Órbita',
        'img/portadas/portada-02.svg' => 'Atardecer',
        'img/portadas/portada-03.svg' => 'Lado B',
        'img/portadas/portada-04.svg' => 'Prisma',
        'img/portadas/portada-05.svg' => 'Noche',
        'img/portadas/portada-06.svg' => 'Ritmo',
    ];
}

function obtenerPortadaPerfil(int $uid): string
{
    $st = getDB()->prepare('SELECT archivo FROM fondos_perfil WHERE usuario_id = :u LIMIT 1');
    $st->execute([':u' => $uid]);
    $archivo = $st->fetchColumn();
    if (is_string($archivo) && isset(portadasPerfilDisponibles()[$archivo])) {
        return $archivo;
    }
    if (is_string($archivo) && preg_match('/^' . preg_quote((string) $uid, '/') . '_[a-f0-9]{24}\.jpg$/', $archivo)) {
        $base = realpath(musicmadMediaDirectoryForFile('covers', $archivo));
        $path = realpath(musicmadMediaDirectoryForFile('covers', $archivo) . '/' . $archivo);
        if ($base !== false && $path !== false && dirname($path) === $base && is_file($path) && !is_link(musicmadMediaDirectoryForFile('covers', $archivo) . '/' . $archivo)) {
            return 'uploads/covers/' . $archivo;
        }
    }
    return 'img/portadas/portada-01.svg';
}

function guardarPortadaPerfil(int $uid, string $archivo): bool
{
    $base = realpath(musicmadMediaDirectoryForFile('covers', $archivo));
    $path = realpath(musicmadMediaDirectoryForFile('covers', $archivo) . '/' . $archivo);
    $personal = preg_match('/^' . preg_quote((string) $uid, '/') . '_[a-f0-9]{24}\.jpg$/', $archivo) === 1
        && $base !== false && $path !== false && dirname($path) === $base && is_file($path) && !is_link(musicmadMediaDirectoryForFile('covers', $archivo) . '/' . $archivo);
    if (!isset(portadasPerfilDisponibles()[$archivo]) && !$personal) {
        return false;
    }
    getDB()->prepare(
        'INSERT INTO fondos_perfil (usuario_id, archivo) VALUES (:u, :a)

         ON DUPLICATE KEY UPDATE archivo = VALUES(archivo)'
    )->execute([':u' => $uid, ':a' => $archivo]);
    return true;
}

function guardarPerfilMusical(int $uid, array $d): void
{
    if (isset($d['nivel']) && matchingDisponible()) {
        guardarNivelMusico($uid, textoCampo($d, 'nivel'));
    }
    $instr = mb_substr(trim(textoCampo($d, 'instrumento')), 0, 60);
    $ciudad = mb_substr(trim(textoCampo($d, 'ciudad')), 0, 80);
    $generosEntrada = $d['generos'] ?? [];
    $generosEntrada = is_array($generosEntrada) ? array_filter($generosEntrada, 'is_string') : [];
    $gen = array_values(array_intersect($generosEntrada, GENEROS));
    $generos = mb_substr(implode(', ', array_slice($gen, 0, 4)), 0, 120);

    getDB()->prepare(
        'INSERT INTO perfiles_musicales (usuario_id, instrumento, generos, ciudad)

         VALUES (:u, :i, :g, :c)

         ON DUPLICATE KEY UPDATE instrumento = VALUES(instrumento), generos = VALUES(generos), ciudad = VALUES(ciudad)'
    )->execute([':u' => $uid, ':i' => $instr, ':g' => $generos, ':c' => $ciudad]);
}

/** Músicos sugeridos para seguir (los que más publican y aún no sigo). */
function musicosSugeridos(int $yo, int $limite = 5): array
{
    [$policy, $policyParams] = social3Disponible() ? filtroAccesoContenido($yo, 'u.id', 'suggested') : ['1=1', []];
    $profile = obtenerPerfilMusical($yo);
    $genres = array_values(array_intersect(GENEROS, array_map('trim', explode(',', $profile['generos']))));
    $scores = [];
    foreach ($genres as $index => $genre) {
        $key = ':suggested_genre_' . $index;
        $policyParams[$key] = $genre;
        $scores[] = "CASE WHEN FIND_IN_SET($key,REPLACE(COALESCE(pm.generos,''),', ',','))>0 THEN 1 ELSE 0 END";
    }
    $score = $scores ? implode('+', $scores) : '0';
    $st = getDB()->prepare(
        'SELECT u.id, u.nombre, u.apellido, u.avatar, pm.instrumento, (' . $score . ') AS afinidad,

                (SELECT COUNT(*) FROM publicaciones p WHERE p.usuario_id = u.id) AS pubs

           FROM usuarios u

           LEFT JOIN perfiles_musicales pm ON pm.usuario_id = u.id

          WHERE u.activo = 1 AND u.id <> :yo AND (' . $policy . ')

            AND u.id NOT IN (SELECT seguido_id FROM seguidores WHERE seguidor_id = :yo2)

          ORDER BY afinidad DESC, pubs DESC, u.created_at DESC

          LIMIT ' . (int) $limite
    );
    $st->execute([':yo' => $yo, ':yo2' => $yo] + $policyParams);
    return $st->fetchAll();
}

function buscarMusicos(int $yo, string $q, int $pagina = 1): array
{
    $q = mb_substr(trim($q), 0, 80);
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $pagina = max(1, min(10000, $pagina));
    $porPagina = 20;
    $offset = ($pagina - 1) * $porPagina;
    $db = getDB();
    [$policy, $policyParams] = social3Disponible() ? filtroAccesoContenido($yo, 'u.id', 'search') : ['1=1', []];
    $cuenta = $db->prepare('SELECT COUNT(*) FROM usuarios u LEFT JOIN perfiles_musicales pm ON pm.usuario_id=u.id WHERE u.activo=1 AND u.id<>:yo AND CONCAT_WS(" ",u.nombre,u.apellido,pm.instrumento,pm.ciudad,pm.generos) LIKE :q AND (' . $policy . ')');
    $cuenta->execute([':yo' => $yo,':q' => $like] + $policyParams);
    $total = (int)$cuenta->fetchColumn();
    $st = $db->prepare(
        'SELECT u.id,u.nombre,u.apellido,u.avatar,u.bio,pm.instrumento,pm.ciudad,pm.generos,

                EXISTS(SELECT 1 FROM seguidores s WHERE s.seguidor_id=:yo AND s.seguido_id=u.id) AS lo_sigo

           FROM usuarios u LEFT JOIN perfiles_musicales pm ON pm.usuario_id=u.id

          WHERE u.activo=1 AND u.id<>:yo2 AND CONCAT_WS(" ",u.nombre,u.apellido,pm.instrumento,pm.ciudad,pm.generos) LIKE :q AND (' . $policy . ')

          ORDER BY lo_sigo DESC,u.nombre,u.apellido LIMIT ' . $porPagina . ' OFFSET ' . $offset
    );
    $st->execute([':yo' => $yo, ':yo2' => $yo, ':q' => $like] + $policyParams);
    return ['items' => $st->fetchAll(),'total' => $total,'pagina' => $pagina,'por_pagina' => $porPagina];
}

require_once __DIR__ . '/social3.php';


require_once __DIR__ . '/feed_engine.php';


require_once __DIR__ . '/web_push.php';


require_once __DIR__ . '/global_search.php';


require_once __DIR__ . '/band_workspace.php';


require_once __DIR__ . '/musician_matching.php';


require_once __DIR__ . '/scene_map.php';


require_once __DIR__ . '/event_workspace.php';


require_once __DIR__ . '/media_duplicates.php';


require_once __DIR__ . '/account_export.php';
require_once __DIR__ . '/account_removal.php';


require_once __DIR__ . '/artist_portfolio.php';


require_once __DIR__ . '/public_read_limits.php';


require_once __DIR__ . '/listening_rooms.php';


require_once __DIR__ . '/audio_waveform.php';


require_once __DIR__ . '/collab_workspace.php';
require_once __DIR__ . '/clip_stories.php';
require_once __DIR__ . '/track_feedback.php';
require_once __DIR__ . '/playlist_workspace.php';
require_once __DIR__ . '/repost_comments.php';

if (is_file(__DIR__ . '/account_verification.php')) {
    require_once __DIR__ . '/account_verification.php';
}
require_once __DIR__ . '/public_share.php';
