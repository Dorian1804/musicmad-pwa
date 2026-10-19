<?php

const REDES_PORTAFOLIO = ['instagram' => 'Instagram','youtube' => 'YouTube','spotify' => 'Spotify','soundcloud' => 'SoundCloud','tiktok' => 'TikTok','sitio' => 'Sitio web'];
function portafolioDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $n = (int)getDB()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('musicmad_epk_options','musicmad_epk_featured')")->fetchColumn();
    if ($n === 0) {
        return $ready = false;
    }
    if ($n !== 2 || !social3Disponible()) {
        throw new RuntimeException('Actualización de portafolio incompleta.');
    }
    return $ready = true;
}
function validarAliasPortafolio(string $slug): string
{
    $slug = strtolower(trim($slug));
    if (!preg_match('/^[a-z][a-z0-9_]{2,29}$/D', $slug) || in_array($slug, ['admin','api','login','musicmad','config','moderacion','support','soporte','seguridad'], true)) {
        throw new InvalidArgumentException('Usa de3 a30 letras, números o guion bajo; empieza con una letra. Ese nombre puede estar reservado.');
    }
    return $slug;
}
function enlacesPortafolio(array $data): array
{
    $out = [];
    foreach (REDES_PORTAFOLIO as $key => $label) {
        $value = trim(textoCampo($data, $key));
        if ($value === '') {
            continue;
        }
        $url = parse_url($value);
        $host = strtolower($url['host'] ?? '');
        if (strlen($value) > 300 || preg_match('/[\x00-\x20\x7f]/', $value) || !filter_var($value, FILTER_VALIDATE_URL) || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || $host === '' || filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost' || !str_contains($host, '.')) {
            throw new InvalidArgumentException('Los enlaces deben ser HTTPS públicos, sin credenciales.');
        }
        $hosts = match($key) {
            'instagram' => ['instagram.com','www.instagram.com'],'youtube' => ['youtube.com','www.youtube.com','youtu.be'],'spotify' => ['open.spotify.com'],'soundcloud' => ['soundcloud.com','www.soundcloud.com'],'tiktok' => ['tiktok.com','www.tiktok.com'],default => null
        };
        if ($hosts !== null && !in_array($host, $hosts, true)) {
            throw new InvalidArgumentException('El enlace de ' . $label . ' debe pertenecer a ese servicio.');
        }
        $out[$key] = $value;
    }
    return $out;
}
function opcionesPortafolio(int $uid): array
{
    if (!portafolioDisponible()) {
        return ['slug' => '','habilitado' => 0,'color' => 'violeta','redes' => [],'destacadas' => []];
    }
    $st = getDB()->prepare('SELECT pp.slug,pp.redes,o.habilitado,o.color FROM perfiles_publicos pp LEFT JOIN musicmad_epk_options o ON o.usuario_id=pp.usuario_id WHERE pp.usuario_id=?');
    $st->execute([$uid]);
    $row = $st->fetch() ?: ['slug' => '','redes' => null,'habilitado' => 0,'color' => 'violeta'];
    $decoded = json_decode((string)$row['redes'], true);
    $row['redes'] = is_array($decoded) ? $decoded : [];
    $st = getDB()->prepare('SELECT publicacion_id FROM musicmad_epk_featured WHERE usuario_id=? ORDER BY orden');
    $st->execute([$uid]);
    $row['destacadas'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $row['habilitado'] = (int)($row['habilitado'] ?? 0);
    $row['color'] = $row['color'] ?? 'violeta';
    $row['incrustable'] = permisoIncrustarPortafolio($uid);
    return $row;
}
function guardarPortafolio(int $uid, array $data): void
{
    if (!portafolioDisponible()) {
        throw new RuntimeException('Importa la actualización de portafolio.');
    }
    $slug = validarAliasPortafolio(textoCampo($data, 'slug'));
    $links = enlacesPortafolio($data);
    $enabled = textoCampo($data, 'habilitado') === '1';
    $color = textoCampo($data, 'color');
    if (!in_array($color, ['violeta','azul','coral','verde'], true)) {
        throw new InvalidArgumentException('Elige un color de la colección.');
    }
    $raw = $data['destacadas'] ?? [];
    if (!is_array($raw) || count($raw) > 5) {
        throw new InvalidArgumentException('Elige hasta cinco pistas.');
    }
    $ids = [];
    foreach ($raw as $value) {
        if ($value === '') {
            continue;
        }
        $id = idCampo(['id' => $value], 'id');
        if (!$id) {
            throw new InvalidArgumentException('Pista no válida.');
        }
        $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));
    $db = getDB();
    limiteChat($uid, 'portafolio', 10);
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$uid]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        $st = $db->prepare('SELECT visibilidad FROM perfiles_privacidad WHERE usuario_id=? FOR UPDATE');
        $st->execute([$uid]);
        $visibility = $st->fetchColumn();
        if ($enabled && $visibility !== false && $visibility !== 'publico') {
            throw new LogicException('Tu perfil debe ser público para publicar el portafolio. Revisa Privacidad.');
        }
        foreach ($ids as $id) {
            $st = $db->prepare('SELECT p.id FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.id=? AND p.usuario_id=? FOR UPDATE');
            $st->execute([$id,$uid]);
            if (!$st->fetchColumn()) {
                throw new OutOfBoundsException('Solo puedes destacar tus propias pistas con audio subido.');
            }
        }
        $st = $db->prepare('SELECT usuario_id FROM perfiles_publicos WHERE usuario_id=? FOR UPDATE');
        $st->execute([$uid]);
        if ($st->fetchColumn()) {
            $db->prepare('UPDATE perfiles_publicos SET slug=?,redes=? WHERE usuario_id=?')->execute([$slug,json_encode($links, JSON_THROW_ON_ERROR),$uid]);
        } else {
            $db->prepare('INSERT INTO perfiles_publicos(usuario_id,slug,redes) VALUES(?,?,?)')->execute([$uid,$slug,json_encode($links, JSON_THROW_ON_ERROR)]);
        }
        $db->prepare('INSERT INTO musicmad_epk_options(usuario_id,habilitado,color,updated_at) VALUES(?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE habilitado=VALUES(habilitado),color=VALUES(color),updated_at=UTC_TIMESTAMP()')->execute([$uid,(int)$enabled,$color]);
        $db->prepare('DELETE FROM musicmad_epk_featured WHERE usuario_id=?')->execute([$uid]);
        foreach ($ids as $i => $id) {
            $db->prepare('INSERT INTO musicmad_epk_featured(usuario_id,publicacion_id,orden) VALUES(?,?,?)')->execute([$uid,$id,$i]);
        }
        if (incrustarPortafolioDisponible()) {
            $db->prepare('INSERT INTO musicmad_epk_embed(usuario_id,permitido) VALUES(?,?) ON DUPLICATE KEY UPDATE permitido=VALUES(permitido)')->execute([$uid,textoCampo($data, 'incrustable') === '1' ? 1 : 0]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($error instanceof PDOException && $error->getCode() === '23000') {
            throw new InvalidArgumentException('Ese nombre de perfil ya está ocupado. Elige otro.');
        }
        throw $error;
    }
}
function portafolioPorAlias(string $slug, int $viewer = 0, bool $preview = false): ?array
{
    if (!portafolioDisponible() || !preg_match('/^[a-z][a-z0-9_]{2,29}$/D', $slug)) {
        return null;
    }
    [$policy,$params] = filtroAccesoContenido($viewer, 'u.id', 'epk_access');
    $sql = 'SELECT u.id,u.nombre,u.apellido,u.avatar,u.bio,pp.slug,pp.redes,o.color,o.habilitado,pm.instrumento,pm.generos,pm.ciudad FROM perfiles_publicos pp JOIN usuarios u ON u.id=pp.usuario_id AND u.activo=1 JOIN musicmad_epk_options o ON o.usuario_id=u.id LEFT JOIN perfiles_musicales pm ON pm.usuario_id=u.id LEFT JOIN perfiles_privacidad pr ON pr.usuario_id=u.id WHERE pp.slug=:slug AND (' . $policy . ') AND ((o.habilitado=1 AND (pr.visibilidad IS NULL OR pr.visibilidad=\'publico\'))';
    if ($preview) {
        $sql .= ' OR u.id=:preview';
        $params[':preview'] = $viewer;
    }
    $sql .= ') LIMIT 1';
    $st = getDB()->prepare($sql);
    $st->execute([':slug' => $slug] + $params);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $row['publico'] = (int)$row['habilitado'] === 1 && obtenerPrivacidadPerfil((int)$row['id'])['visibilidad'] === 'publico';
    $decoded = json_decode((string)$row['redes'], true);
    try {
        $row['redes'] = enlacesPortafolio(is_array($decoded) ? $decoded : []);
    } catch (InvalidArgumentException $error) {
        $row['redes'] = [];
    }
    $row['avatar'] = avatarBusqueda((int)$row['id'], (string)$row['avatar']);
    $row['portada'] = obtenerPortadaPerfil((int)$row['id']);
    $st = getDB()->prepare('SELECT p.id,p.titulo,p.genero,m.duracion FROM musicmad_epk_featured f JOIN publicaciones p ON p.id=f.publicacion_id AND p.usuario_id=f.usuario_id JOIN publicacion_audio a ON a.publicacion_id=p.id LEFT JOIN publicacion_medios m ON m.publicacion_id=p.id WHERE f.usuario_id=? ORDER BY f.orden LIMIT 5');
    $st->execute([(int)$row['id']]);
    $row['pistas'] = $st->fetchAll();
    $row['incrustable'] = $row['publico'] && permisoIncrustarPortafolio((int)$row['id']);
    return $row;
}
function pistaPortafolioPublica(int $id, int $viewer = 0): bool
{
    if (!portafolioDisponible()) {
        return false;
    }[$policy,$params] = filtroAccesoContenido($viewer, 'u.id', 'epk_audio');
    $st = getDB()->prepare("SELECT 1 FROM musicmad_epk_featured f JOIN publicaciones p ON p.id=f.publicacion_id AND p.usuario_id=f.usuario_id JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 JOIN musicmad_epk_options o ON o.usuario_id=u.id AND o.habilitado=1 JOIN publicacion_audio a ON a.publicacion_id=p.id LEFT JOIN perfiles_privacidad pr ON pr.usuario_id=u.id WHERE p.id=:id AND (pr.visibilidad IS NULL OR pr.visibilidad='publico') AND " . $policy . ' LIMIT 1');
    $st->execute([':id' => $id] + $params);
    return (bool)$st->fetchColumn();
}
function urlPortafolio(string $slug): string
{
    return rtrim(APP_URL, '/') . '/@' . validarAliasPortafolio($slug);
}


function incrustarPortafolioDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    return $ready = (bool)getDB()->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='musicmad_epk_embed'")->fetchColumn();
}
function permisoIncrustarPortafolio(int $uid): bool
{
    if (!incrustarPortafolioDisponible()) {
        return false;
    }
    $st = getDB()->prepare('SELECT permitido FROM musicmad_epk_embed WHERE usuario_id=?');
    $st->execute([$uid]);
    return (bool)$st->fetchColumn();
}
function codigoIncrustarPortafolio(string $slug): string
{
    return '<iframe src="' . htmlspecialchars(rtrim(APP_URL, '/') . '/embed.php?usuario=' . validarAliasPortafolio($slug), ENT_QUOTES, 'UTF-8') . '" title="Portafolio de MusicMad" width="100%" height="600" loading="lazy" referrerpolicy="no-referrer"></iframe>';
}
