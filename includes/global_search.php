<?php

require_once __DIR__ . '/linked_text.php';
function busquedaGlobalDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare("SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema=DATABASE() AND index_name IN ('ft_mm_identidad','ft_mm_ficha','ft_mm_publicacion','ft_mm_banda')");
    $st->execute();
    return $ready = (int)$st->fetchColumn() === 4 && social3Disponible();
}
function consultaBusquedaGlobal(string $text): array
{
    $text = mb_substr(trim($text), 0, 80);
    preg_match_all('/[\p{L}\p{N}_]{2,40}/u', $text, $matches);
    $words = array_slice(array_unique(array_map('mb_strtolower', $matches[0] ?? [])), 0, 5);
    if (count($words) > 1) {
        $words = array_values(array_diff($words, ['about','an','are','as','at','be','by','com','de','en','for','from','how','in','is','it','la','of','on','or','that','the','this','to','was','what','when','where','who','will','with','und','www']));
    }
    return [$text,implode(' ', array_map(static fn ($word) => '+' . $word . '*', $words))];
}
function buscarGlobal(int $yo, string $text, int $limit = 6): array
{
    [$query,$boolean] = consultaBusquedaGlobal($text);
    $result = ['musicos' => [],'publicaciones' => [],'bandas' => [],'hashtags' => [],'generos' => []];
    if ($boolean === '') {
        return $result;
    }
    $limit = max(1, min(10, $limit));
    if (!busquedaGlobalDisponible()) {
        throw new RuntimeException('La búsqueda necesita terminar una actualización.');
    }
    $db = getDB();
    $like = '%' . addcslashes($query, '%_\\') . '%';
    $short = mb_strlen(preg_replace('/[^\p{L}\p{N}_]/u', '', $query)) < 3;
    [$policy,$params] = filtroAccesoContenido($yo, 'u.id', 'global_users');
    $match = $short ? 'CONCAT_WS(" ",u.nombre,u.apellido,pm.instrumento,pm.generos,pm.ciudad) LIKE :identity' : '(MATCH(u.nombre,u.apellido) AGAINST(:identity IN BOOLEAN MODE) OR MATCH(pm.instrumento,pm.generos,pm.ciudad) AGAINST(:ficha IN BOOLEAN MODE))';
    $aliasQuery = preg_match('/^@([a-z][a-z0-9_]{1,29})$/D', $query, $aliasMatch) === 1;
    $identityParams = $short ? [':identity' => $like] : [':identity' => $boolean,':ficha' => $boolean];
    if ($aliasQuery) {
        $match = 'pp.slug LIKE :alias';
        $identityParams = [':alias' => addcslashes($aliasMatch[1], '%_\\') . '%'];
    }
    $st = $db->prepare('SELECT u.id,u.nombre,u.apellido,u.avatar,pp.slug,pm.instrumento,pm.ciudad FROM usuarios u LEFT JOIN perfiles_musicales pm ON pm.usuario_id=u.id LEFT JOIN perfiles_publicos pp ON pp.usuario_id=u.id WHERE u.activo=1 AND ' . $policy . ' AND ' . $match . ' ORDER BY u.nombre,u.id LIMIT ' . $limit);
    $st->execute($params + $identityParams);
    foreach ($st->fetchAll() as $r) {
        $result['musicos'][] = ['id' => (int)$r['id'],'titulo' => $r['nombre'] . ' ' . $r['apellido'],'imagen' => avatarBusqueda((int)$r['id'], (string)$r['avatar']),'detalle' => implode(' · ', array_filter([empty($r['slug']) ? '' : '@' . $r['slug'],$r['instrumento'],$r['ciudad']])),'url' => 'musico.php?id=' . (int)$r['id']];
    }
    [$policy,$params] = filtroAccesoContenido($yo, 'p.usuario_id', 'global_posts');
    $match = $short ? 'CONCAT_WS(" ",p.titulo,p.texto,p.genero) LIKE :post' : '(MATCH(p.titulo,p.texto) AGAINST(:post IN BOOLEAN MODE) OR p.genero LIKE :genre)';
    $st = $db->prepare('SELECT p.id,p.titulo,p.genero,p.tipo FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 WHERE ' . $policy . ' AND ' . $match . ' ORDER BY p.id DESC LIMIT ' . $limit);
    $st->execute($params + ($short ? [':post' => $like] : [':post' => $boolean,':genre' => $like]));
    foreach ($st->fetchAll() as $r) {
        $result['publicaciones'][] = ['id' => (int)$r['id'],'titulo' => $r['titulo'],'detalle' => implode(' · ', array_filter([$r['tipo'],$r['genero']])),'url' => 'publicacion.php?id=' . (int)$r['id']];
    }
    [$policy,$params] = filtroAccesoContenido($yo, 'b.creador_id', 'global_bands');
    $match = $short ? 'CONCAT_WS(" ",b.nombre,b.descripcion,b.genero,b.ciudad) LIKE :band' : 'MATCH(b.nombre,b.descripcion,b.genero,b.ciudad) AGAINST(:band IN BOOLEAN MODE)';
    $bandArchive = bandasWorkspaceDisponible() ? ' AND NOT EXISTS(SELECT 1 FROM musicmad_band_settings bs WHERE bs.banda_id=b.id AND bs.archivada_en IS NOT NULL)' : '';
    $st = $db->prepare('SELECT b.id,b.nombre,b.genero,b.ciudad FROM bandas b JOIN usuarios u ON u.id=b.creador_id AND u.activo=1 WHERE ' . $policy . ' AND ' . $match . $bandArchive . ' ORDER BY b.nombre,b.id LIMIT ' . $limit);
    $st->execute($params + [':band' => $short ? $like : $boolean]);
    foreach ($st->fetchAll() as $r) {
        $result['bandas'][] = ['id' => (int)$r['id'],'titulo' => $r['nombre'],'detalle' => implode(' · ', array_filter([$r['genero'],$r['ciudad']])),'url' => 'banda.php?id=' . (int)$r['id']];
    }
    [$policy,$params] = filtroAccesoContenido($yo, 'p.usuario_id', 'global_tags');
    $tag = mb_strtolower(ltrim($query, '#'));
    $st = $db->prepare('SELECT h.id,h.etiqueta,COUNT(DISTINCT p.id) AS publicaciones FROM hashtags h JOIN publicacion_hashtags ph ON ph.hashtag_id=h.id JOIN publicaciones p ON p.id=ph.publicacion_id JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 WHERE h.etiqueta LIKE :tag AND ' . $policy . ' GROUP BY h.id,h.etiqueta ORDER BY publicaciones DESC,h.etiqueta LIMIT ' . $limit);
    $st->execute($params + [':tag' => addcslashes($tag, '%_\\') . '%']);
    foreach ($st->fetchAll() as $r) {
        $result['hashtags'][] = ['id' => (int)$r['id'],'titulo' => '#' . $r['etiqueta'],'detalle' => $r['publicaciones'] . ' publicaciones','url' => 'buscar.php?tag=' . rawurlencode($r['etiqueta'])];
    }
    foreach (GENEROS as $genre) {
        if (mb_stripos($genre, ltrim($query, '#')) !== false) {
            $result['generos'][] = ['id' => 0,'titulo' => $genre,'detalle' => 'Explorar género','url' => 'feed.php?genero=' . rawurlencode($genre)];
        }
    }
    $result['generos'] = array_slice($result['generos'], 0, $limit);
    return $result;
}
function etiquetasEnTexto(string $text): array
{
    $tags = [];
    foreach (tokensSocialesTexto($text) as $token) {
        if (($token[1][0] ?? '') !== '') {
            $tags[] = mb_strtolower($token[1][0]);
        }
    }
    return array_slice(array_unique($tags), 0, 8);
}
function indexarEtiquetasPublicacion(int $id, string $text): void
{
    $tags = etiquetasEnTexto($text);
    if (!$tags || !social3Disponible()) {
        return;
    }
    $db = getDB();
    foreach ($tags as $tag) {
        $db->prepare('INSERT IGNORE INTO hashtags(etiqueta) VALUES(?)')->execute([$tag]);
        $db->prepare('INSERT IGNORE INTO publicacion_hashtags(publicacion_id,hashtag_id) SELECT ?,id FROM hashtags WHERE etiqueta=?')->execute([$id,$tag]);
    }
}
function publicacionesEtiqueta(int $yo, string $tag, int $before = 0): array
{
    if (!preg_match('/^[\p{L}\p{N}_]{2,40}$/uD', $tag)) {
        return [];
    }
    [$policy,$params] = filtroAccesoContenido($yo, 'p.usuario_id', 'tag_posts');
    $sql = 'SELECT p.id,p.titulo,p.texto,p.genero FROM publicaciones p JOIN publicacion_hashtags ph ON ph.publicacion_id=p.id JOIN hashtags h ON h.id=ph.hashtag_id JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 WHERE h.etiqueta=:tag AND ' . $policy;
    $params[':tag'] = mb_strtolower($tag);
    if ($before) {
        $sql .= ' AND p.id<:before';
        $params[':before'] = $before;
    }
    $st = getDB()->prepare($sql . ' ORDER BY p.id DESC LIMIT 21');
    $st->execute($params);
    return $st->fetchAll();
}


function avatarBusqueda(int $uid, string $avatar): string
{
    if (in_array($avatar, avataresDisponibles(), true)) {
        return $avatar;
    }
    if (preg_match('#^uploads/avatars/' . preg_quote((string)$uid, '#') . '_[a-f0-9]{24}\.jpg$#D', $avatar)) {
        $file = musicmadMediaDirectoryForFile('avatars', basename($avatar)) . '/' . basename($avatar);
        if (is_file($file) && !is_link($file)) {
            return musicmadPublicImageUrl($avatar);
        }
    }
    return AVATAR_DEFECTO;
}
