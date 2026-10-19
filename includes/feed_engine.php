<?php

// Stable snapshots prevent popularity changes and new reposts shifting a cursor.
function normalizarFiltrosFeed(string $mode, array $filters): array
{
    return ['modo' => in_array($mode, ['todo','siguiendo','guardadas'], true) ? $mode : 'todo',
      'genero' => in_array($filters['genero'] ?? '', GENEROS, true) ? $filters['genero'] : '',
      'tipo' => isset(TIPOS_PUBLICACION[$filters['tipo'] ?? '']) ? $filters['tipo'] : '',
      'instrumento' => mb_substr(trim((string)($filters['instrumento'] ?? '')), 0, 60),
      'autor' => max(0, (int)($filters['autor'] ?? 0)),
      'feedback' => ($filters['feedback'] ?? '') === '1'];
}
function hashFiltrosFeed(array $filters): string
{
    return hash('sha256', json_encode($filters, JSON_UNESCAPED_UNICODE));
}
function cursorFeed(string $id, int $offset, int $uid, string $filtersHash): string
{
    return $id . '.' . $offset . '.' . hash_hmac('sha256', $id . '.' . $offset . '.' . $uid . '.' . $filtersHash, prepararClaveLimites());
}
function leerCursorFeed(string $cursor, int $uid, string $filtersHash): array
{
    if (!preg_match('/^([a-f0-9]{32})\.([0-9]{1,4})\.([a-f0-9]{64})$/D', $cursor, $match)) {
        throw new InvalidArgumentException('Cursor inválido. Actualiza la comunidad.');
    }
    $offset = (int)$match[2];
    if ($offset > 1000 || !hash_equals(cursorFeed($match[1], $offset, $uid, $filtersHash), $cursor)) {
        throw new InvalidArgumentException('Esta página no pertenece a tu feed actual.');
    }
    return [$match[1],$offset];
}
function candidatosComunidad(int $uid, array $filters): array
{
    $db = getDB();
    $social3 = social3Disponible();
    $base = [];
    $params = [];
    if ($filters['feedback'] ?? false) {
        if (!feedbackDisponible()) {
            return [];
        }
        $base[] = 'EXISTS(SELECT 1 FROM musicmad_track_feedback fb WHERE fb.publicacion_id=p.id)';
    }
    if ($social3) {
        [$policy,$access] = filtroAccesoContenido($uid, 'p.usuario_id', 'candidate_owner');
        $base[] = '(' . $policy . ')';
        $params += $access;
    }
    if ($filters['genero'] !== '') {
        $base[] = 'p.genero=:gen';
        $params[':gen'] = $filters['genero'];
    }
    if ($filters['tipo'] !== '') {
        $base[] = 'p.tipo=:tipo';
        $params[':tipo'] = $filters['tipo'];
    }
    if ($filters['instrumento'] !== '') {
        $base[] = 'pm.instrumento LIKE :inst';
        $params[':inst'] = '%' . addcslashes($filters['instrumento'], '%_\\') . '%';
    }
    $select = 'SELECT p.id,p.usuario_id,p.genero,p.created_at,%ACTOR% AS actor,%DATE% AS evento,
      (SELECT COUNT(*) FROM aplausos a WHERE a.publicacion_id=p.id) AS aplausos,
      (SELECT COUNT(*) FROM comentarios c WHERE c.publicacion_id=p.id) AS comentarios
      FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 LEFT JOIN perfiles_musicales pm ON pm.usuario_id=p.usuario_id ';
    $originalWhere = $base;
    $originalParams = $params;
    if ($filters['autor']) {
        $originalWhere[] = 'p.usuario_id=:autor';
        $originalParams[':autor'] = $filters['autor'];
    }
    if ($filters['modo'] === 'siguiendo') {
        $originalWhere[] = '(p.usuario_id=:self OR EXISTS(SELECT 1 FROM seguidores f WHERE f.seguidor_id=:following AND f.seguido_id=p.usuario_id))';
        $originalParams += [':self' => $uid,':following' => $uid];
    }
    if ($filters['modo'] === 'guardadas') {
        if (!$social3) {
            return [];
        }
        $originalWhere[] = 'EXISTS(SELECT 1 FROM publicaciones_guardadas g WHERE g.usuario_id=:saved AND g.publicacion_id=p.id)';
        $originalParams[':saved'] = $uid;
    }
    $query = $db->prepare(str_replace(['%ACTOR%','%DATE%'], ['0','p.created_at'], $select) . ($originalWhere ? ' WHERE ' . implode(' AND ', $originalWhere) : '') . ' ORDER BY p.created_at DESC,p.id DESC LIMIT 1000');
    $query->execute($originalParams);
    $events = $query->fetchAll();
    if ($filters['modo'] !== 'guardadas') {
        $repostWhere = $base;
        $repostParams = $params;
        if ($filters['autor']) {
            $repostWhere[] = 'r.usuario_id=:rp_profile';
            $repostParams[':rp_profile'] = $filters['autor'];
        } else {
            $repostWhere[] = '(r.usuario_id=:rp_self OR EXISTS(SELECT 1 FROM seguidores f WHERE f.seguidor_id=:rp_following AND f.seguido_id=r.usuario_id))';
            $repostParams += [':rp_self' => $uid,':rp_following' => $uid];
        }
        if ($social3) {
            [$policy,$access] = filtroAccesoContenido($uid, 'r.usuario_id', 'candidate_actor');
            $repostWhere[] = '(' . $policy . ')';
            $repostParams += $access;
        }
        $query = $db->prepare(str_replace(['%ACTOR%','%DATE%'], ['r.usuario_id','r.created_at'], $select) . ' JOIN reposts r ON r.publicacion_id=p.id JOIN usuarios ru ON ru.id=r.usuario_id AND ru.activo=1 WHERE ' . implode(' AND ', $repostWhere) . ' ORDER BY r.created_at DESC,p.id DESC,r.usuario_id DESC LIMIT 1000');
        $query->execute($repostParams);
        $events = array_merge($events, $query->fetchAll());
    }
    usort($events, static fn ($a, $b) => strcmp($b['evento'], $a['evento']) ?: ((int)$b['id'] <=> (int)$a['id']) ?: ((int)$b['actor'] <=> (int)$a['actor']));
    $unique = [];
    foreach ($events as $event) {
        if (!isset($unique[$event['id']])) {
            $unique[$event['id']] = $event;
        }
    }
    $events = array_values($unique);
    if ($filters['modo'] === 'todo' && !$filters['autor']) {
        $st = $db->prepare('SELECT seguido_id FROM seguidores WHERE seguidor_id=?');
        $st->execute([$uid]);
        $following = array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
        $profile = obtenerPerfilMusical($uid);
        $genres = array_filter(array_map('trim', explode(',', $profile['generos'] ?? '')));
        $clock = $db->prepare('SELECT NOW()');
        $clock->execute();
        $now = strtotime($clock->fetchColumn());
        foreach ($events as &$event) {
            $hours = max(0, ($now - strtotime($event['evento'])) / 3600);
            $event['score'] = ($event['usuario_id'] == $uid ? 6 : 0) + (isset($following[(int)$event['usuario_id']]) ? 3 : 0) + (in_array($event['genero'], $genres, true) ? 3 : 0) + log(1 + (int)$event['aplausos']) * .8 + log(1 + (int)$event['comentarios']) * .5 + 8 * exp(-$hours / 36);
        }
        unset($event);
        usort($events, static fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($b['evento'], $a['evento']) ?: ((int)$b['id'] <=> (int)$a['id']));
    }
    return array_map(static fn ($event) => [(int)$event['id'],(int)$event['actor']], array_slice($events, 0, 1000));
}
function paginaComunidad(int $uid, string $mode, array $filters = [], string $cursor = '', int $limit = 10): array
{
    $filters = normalizarFiltrosFeed($mode, $filters);
    $filtersHash = hashFiltrosFeed($filters);
    $db = getDB();
    $limit = max(1, min(30, $limit));
    try {
        $check = $db->prepare('SELECT id FROM musicmad_feed_snapshots LIMIT 0');
        $check->execute();
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') {
            throw $error;
        }
        return ['publicaciones' => listarFeed($uid, $filters['modo'], $filters, 30),'cursor' => '','hay_mas' => false,'actualizacion_pendiente' => true];
    }
    if ($cursor === '') {
        $db->prepare('DELETE FROM musicmad_feed_snapshots WHERE usuario_id=? AND expira_en<=UTC_TIMESTAMP()')->execute([$uid]);
        $st = $db->prepare('SELECT COUNT(*) FROM musicmad_feed_snapshots WHERE usuario_id=?');
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() >= 20) {
            $db->prepare('DELETE FROM musicmad_feed_snapshots WHERE usuario_id=? ORDER BY expira_en ASC LIMIT 1')->execute([$uid]);
        }
        $events = candidatosComunidad($uid, $filters);
        $id = bin2hex(random_bytes(16));
        $offset = 0;
        $db->prepare('INSERT INTO musicmad_feed_snapshots(id,usuario_id,filtros_hash,contenido,expira_en) VALUES(:id,:u,:f,:c,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))')
            ->execute([':id' => $id,':u' => $uid,':f' => $filtersHash,':c' => json_encode($events)]);
    } else {
        [$id,$offset] = leerCursorFeed($cursor, $uid, $filtersHash);
        $st = $db->prepare('SELECT contenido FROM musicmad_feed_snapshots WHERE id=:id AND usuario_id=:u AND filtros_hash=:f AND expira_en>UTC_TIMESTAMP()');
        $st->execute([':id' => $id,':u' => $uid,':f' => $filtersHash]);
        $raw = $st->fetchColumn();
        if (!is_string($raw)) {
            throw new OutOfBoundsException('Tu feed se actualizó. Vuelve al inicio para descubrir publicaciones nuevas.');
        }
        $events = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
    $posts = [];
    while ($offset < count($events) && count($posts) < $limit) {
        $chunk = array_slice($events, $offset, $limit - count($posts));
        $ids = array_column($chunk, 0);
        $liveFilters = $filters;
        $liveFilters['ids'] = $ids;
        unset($liveFilters['autor']);
        $hydrated = listarFeed($uid, $filters['modo'] === 'siguiendo' ? 'todo' : $filters['modo'], $liveFilters, count($chunk));
        $byId = [];
        foreach ($hydrated as $post) {
            $byId[(int)$post['id']] = $post;
        }
        foreach ($chunk as [$pubId,$actor]) {
            $offset++;
            if (!isset($byId[$pubId])) {
                continue;
            }
            $post = $byId[$pubId];
            if (($filters['feedback'] ?? false) && !pistaPideFeedback((int)$pubId)) {
                continue;
            }
            if (!$actor && $filters['modo'] === 'siguiendo' && (int)$post['usuario_id'] !== $uid) {
                $st = $db->prepare('SELECT 1 FROM seguidores WHERE seguidor_id=? AND seguido_id=?');
                $st->execute([$uid,$post['usuario_id']]);
                if (!$st->fetchColumn()) {
                    continue;
                }
            }
            if ($actor) {
                if (!$filters['autor']) {
                    $follow = $db->prepare('SELECT 1 WHERE :self=:actor OR EXISTS(SELECT 1 FROM seguidores WHERE seguidor_id=:viewer AND seguido_id=:followed)');
                    $follow->execute([':self' => $uid,':actor' => $actor,':viewer' => $uid,':followed' => $actor]);
                    if (!$follow->fetchColumn()) {
                        continue;
                    }
                }
                $params = [':actor' => $actor,':p' => $pubId];
                $where = 'r.usuario_id=:actor AND r.publicacion_id=:p';
                if (social3Disponible()) {
                    [$policy,$access] = filtroAccesoContenido($uid, 'r.usuario_id', 'live_actor');
                    $where .= ' AND (' . $policy . ')';
                    $params += $access;
                }
                $st = $db->prepare('SELECT r.created_at,u.nombre,u.apellido FROM reposts r JOIN usuarios u ON u.id=r.usuario_id AND u.activo=1 WHERE ' . $where);
                $st->execute($params);
                $repost = $st->fetch();
                if (!$repost) {
                    continue;
                }
                $post += ['repost_usuario_id' => $actor,'repost_nombre' => $repost['nombre'],'repost_apellido' => $repost['apellido'],'repost_created_at' => $repost['created_at']];
            }
            $posts[] = $post;
        }
    }
    $more = $offset < count($events);
    return ['publicaciones' => $posts,'cursor' => $more ? cursorFeed($id, $offset, $uid, $filtersHash) : '','hay_mas' => $more,'actualizacion_pendiente' => false];
}
