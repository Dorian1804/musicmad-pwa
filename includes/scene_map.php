<?php

function mapaDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('musicmad_location_consent','musicmad_map_places')");
    $st->execute();
    $n = (int)$st->fetchColumn();
    if ($n === 1) {
        throw new RuntimeException('Actualización de mapa incompleta.');
    }
    return $ready = $n === 2 && social3Disponible();
}
function coordenadaMapa(mixed $value, float $min, float $max): float
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('Zona no válida.');
    }
    if (is_string($value) && !preg_match('/^-?\d{1,3}(?:\.\d{1,12})?$/D', $value)) {
        throw new InvalidArgumentException('Zona no válida.');
    }
    $number = (float)$value;
    if (!is_finite($number) || $number < $min || $number > $max) {
        throw new InvalidArgumentException('Zona fuera del mapa.');
    }
    return $number;
}
function zonaAproximada(mixed $lat, mixed $lon): array
{
    return ['latitud' => round(coordenadaMapa($lat, -85, 85), 1),'longitud' => round(coordenadaMapa($lon, -180, 180), 1),'precision_km' => 10.0];
}
function autorizarLugarMapa(int $yo, string $type, int $id): void
{
    if ($type === 'musico' && $id === $yo) {
        return;
    }
    if ($type === 'banda') {
        exigirIntegranteBanda($yo, $id, ['admin']);
        return;
    }
    if ($type === 'evento') {
        $st = getDB()->prepare("SELECT 1 FROM eventos_musicales WHERE id=? AND creador_id=? AND estado='publicado'");
        $st->execute([$id,$yo]);
        if ($st->fetchColumn()) {
            return;
        }
    }
    throw new OutOfBoundsException('No puedes cambiar esta ubicación.');
}
function guardarZonaMapa(int $yo, string $type, int $id, array $data, bool $remove = false): void
{
    if (!mapaDisponible()) {
        throw new RuntimeException('Mapa pendiente de actualización.');
    }
    $zone = $remove ? null : zonaAproximada($data['latitud'] ?? null, $data['longitud'] ?? null);
    if (!$remove && textoCampo($data, 'consentimiento') !== '1') {
        throw new InvalidArgumentException('Confirma que quieres mostrar esta zona aproximada.');
    }
    $operation = function (PDO $db) use ($yo, $type, $id, $zone, $remove) {
        autorizarLugarMapa($yo, $type, $id);
        limiteChat($yo, 'mapa_guardar', 15);
        if ($type === 'musico') {
            if ($remove) {
                $db->prepare('UPDATE ubicaciones_publicas SET visible=0 WHERE usuario_id=?')->execute([$yo]);
                $db->prepare('DELETE FROM musicmad_location_consent WHERE usuario_id=?')->execute([$yo]);
            } else {
                $db->prepare('INSERT INTO ubicaciones_publicas(usuario_id,latitud,longitud,precision_km,visible) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE latitud=VALUES(latitud),longitud=VALUES(longitud),precision_km=10.0,visible=1')->execute([$yo,$zone['latitud'],$zone['longitud'],10]);
                $db->prepare('INSERT INTO musicmad_location_consent(usuario_id,consentimiento_en) VALUES(?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE consentimiento_en=UTC_TIMESTAMP()')->execute([$yo]);
            }
        } elseif ($remove) {
            $db->prepare('DELETE FROM musicmad_map_places WHERE tipo=? AND objeto_id=?')->execute([$type,$id]);
        } else {
            $db->prepare('INSERT INTO musicmad_map_places(tipo,objeto_id,latitud,longitud,autorizado_por,consentimiento_en) VALUES(?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE latitud=VALUES(latitud),longitud=VALUES(longitud),autorizado_por=VALUES(autorizado_por),consentimiento_en=UTC_TIMESTAMP()')->execute([$type,$id,$zone['latitud'],$zone['longitud'],$yo]);
        }
    };
    if ($type === 'banda') {
        transaccionBanda($yo, $id, $operation);
        return;
    }
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$yo]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        if ($type === 'evento') {
            $st = $db->prepare('SELECT id FROM eventos_musicales WHERE id=? FOR UPDATE');
            $st->execute([$id]);
        }
        $operation($db);
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
function miZonaMapa(int $yo, string $type, int $id): ?array
{
    autorizarLugarMapa($yo, $type, $id);
    if ($type === 'musico') {
        $st = getDB()->prepare('SELECT l.latitud,l.longitud FROM ubicaciones_publicas l JOIN musicmad_location_consent c ON c.usuario_id=l.usuario_id WHERE l.usuario_id=? AND l.visible=1');
        $st->execute([$yo]);
    } else {
        $st = getDB()->prepare('SELECT latitud,longitud FROM musicmad_map_places WHERE tipo=? AND objeto_id=?');
        $st->execute([$type,$id]);
    }
    return $st->fetch() ?: null;
}
function puntosMapaEscena(int $yo, array $bounds, string $type = 'todos', string $city = ''): array
{
    if (!mapaDisponible()) {
        return [];
    }
    if (!in_array($type, ['todos','musico','banda','evento'], true)) {
        throw new InvalidArgumentException('Filtro no válido.');
    }
    $west = coordenadaMapa($bounds['oeste'] ?? null, -180, 180);
    $east = coordenadaMapa($bounds['este'] ?? null, -180, 180);
    $south = coordenadaMapa($bounds['sur'] ?? null, -85, 85);
    $north = coordenadaMapa($bounds['norte'] ?? null, -85, 85);
    if ($west >= $east || $south >= $north) {
        throw new InvalidArgumentException('Área no válida.');
    }
    $city = mb_substr(trim($city), 0, 80);
    $box = [':west' => $west,':east' => $east,':south' => $south,':north' => $north];
    $result = [];
    foreach (['musico','banda','evento'] as $kind) {
        if ($kind === 'banda' && !bandasWorkspaceDisponible()) {
            continue;
        }
        if ($type !== 'todos' && $type !== $kind) {
            continue;
        }
        $alias = $kind === 'musico' ? 'u.id' : ($kind === 'banda' ? 'b.creador_id' : 'ev.creador_id');
        [$policy,$params] = filtroAccesoContenido($yo, $alias, 'scene_' . $kind);
        $params += $box;
        $cityFilter = $city !== '' ? ' AND ' . ($kind === 'musico' ? 'pm.ciudad' : ($kind === 'banda' ? 'b.ciudad' : 'ev.ciudad')) . ' LIKE :city' : '';
        if ($city !== '') {
            $params[':city'] = '%' . addcslashes($city, '%_\\') . '%';
        }
        if ($kind === 'musico') {
            $sql = 'SELECT u.id,CONCAT_WS(" ",u.nombre,u.apellido) AS titulo,pm.instrumento AS detalle,pm.ciudad,l.latitud,l.longitud FROM ubicaciones_publicas l JOIN musicmad_location_consent c ON c.usuario_id=l.usuario_id JOIN usuarios u ON u.id=l.usuario_id AND u.activo=1 LEFT JOIN perfiles_musicales pm ON pm.usuario_id=u.id WHERE l.visible=1 AND ' . $policy . $cityFilter;
        } elseif ($kind === 'banda') {
            $sql = "SELECT b.id,b.nombre AS titulo,b.genero AS detalle,b.ciudad,l.latitud,l.longitud FROM musicmad_map_places l JOIN bandas b ON b.id=l.objeto_id JOIN usuarios u ON u.id=b.creador_id AND u.activo=1 LEFT JOIN musicmad_band_settings s ON s.banda_id=b.id WHERE l.tipo='banda' AND s.archivada_en IS NULL AND " . $policy . $cityFilter;
        } else {
            $params[':event_now'] = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');
            $sql = "SELECT ev.id,ev.titulo,ev.sede AS detalle,ev.ciudad,l.latitud,l.longitud FROM musicmad_map_places l JOIN eventos_musicales ev ON ev.id=l.objeto_id JOIN usuarios u ON u.id=ev.creador_id AND u.activo=1 WHERE l.tipo='evento' AND ev.estado='publicado' AND ev.inicio>=:event_now AND " . $policy . $cityFilter;
        }
        $sql .= ' AND l.latitud BETWEEN :south AND :north AND l.longitud BETWEEN :west AND :east ORDER BY ' . ($kind === 'evento' ? 'ev.inicio' : 'titulo') . ' LIMIT 70';
        $st = getDB()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $row) {
            $zone = zonaAproximada((string)$row['latitud'], (string)$row['longitud']);
            $result[] = ['id' => (int)$row['id'],'tipo' => $kind,'titulo' => $row['titulo'],'detalle' => $row['detalle'] ?? '','ciudad' => $row['ciudad'] ?? '','latitud' => $zone['latitud'],'longitud' => $zone['longitud'],'radio_km' => 10,'url' => $kind === 'musico' ? 'musico.php?id=' . $row['id'] : ($kind === 'banda' ? 'banda.php?id=' . $row['id'] : 'evento.php?id=' . $row['id'])];
        }
    }
    return array_slice($result, 0, 200);
}
