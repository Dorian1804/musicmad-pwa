<?php

const NIVELES_MUSICALES = ['sin_definir' => 'Cualquier nivel','principiante' => 'Principiante','intermedio' => 'Intermedio','avanzado' => 'Avanzado','profesional' => 'Profesional'];
const INSTRUMENTOS_AFINES = ['bateria' => ['bateria','baterista','drums'],'voz' => ['voz','vocal','vocalista','cantante'],'guitarra' => ['guitarra','guitarrista'],'bajo' => ['bajo','bajista'],'piano' => ['piano','pianista'],'teclado' => ['teclado','tecladista'],'violin' => ['violin','violinista'],'viola' => ['viola','violista'],'violonchelo' => ['violonchelo','chelo','cellista'],'contrabajo' => ['contrabajo','contrabajista'],'saxofon' => ['saxofon','saxofonista','saxo'],'trompeta' => ['trompeta','trompetista'],'flauta' => ['flauta','flautista'],'percusion' => ['percusion','percusionista'],'produccion' => ['produccion','productor','productora','beats','beatmaker']];
function matchingDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('musicmad_musician_levels','musicmad_wanted_levels')");
    $st->execute();
    $count = (int)$st->fetchColumn();
    if ($count === 1) {
        throw new RuntimeException('Actualización de descubrimiento incompleta.');
    }
    return $ready = $count === 2;
}
function normalizarAfinidad(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = strtr($value, ['á' => 'a','é' => 'e','í' => 'i','ó' => 'o','ú' => 'u','ü' => 'u','ñ' => 'n']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
}
function instrumentosAfinidad(string $value): array
{
    $text = normalizarAfinidad($value);
    $found = [];
    foreach (INSTRUMENTOS_AFINES as $key => $aliases) {
        foreach ($aliases as $alias) {
            if (preg_match('/(?:^| )' . preg_quote($alias, '/') . '(?: |$)/', $text)) {
                $found[] = $key;
                break;
            }
        }
    }
    return array_values(array_unique($found));
}
function coincideInstrumento(string $wanted, string $candidate): bool
{
    $a = instrumentosAfinidad($wanted);
    $b = instrumentosAfinidad($candidate);
    if ($a) {
        return (bool)array_intersect($a, $b);
    }
    $a = normalizarAfinidad($wanted);
    $b = normalizarAfinidad($candidate);
    return $a !== '' && preg_match('/(?:^| )' . preg_quote($a, '/') . '(?: |$)/', $b) === 1;
}
function guardarNivelMusico(int $yo, string $level): void
{
    if (!isset(NIVELES_MUSICALES[$level])) {
        throw new InvalidArgumentException('Nivel no válido.');
    }
    if (!matchingDisponible()) {
        return;
    }
    getDB()->prepare('INSERT INTO musicmad_musician_levels(usuario_id,nivel) VALUES(?,?) ON DUPLICATE KEY UPDATE nivel=VALUES(nivel)')->execute([$yo,$level]);
}
function nivelMusico(int $yo): string
{
    if (!matchingDisponible()) {
        return 'sin_definir';
    }
    $st = getDB()->prepare('SELECT nivel FROM musicmad_musician_levels WHERE usuario_id=?');
    $st->execute([$yo]);
    return $st->fetchColumn() ?: 'sin_definir';
}
function crearBusquedaMatching(int $yo, array $data): array
{
    $level = textoCampo($data, 'nivel') ?: 'sin_definir';
    if (!isset(NIVELES_MUSICALES[$level])) {
        return ['ok' => false,'mensaje' => 'Nivel no válido.'];
    }
    $band = idCampo($data, 'banda_id');
    $operation = function (PDO $db) use ($yo, $data, $level, $band) {
        if ($band) {
            exigirIntegranteBanda($yo, $band, ['admin']);
            limiteChat($yo, 'buscar_crear', 10);
        }
        $result = crearBusquedaBase($yo, $data);
        if ($result['ok']) {
            $db->prepare('INSERT INTO musicmad_wanted_levels(busqueda_id,nivel) VALUES(?,?)')->execute([$result['id'],$level]);
        }
        return $result;
    };
    if ($band) {
        return transaccionBanda($yo, $band, $operation);
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
        limiteChat($yo, 'buscar_crear', 10);
        $result = $operation($db);
        if ($owns) {
            $db->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function busquedaParaMatching(int $yo, int $id): array
{
    $st = getDB()->prepare("SELECT b.*,COALESCE(l.nivel,'sin_definir') AS nivel FROM busquedas b LEFT JOIN musicmad_wanted_levels l ON l.busqueda_id=b.id WHERE b.id=? AND b.usuario_id=? AND b.abierta=1");
    $st->execute([$id,$yo]);
    $row = $st->fetch();
    if (!$row || ($row['banda_id'] && !bandaDisponible($yo, (int)$row['banda_id']))) {
        throw new OutOfBoundsException('Búsqueda no disponible para tu cuenta.');
    }
    return $row;
}
function sugerirMusicosCompatibles(int $yo, array $wanted, int $limit = 12): array
{
    if (!matchingDisponible()) {
        return [];
    }
    $instrument = mb_substr(textoCampo($wanted, 'instrumento'), 0, 60);
    $genre = textoCampo($wanted, 'genero');
    $city = mb_substr(textoCampo($wanted, 'ciudad'), 0, 80);
    $level = textoCampo($wanted, 'nivel') ?: 'sin_definir';
    if (!isset(NIVELES_MUSICALES[$level])) {
        throw new InvalidArgumentException('Nivel no válido.');
    }
    $levels = array_flip(array_keys(NIVELES_MUSICALES));
    [$policy,$params] = filtroAccesoContenido($yo, 'u.id', 'matching_candidate');
    $params[':yo'] = $yo;
    $where = 'u.activo=1 AND u.id<>:yo AND (' . $policy . ')';
    $keys = instrumentosAfinidad($instrument);
    $terms = [];
    foreach ($keys as $key) {
        foreach (INSTRUMENTOS_AFINES[$key] as $term) {
            $terms[] = $term;
        }
    }
    if (!$terms && $instrument !== '') {
        $terms[] = $instrument;
    }
    if ($terms) {
        $ors = [];
        foreach (array_slice(array_unique($terms), 0, 30) as $i => $term) {
            $name = ':instrument' . $i;
            $ors[] = 'pm.instrumento LIKE ' . $name;
            $params[$name] = '%' . addcslashes($term, '%_\\') . '%';
        }
        $where .= ' AND (' . implode(' OR ', $ors) . ')';
    }
    $st = getDB()->prepare("SELECT u.id,u.nombre,u.apellido,u.avatar,pm.instrumento,pm.generos,pm.ciudad,COALESCE(l.nivel,'sin_definir') AS nivel FROM usuarios u JOIN perfiles_musicales pm ON pm.usuario_id=u.id LEFT JOIN musicmad_musician_levels l ON l.usuario_id=u.id WHERE " . $where . ' ORDER BY u.id DESC LIMIT 300');
    $st->execute($params);
    $results = [];
    foreach ($st->fetchAll() as $candidate) {
        if ($instrument !== '' && !coincideInstrumento($instrument, $candidate['instrumento'])) {
            continue;
        }
        if ($level !== 'sin_definir' && $levels[$candidate['nivel']] < $levels[$level]) {
            continue;
        }
        $score = 0;
        $reasons = [];
        if ($instrument !== '') {
            $score += 50;
            $reasons[] = 'Instrumento compatible';
        }
        $genres = array_map('normalizarAfinidad', explode(',', $candidate['generos']));
        if ($genre !== '' && in_array(normalizarAfinidad($genre), $genres, true)) {
            $score += 25;
            $reasons[] = 'Comparten género';
        }
        if ($city !== '' && normalizarAfinidad($candidate['ciudad']) === normalizarAfinidad($city)) {
            $score += 20;
            $reasons[] = 'Misma ciudad';
        }
        if ($level !== 'sin_definir') {
            $score += 5;
            $reasons[] = 'Nivel solicitado';
        }
        $candidate['coincidencias'] = $reasons;
        $candidate['orden'] = $score;
        $results[] = $candidate;
    }
    usort($results, static fn ($a, $b) => ($b['orden'] <=> $a['orden']) ?: strcmp($a['nombre'] . ' ' . $a['apellido'], $b['nombre'] . ' ' . $b['apellido']) ?: ($a['id'] <=> $b['id']));
    return array_slice($results, 0, max(1, min(30, $limit)));
}
