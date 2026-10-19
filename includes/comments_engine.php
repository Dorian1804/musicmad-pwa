<?php

function paginaComentarios(int $uid, int $pubId, int $before = 0, int $parent = 0, int $limit = 20): array
{
    if ($pubId < 1 || !puedeVerPublicacionSocial3($uid, $pubId)) {
        throw new OutOfBoundsException('Publicación no disponible.');
    }
    $db = getDB();
    $limit = max(1, min(30, $limit));
    $params = [':p' => $pubId];
    if ($parent > 0) {
        $parentParams = [':c' => $parent,':pub' => $pubId];
        $parentPolicy = '';
        if (social3Disponible()) {
            [$policy,$access] = filtroAccesoContenido($uid, 'c.usuario_id', 'comment_parent');
            $parentPolicy = ' AND (' . $policy . ')';
            $parentParams += $access;
        }
        $check = $db->prepare('SELECT c.id FROM comentarios c JOIN usuarios u ON u.id=c.usuario_id AND u.activo=1 WHERE c.id=:c AND c.publicacion_id=:pub' . $parentPolicy);
        $check->execute($parentParams);
        if (!$check->fetchColumn()) {
            throw new OutOfBoundsException('Comentario no disponible.');
        }
        $source = 'respuestas_comentarios c';
        $where = 'c.comentario_id=:parent';
        $params = [':parent' => $parent];
    } else {
        $source = 'comentarios c';
        $where = 'c.publicacion_id=:p';
    }
    if ($before > 0) {
        $where .= ' AND c.id<:before';
        $params[':before'] = $before;
    }
    if (social3Disponible()) {
        [$policy,$access] = filtroAccesoContenido($uid, 'c.usuario_id', 'comment_owner');
        $where .= ' AND (' . $policy . ')';
        $params += $access;
    }
    $query = $db->prepare('SELECT c.id,c.texto,c.created_at,u.id AS uid,u.nombre,u.avatar FROM ' . $source . ' JOIN usuarios u ON u.id=c.usuario_id AND u.activo=1 WHERE ' . $where . ' ORDER BY c.id DESC LIMIT ' . ($limit + 1));
    $query->execute($params);
    $rows = $query->fetchAll();
    $more = count($rows) > $limit;
    if ($more) {
        array_pop($rows);
    }
    $cursor = $rows ? (int)end($rows)['id'] : 0;
    foreach ($rows as &$row) {
        $row['fecha'] = hace($row['created_at']);
        $row['avatar'] = musicmadPublicImageUrl((string)$row['avatar']);
    }
    unset($row);
    return ['items' => array_reverse($rows),'before' => $cursor,'hay_mas' => $more];
}
