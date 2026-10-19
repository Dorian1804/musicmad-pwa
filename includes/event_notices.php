<?php

function filtroAvisosEventos(int $yo, string $prefix): array
{
    [$policy,$params] = filtroAccesoContenido($yo, 'ev.creador_id', $prefix);
    $params[':' . $prefix . '_owner'] = $yo;
    $sql = "n.usuario_id=:" . $prefix . "_owner AND n.tipo IN ('evento_recordatorio','evento_cambio','evento_cancelado') AND " . $policy . " AND n.actor_id=ev.creador_id AND EXISTS(SELECT 1 FROM evento_asistentes a WHERE a.evento_id=ev.id AND a.usuario_id=n.usuario_id) AND ((n.tipo='evento_cancelado' AND ev.estado='cancelado') OR (n.tipo<>'evento_cancelado' AND ev.estado='publicado')) AND (n.tipo<>'evento_recordatorio' OR (d.inicio_utc>UTC_TIMESTAMP() AND EXISTS(SELECT 1 FROM musicmad_event_reminders r JOIN musicmad_event_preferences p ON p.evento_id=r.evento_id AND p.usuario_id=r.usuario_id WHERE r.notificacion_id=n.id AND r.revision=d.revision AND p.aviso=1)))";
    return [$sql,$params];
}
function listarAvisosEventos(int $yo): array
{
    if (!eventosWorkspaceDisponible()) {
        return [];
    }[$policy,$params] = filtroAvisosEventos($yo, 'event_notice_list');
    $st = getDB()->prepare('SELECT n.*,u.nombre,u.apellido,u.avatar,ev.titulo AS evento_titulo FROM notificaciones_ext n JOIN eventos_musicales ev ON ev.id=n.referencia_id JOIN usuarios u ON u.id=ev.creador_id AND u.activo=1 LEFT JOIN musicmad_event_details d ON d.evento_id=ev.id WHERE ' . $policy . ' ORDER BY n.id DESC LIMIT 50');
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$row) {
        $row['_origen'] = 'evento';
        $row['leida'] = $row['leida_en'] !== null;
    }
    unset($row);
    return $rows;
}
function contarAvisosEventos(int $yo): int
{
    if (!eventosWorkspaceDisponible()) {
        return 0;
    }[$policy,$params] = filtroAvisosEventos($yo, 'event_notice_count');
    $st = getDB()->prepare('SELECT COUNT(*) FROM notificaciones_ext n JOIN eventos_musicales ev ON ev.id=n.referencia_id JOIN usuarios u ON u.id=ev.creador_id AND u.activo=1 LEFT JOIN musicmad_event_details d ON d.evento_id=ev.id WHERE n.leida_en IS NULL AND ' . $policy);
    $st->execute($params);
    return (int)$st->fetchColumn();
}
function marcarAvisosEventosHasta(int $yo, int $until): void
{
    if ($until > 0 && eventosWorkspaceDisponible()) {
        getDB()->prepare('UPDATE notificaciones_ext SET leida_en=UTC_TIMESTAMP() WHERE usuario_id=? AND id<=? AND leida_en IS NULL')->execute([$yo,$until]);
    }
}
function textoAvisoEvento(array $row): array
{
    $verb = match($row['tipo']) {
        'evento_recordatorio' => 'Se acerca','evento_cancelado' => 'Se canceló',default => 'Se actualizó'
    };
    return [$verb . ' ' . $row['evento_titulo'],'evento.php?id=' . (int)$row['referencia_id']];
}
