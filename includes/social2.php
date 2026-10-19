<?php

require_once __DIR__ . '/media_backup.php';
// ============================================================
//  MusicMad – Red social, parte 2
//  includes/social2.php
//  Audio propio · notificaciones · mensajes · bandas · "se busca"
//  (requiere includes/auth.php; lo carga includes/social.php)
// ============================================================

require_once __DIR__ . '/upload_transport.php';
require_once __DIR__ . '/media_metadata.php';

define('AUDIO_DIR', musicmadUploadDirectory('audio'));
const AUDIO_MAX_BYTES = 64 * 1024 * 1024;  // 64 MB
define('VIDEO_DIR', musicmadUploadDirectory('videos'));
const VIDEO_MAX_BYTES = 256 * 1024 * 1024; // 256 MB
const AUDIO_TIPOS = [
    'mp3' => ['audio/mpeg', 'audio/mp3'],
    'ogg' => ['audio/ogg', 'application/ogg'],
    'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave', 'audio/x-pn-wav'],
    'm4a' => ['audio/mp4', 'application/mp4', 'audio/x-m4a', 'video/mp4', 'audio/aac'],
];
define('PARTITURA_DIR', musicmadUploadDirectory('partituras'));
const PARTITURA_MAX_BYTES = 20 * 1024 * 1024;
define('MERCADO_DIR', musicmadUploadDirectory('market'));
const MERCADO_FOTO_MAX_BYTES = 5 * 1024 * 1024;
const MERCADO_CATEGORIAS = ['Guitarras y bajos', 'Baterías y percusión', 'Teclados', 'Viento', 'Cuerdas', 'Audio y estudio', 'Accesorios', 'Otro'];

function guardarFotoMercado(array $f): array
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true,'archivo' => null];
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false,'mensaje' => 'La foto supera el límite del hosting (5 MB).'];
    }
    if ($err !== UPLOAD_ERR_OK || !isset($f['tmp_name'],$f['size']) || !is_string($f['tmp_name']) || !is_numeric($f['size']) || !archivoCargaReal($f)) {
        return ['ok' => false,'mensaje' => 'No se recibió la foto del instrumento.'];
    }
    if ((int)$f['size'] < 1 || (int)$f['size'] > MERCADO_FOTO_MAX_BYTES || !class_exists('finfo') || !function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return ['ok' => false,'mensaje' => 'La foto debe ser JPG, PNG o WebP de hasta 5 MB; el hosting debe tener GD y Fileinfo.'];
    }
    try {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $info = @getimagesize($f['tmp_name']);
        $allowed = ['image/jpeg','image/png','image/webp'];
        if (!is_string($mime) || !in_array($mime, $allowed, true) || !is_array($info) || !in_array($info['mime'] ?? '', $allowed, true)) {
            return ['ok' => false,'mensaje' => 'Usa una imagen JPG, PNG o WebP válida.'];
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w < 1 || $h < 1 || $w > 10000 || $h > 10000 || $w * $h > 12000000) {
            return ['ok' => false,'mensaje' => 'La foto tiene dimensiones demasiado grandes.'];
        }
        if (!presupuestoImagenSeguro($w, $h, (string)ini_get('memory_limit'), memory_get_usage(true))) {
            return ['ok' => false, 'mensaje' => 'La foto necesita más memoria de la disponible. Elige una imagen reducida.'];
        }
        $data = file_get_contents($f['tmp_name']);
        $src = is_string($data) ? @imagecreatefromstring($data) : false;
        unset($data);
        if ($src === false) {
            return ['ok' => false,'mensaje' => 'No se pudo procesar la foto.'];
        }
        if (!is_dir(MERCADO_DIR) && !mkdir(MERCADO_DIR, 0755, true) && !is_dir(MERCADO_DIR)) {
            imagedestroy($src);
            return ['ok' => false,'mensaje' => 'No se pudo preparar el espacio para guardar la foto.'];
        }
        if (is_link(MERCADO_DIR)) {
            imagedestroy($src);
            return ['ok' => false,'mensaje' => 'La carpeta de fotos no es segura.'];
        }
        $scale = min(1, 1200 / $w, 900 / $h);
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $canvas = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $name = bin2hex(random_bytes(12)) . '.jpg';
        $tmp = MERCADO_DIR . '/' . $name . '.tmp';
        $dest = MERCADO_DIR . '/' . $name;
        $ok = imagejpeg($canvas, $tmp, 86);
        imagedestroy($canvas);
        if (!$ok || !rename($tmp, $dest)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            return ['ok' => false,'mensaje' => 'No se pudo guardar la foto.'];
        }
        respaldarMedioGuardado('market', $name);
        return ['ok' => true,'archivo' => $name];
    } catch (Throwable $e) {
        error_log('No se pudo procesar foto del marketplace: ' . get_class($e));
        return ['ok' => false,'mensaje' => 'No se pudo procesar la foto.'];
    }
}

function crearAnuncioInstrumento(int $uid, array $d, ?array $fotos): array
{
    $titulo = trim(textoCampo($d, 'titulo'));
    $descripcion = trim(textoCampo($d, 'descripcion'));
    $categoria = textoCampo($d, 'categoria');
    $condicion = textoCampo($d, 'condicion');
    $precioRaw = trim(textoCampo($d, 'precio'));
    $ciudad = trim(textoCampo($d, 'ciudad'));
    if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 120 || mb_strlen($descripcion) > 700 || mb_strlen($ciudad) > 80) {
        return ['ok' => false,'mensaje' => 'Revisa el título, descripción y ciudad.'];
    }
    if (!in_array($categoria, MERCADO_CATEGORIAS, true) || !in_array($condicion, ['Como nuevo','Buen estado','Con detalles'], true)) {
        return ['ok' => false,'mensaje' => 'Selecciona una categoría y estado válidos.'];
    }
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $precioRaw) || (float)$precioRaw <= 0 || (float)$precioRaw > 10000000) {
        return ['ok' => false,'mensaje' => 'Indica un precio válido en pesos.'];
    }
    $archivos = [];
    if ($fotos && isset($fotos['name']) && is_array($fotos['name'])) {
        $n = count($fotos['name']);
        if ($n > 4) {
            return ['ok' => false,'mensaje' => 'Puedes agregar hasta 4 fotos.'];
        }
        for ($i = 0;$i < $n;$i++) {
            if (($fotos['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $r = guardarFotoMercado(['name' => $fotos['name'][$i] ?? '','type' => $fotos['type'][$i] ?? '','tmp_name' => $fotos['tmp_name'][$i] ?? '','error' => $fotos['error'][$i] ?? UPLOAD_ERR_NO_FILE,'size' => $fotos['size'][$i] ?? 0]);
            if (!$r['ok']) {
                foreach ($archivos as $a) {
                    @unlink(MERCADO_DIR . '/' . $a);
                }
                return $r;
            }
            if ($r['archivo']) {
                $archivos[] = $r['archivo'];
            }
        }
    } elseif ($fotos && ($fotos['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $r = guardarFotoMercado($fotos);
        if (!$r['ok']) {
            return $r;
        }
        if ($r['archivo']) {
            $archivos[] = $r['archivo'];
        }
    }
    $db = getDB();
    try {
        $db->beginTransaction();
        $db->prepare('INSERT INTO instrumentos_usados (usuario_id,titulo,descripcion,categoria,condicion,precio,ciudad,foto) VALUES (:u,:t,:d,:c,:co,:p,:ci,:f)')
          ->execute([':u' => $uid,':t' => $titulo,':d' => $descripcion,':c' => $categoria,':co' => $condicion,':p' => $precioRaw,':ci' => $ciudad,':f' => $archivos[0] ?? null]);
        $id = (int)$db->lastInsertId();
        foreach ($archivos as $i => $archivo) {
            $db->prepare('INSERT INTO instrumentos_fotos (instrumento_id,archivo,orden) VALUES (:i,:a,:o)')->execute([':i' => $id,':a' => $archivo,':o' => $i]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($archivos as $a) {
            @unlink(MERCADO_DIR . '/' . $a);
        }
        error_log('No se pudo publicar instrumento: ' . get_class($e));
        return ['ok' => false,'mensaje' => 'No se pudo publicar el anuncio.'];
    }
    return ['ok' => true,'id' => $id,'mensaje' => 'Anuncio publicado.'];
}

function alternarFavoritoInstrumento(int $uid, int $itemId): bool
{
    $db = getDB();
    $del = $db->prepare('DELETE FROM instrumentos_favoritos WHERE usuario_id=:u AND instrumento_id=:i');
    $del->execute([':u' => $uid,':i' => $itemId]);
    if ($del->rowCount() === 0) {
        [$policy,$params] = social3Disponible() ? filtroAccesoContenido($uid, 'i.usuario_id', 'favorite_owner') : ['1=1',[]];
        $db->prepare("INSERT IGNORE INTO instrumentos_favoritos (usuario_id,instrumento_id) SELECT :u,i.id FROM instrumentos_usados i JOIN usuarios u ON u.id=i.usuario_id AND u.activo=1 WHERE i.id=:i AND i.estado='disponible' AND i.usuario_id<>:owner AND (" . $policy . ")")
           ->execute([':u' => $uid,':i' => $itemId,':owner' => $uid] + $params);
    }
    $st = $db->prepare('SELECT COUNT(*) FROM instrumentos_favoritos WHERE usuario_id=:u AND instrumento_id=:i');
    $st->execute([':u' => $uid,':i' => $itemId]);
    return (int)$st->fetchColumn() > 0;
}

function reportarInstrumento(int $uid, int $itemId, string $motivo, string $detalle): array
{
    $permitidos = ['Información engañosa','Posible fraude','Producto prohibido','Contenido inapropiado','Otro'];
    $detalle = trim($detalle);
    if (!in_array($motivo, $permitidos, true) || mb_strlen($detalle) > 250) {
        return ['ok' => false,'mensaje' => 'Elige un motivo válido y limita el detalle a 250 caracteres.'];
    }
    try {
        [$policy,$params] = social3Disponible() ? filtroAccesoContenido($uid, 'i.usuario_id', 'report_owner') : ['1=1',[]];
        $st = getDB()->prepare("INSERT INTO instrumentos_reportes (usuario_id,instrumento_id,motivo,detalle) SELECT :u,i.id,:m,:d FROM instrumentos_usados i JOIN usuarios u ON u.id=i.usuario_id AND u.activo=1 WHERE i.id=:i AND i.estado='disponible' AND i.usuario_id<>:propio AND (" . $policy . ")");
        $st->execute([':u' => $uid,':m' => $motivo,':d' => $detalle,':i' => $itemId,':propio' => $uid] + $params);
        if ($st->rowCount() > 0) {
            return ['ok' => true,'mensaje' => 'Gracias. El equipo de MusicMad revisará el anuncio.'];
        }
        return ['ok' => false,'mensaje' => 'No se pudo reportar: el anuncio ya no está disponible o ya lo reportaste.'];
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            return ['ok' => false,'mensaje' => 'Ya reportaste este anuncio. Administración revisará el reporte existente.'];
        }
        throw $e;
    }
}

function listarInstrumentos(int $yo, string $q = '', array $filtros = []): array
{
    $q = mb_substr(trim($q), 0, 80);
    $where = ['(i.estado = \'disponible\' OR i.usuario_id = :yo)'];
    $p = [':yo' => $yo,':yo_fav' => $yo];
    if (social3Disponible()) {
        [$policy, $accessParams] = filtroAccesoContenido($yo, 'i.usuario_id', 'market');
        $where[] = '(' . $policy . ')';
        $p += $accessParams;
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(i.titulo LIKE :q OR i.descripcion LIKE :q2 OR i.ciudad LIKE :q3)';
        $p += [':q' => $like,':q2' => $like,':q3' => $like];
    }
    if (in_array($filtros['categoria'] ?? '', MERCADO_CATEGORIAS, true)) {
        $where[] = 'i.categoria = :cat';
        $p[':cat'] = $filtros['categoria'];
    }
    $ciudad = mb_substr(trim((string)($filtros['ciudad'] ?? '')), 0, 80);
    if ($ciudad !== '') {
        $where[] = 'i.ciudad LIKE :ciudad';
        $p[':ciudad'] = '%' . addcslashes($ciudad, '%_\\') . '%';
    }
    foreach (['min' => 'minimo','max' => 'maximo'] as $key => $param) {
        $value = (string)($filtros[$key] ?? '');
        if ($value !== '' && preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value)) {
            $where[] = $key === 'min' ? 'i.precio >= :' . $param : 'i.precio <= :' . $param;
            $p[':' . $param] = $value;
        }
    }
    if (!empty($filtros['guardados'])) {
        $where[] = 'EXISTS(SELECT 1 FROM instrumentos_favoritos fg WHERE fg.instrumento_id=i.id AND fg.usuario_id=:solo_fav)';
        $p[':solo_fav'] = $yo;
    }
    if (!empty($filtros['anuncio'])) {
        $where[] = 'i.id=:anuncio';
        $p[':anuncio'] = (int)$filtros['anuncio'];
    }
    $orden = match ($filtros['orden'] ?? '') {
        'precio_bajo' => 'i.precio ASC,i.created_at DESC', 'precio_alto' => 'i.precio DESC,i.created_at DESC', default => '(i.estado=\'disponible\') DESC,i.created_at DESC'
    };
    $pagina = max(1, min(10000, (int)($filtros['pagina'] ?? 1)));
    $porPagina = 24;
    $offset = ($pagina - 1) * $porPagina;
    $db = getDB();
    $pc = $p;
    unset($pc[':yo_fav']);
    $cuenta = $db->prepare('SELECT COUNT(*) FROM instrumentos_usados i JOIN usuarios u ON u.id=i.usuario_id AND u.activo=1 WHERE ' . implode(' AND ', $where));
    $cuenta->execute($pc);
    $total = (int)$cuenta->fetchColumn();
    $st = $db->prepare("SELECT i.*,u.nombre,u.apellido,u.avatar,
            (SELECT COUNT(*) FROM instrumentos_favoritos f WHERE f.instrumento_id=i.id) AS favoritos,
            EXISTS(SELECT 1 FROM instrumentos_favoritos fy WHERE fy.instrumento_id=i.id AND fy.usuario_id=:yo_fav) AS yo_favorito,
            (SELECT GROUP_CONCAT(foto.archivo ORDER BY foto.orden SEPARATOR ',') FROM instrumentos_fotos foto WHERE foto.instrumento_id=i.id) AS galeria
           FROM instrumentos_usados i JOIN usuarios u ON u.id=i.usuario_id AND u.activo=1 WHERE " . implode(' AND ', $where) . ' ORDER BY ' . $orden . ' LIMIT ' . $porPagina . ' OFFSET ' . $offset);
    $st->execute($p);
    return ['items' => $st->fetchAll(),'total' => $total,'pagina' => $pagina,'por_pagina' => $porPagina];
}

function contarReportesInstrumentos(): int
{
    try {
        return (int)getDB()->query("SELECT COUNT(*) FROM instrumentos_reportes WHERE estado='pendiente'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function listarReportesInstrumentos(): array
{
    return getDB()->query("SELECT r.*,i.titulo,i.foto,i.usuario_id AS vendedor_id,v.nombre AS vendedor_nombre,v.apellido AS vendedor_apellido,
              u.nombre AS reportador_nombre,u.apellido AS reportador_apellido
         FROM instrumentos_reportes r JOIN instrumentos_usados i ON i.id=r.instrumento_id
         JOIN usuarios u ON u.id=r.usuario_id LEFT JOIN usuarios v ON v.id=i.usuario_id
        WHERE r.estado='pendiente' ORDER BY r.created_at ASC LIMIT 100")->fetchAll();
}

function resolverReporteInstrumento(int $reporteId, int $adminId, string $decision): bool
{
    if (!in_array($decision, ['retirar','descartar'], true)) {
        return false;
    }
    return transaccionModeracion($adminId, function (PDO $db) use ($reporteId, $adminId, $decision) {
        $st = $db->prepare("SELECT instrumento_id FROM instrumentos_reportes WHERE id=? AND estado='pendiente' FOR UPDATE");
        $st->execute([$reporteId]);
        $item = (int)$st->fetchColumn();
        if (!$item) {
            return false;
        }
        if ($decision === 'retirar') {
            $db->prepare("UPDATE instrumentos_usados SET estado='oculto' WHERE id=?")->execute([$item]);
        }
        $db->prepare('UPDATE instrumentos_reportes SET estado=?,revisado_por=?,revisado_en=UTC_TIMESTAMP() WHERE id=?')->execute([$decision === 'retirar' ? 'retirado' : 'descartado',$adminId,$reporteId]);
        auditarCuenta($adminId, 'anuncio_moderado', 'anuncio', $item);
        return true;
    });
}

function cambiarEstadoInstrumento(int $id, int $uid, string $estado): bool
{
    if (!in_array($estado, ['disponible','reservado','vendido'], true)) {
        return false;
    }
    $st = getDB()->prepare('UPDATE instrumentos_usados SET estado=:e WHERE id=:id AND usuario_id=:u AND estado<>\'oculto\'');
    $st->execute([':e' => $estado,':id' => $id,':u' => $uid]);
    return $st->rowCount() > 0;
}

function eliminarInstrumento(int $id, int $uid): bool
{
    $st = getDB()->prepare('SELECT foto FROM instrumentos_usados WHERE id=:id AND usuario_id=:u');
    $st->execute([':id' => $id,':u' => $uid]);
    $foto = $st->fetchColumn();
    if ($foto === false) {
        return false;
    }
    $fs = getDB()->prepare('SELECT archivo FROM instrumentos_fotos WHERE instrumento_id=:i');
    $fs->execute([':i' => $id]);
    $fotos = $fs->fetchAll(PDO::FETCH_COLUMN);
    getDB()->prepare('DELETE FROM instrumentos_usados WHERE id=:id AND usuario_id=:u')->execute([':id' => $id,':u' => $uid]);
    foreach (array_unique(array_merge($fotos, [$foto])) as $archivo) {
        if (is_string($archivo) && preg_match('/^[a-f0-9]{24}\.jpg$/', $archivo)) {
            @unlink(MERCADO_DIR . '/' . $archivo);
        }
    }
    return true;
}

function guardarPartitura(int $pubId, array $f): array
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'mensaje' => 'El PDF supera el límite que permite el hosting.'];
    }
    if ($err !== UPLOAD_ERR_OK || !isset($f['tmp_name'], $f['size'], $f['name']) || !is_string($f['tmp_name']) || !is_string($f['name']) || !is_numeric($f['size']) || !archivoCargaReal($f)) {
        return ['ok' => false, 'mensaje' => 'Selecciona un PDF de hasta 20 MB.'];
    }
    if ((int)$f['size'] < 8 || (int)$f['size'] > PARTITURA_MAX_BYTES || strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'pdf' || !class_exists('finfo')) {
        return ['ok' => false, 'mensaje' => 'La partitura debe ser un PDF válido de hasta 20 MB.'];
    }
    $fi = new finfo(FILEINFO_MIME_TYPE);
    if ($fi->file($f['tmp_name']) !== 'application/pdf') {
        return ['ok' => false, 'mensaje' => 'El archivo no parece ser un PDF.'];
    }
    $fh = fopen($f['tmp_name'], 'rb');
    $sig = $fh ? fread($fh, 5) : '';
    if ($fh) {
        fclose($fh);
    }
    if ($sig !== '%PDF-') {
        return ['ok' => false, 'mensaje' => 'El archivo PDF no tiene un formato válido.'];
    }
    if (!is_dir(PARTITURA_DIR) && !mkdir(PARTITURA_DIR, 0755, true)) {
        return ['ok' => false, 'mensaje' => 'No se pudo guardar el PDF.'];
    }
    $name = bin2hex(random_bytes(12)) . '.pdf';
    if (!moverArchivoCarga($f, PARTITURA_DIR . '/' . $name)) {
        return ['ok' => false, 'mensaje' => 'No se pudo guardar el PDF.'];
    }
    try {
        respaldarMedioGuardado('partituras', $name);
        getDB()->prepare('INSERT INTO publicacion_partitura (publicacion_id, archivo, bytes) VALUES (:p,:a,:b)')->execute([':p' => $pubId, ':a' => $name, ':b' => (int)$f['size']]);
    } catch (Throwable $error) {
        @unlink(PARTITURA_DIR . '/' . $name);
        throw $error;
    }
    return ['ok' => true, 'mensaje' => '', 'archivo' => $name];
}

function borrarPartituraDePublicacion(int $pubId): void
{
    $st = getDB()->prepare('SELECT archivo FROM publicacion_partitura WHERE publicacion_id = :p');
    $st->execute([':p' => $pubId]);
    $f = $st->fetchColumn();
    if (is_string($f) && preg_match('/^[a-f0-9]{24}\.pdf$/', $f)) {
        @unlink(PARTITURA_DIR . '/' . $f);
    }
}

// ── Audio propio ────────────────────────────────────────────

function archivoSubido(string $campo): bool
{
    return isset($_FILES[$campo]) && is_array($_FILES[$campo])
        && isset($_FILES[$campo]['error']) && is_int($_FILES[$campo]['error'])
        && $_FILES[$campo]['error'] !== UPLOAD_ERR_NO_FILE;
}

function guardarAudio(int $pubId, array $f): array
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'mensaje' => 'El audio supera el límite que permite este hosting. Prueba un archivo menor o pide aumentar el límite de subida.'];
    }
    if ($err === UPLOAD_ERR_PARTIAL) {
        return ['ok' => false, 'mensaje' => 'La conexión se cortó mientras subías el audio. Inténtalo otra vez.'];
    }
    if ($err !== UPLOAD_ERR_OK || !isset($f['tmp_name'], $f['size'], $f['name'])
        || !is_string($f['tmp_name']) || !is_string($f['name']) || !is_numeric($f['size'])
        || !archivoCargaReal($f)) {
        return ['ok' => false, 'mensaje' => 'No se pudo subir el audio. Inténtalo de nuevo.'];
    }
    if ((int) $f['size'] < 1 || (int) $f['size'] > AUDIO_MAX_BYTES) {
        return ['ok' => false, 'mensaje' => 'El audio debe pesar máximo 64 MB.'];
    }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset(AUDIO_TIPOS[$ext])) {
        return ['ok' => false, 'mensaje' => 'Formato no permitido. Usa MP3, M4A, OGG o WAV.'];
    }
    if (!class_exists('finfo')) {
        return ['ok' => false, 'mensaje' => 'El servidor no puede verificar el tipo de archivo.'];
    }
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($f['tmp_name']);
    $head = file_get_contents($f['tmp_name'], false, null, 0, 4096);
    $firmaValida = is_string($head) && match ($ext) {
        'mp3' => str_starts_with($head, 'ID3') || preg_match('/\xFF[\xE2-\xFF][\x00-\xF3]/', $head) === 1,
        'm4a' => is_string($head) && ($pos = strpos($head, 'ftyp')) !== false && $pos >= 4 && $pos < 64,
        'ogg' => str_starts_with($head, 'OggS'),
        'wav' => strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WAVE',
    };
    $mimeAceptado = is_string($mime) && (in_array($mime, AUDIO_TIPOS[$ext], true)
        || ($mime === 'application/octet-stream' && $firmaValida));
    if (!$mimeAceptado || !$firmaValida) {
        return ['ok' => false, 'mensaje' => 'El archivo no parece ser un audio válido.'];
    }

    try {
        $metadata = analizarMedioLocal($f['tmp_name'], 'audio');
    } catch (Throwable $error) {
        return ['ok' => false,'mensaje' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'No pudimos validar el audio. Comprueba las dependencias del hosting.'];
    }

    if (!is_dir(AUDIO_DIR) && !mkdir(AUDIO_DIR, 0755, true) && !is_dir(AUDIO_DIR)) {
        return ['ok' => false, 'mensaje' => 'No se pudo guardar el audio en el servidor.'];
    }
    if (is_link(AUDIO_DIR)) {
        return ['ok' => false,'mensaje' => 'La carpeta de audios no está configurada de forma segura.'];
    }
    $nombre = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!moverArchivoCarga($f, AUDIO_DIR . '/' . $nombre)) {
        return ['ok' => false, 'mensaje' => 'No se pudo guardar el audio en el servidor.'];
    }

    try {
        respaldarMedioGuardado('audio', $nombre);
        guardarMetadatosMedio($pubId, $metadata);
        getDB()->prepare('INSERT INTO publicacion_audio (publicacion_id, archivo, bytes) VALUES (:p, :a, :b)')
               ->execute([':p' => $pubId, ':a' => $nombre, ':b' => (int) $f['size']]);
    } catch (Throwable $e) {
        @unlink(AUDIO_DIR . '/' . $nombre);
        error_log('No se pudo registrar el audio: ' . get_class($e));
        return ['ok' => false, 'mensaje' => $e instanceof RespaldoMedioException ? $e->getMessage() : 'No se pudo registrar el audio en la publicación.'];
    }
    return ['ok' => true, 'mensaje' => '', 'archivo' => $nombre];
}

/** Borra el archivo físico de una publicación (la fila se borra en cascada). */
function borrarAudioDePublicacion(int $pubId): void
{
    $st = getDB()->prepare('SELECT archivo FROM publicacion_audio WHERE publicacion_id = :p');
    $st->execute([':p' => $pubId]);
    $archivo = $st->fetchColumn();
    if (is_string($archivo) && basename($archivo) === $archivo && !str_contains($archivo, '..') && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,70}\.(mp3|m4a|ogg|wav)$/i', $archivo)) {
        $base = realpath(AUDIO_DIR);
        $path = realpath(AUDIO_DIR . '/' . $archivo);
        if ($base !== false && $path !== false && dirname($path) === $base && is_file($path) && !is_link(AUDIO_DIR . '/' . $archivo)) {
            @unlink($path);
        }
    }
}

function guardarVideo(int $pubId, array $f): array
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false,'mensaje' => 'El video supera el límite que permite este hosting.'];
    }
    if ($err === UPLOAD_ERR_PARTIAL) {
        return ['ok' => false,'mensaje' => 'La conexión se cortó a la mitad de la subida. Conserva el original e inténtalo otra vez con una conexión estable.'];
    }
    if ($err !== UPLOAD_ERR_OK || !isset($f['tmp_name'],$f['size'],$f['name']) || !is_string($f['tmp_name']) || !is_string($f['name']) || !is_numeric($f['size']) || !archivoCargaReal($f)) {
        return ['ok' => false,'mensaje' => 'No se recibió el video correctamente.'];
    }
    if ((int)$f['size'] < 1 || (int)$f['size'] > VIDEO_MAX_BYTES || !class_exists('finfo')) {
        return ['ok' => false,'mensaje' => 'El video debe pesar máximo 256 MB.'];
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $mimes = ['mp4' => ['video/mp4','application/mp4','application/octet-stream'],'webm' => ['video/webm','application/x-matroska','application/octet-stream']];
    if (!isset($mimes[$ext])) {
        return ['ok' => false,'mensaje' => 'Usa un video MP4 o WebM.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $fh = fopen($f['tmp_name'], 'rb');
    $head = $fh ? fread($fh, 4096) : '';
    if ($fh) {
        fclose($fh);
    }
    $ftypPos = is_string($head) ? strpos($head, 'ftyp') : false;
    $firmaValida = $ext === 'mp4' ? ($ftypPos !== false && $ftypPos >= 4 && $ftypPos < 64) : (is_string($head) && str_starts_with($head, "\x1A\x45\xDF\xA3"));
    if (!is_string($mime) || !in_array($mime, $mimes[$ext], true) || !$firmaValida) {
        return ['ok' => false,'mensaje' => 'El archivo no parece ser un video MP4 o WebM válido.'];
    }
    try {
        $metadata = analizarMedioLocal($f['tmp_name'], 'video');
    } catch (Throwable $error) {
        return ['ok' => false,'mensaje' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'No pudimos validar el video. Comprueba las dependencias del hosting.'];
    }
    if (!is_dir(VIDEO_DIR) && !mkdir(VIDEO_DIR, 0755, true) && !is_dir(VIDEO_DIR)) {
        return ['ok' => false,'mensaje' => 'No se pudo preparar el espacio para guardar videos.'];
    }
    if (is_link(VIDEO_DIR)) {
        return ['ok' => false,'mensaje' => 'La carpeta de videos no está configurada de forma segura.'];
    }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!moverArchivoCarga($f, VIDEO_DIR . '/' . $name)) {
        return ['ok' => false,'mensaje' => 'No se pudo guardar el video.'];
    }
    try {
        respaldarMedioGuardado('videos', $name);
        guardarMetadatosMedio($pubId, $metadata);
        getDB()->prepare('INSERT INTO publicacion_video (publicacion_id,archivo,formato,bytes) VALUES (:p,:a,:f,:b)')->execute([':p' => $pubId,':a' => $name,':f' => $ext,':b' => (int)$f['size']]);
    } catch (Throwable $e) {
        @unlink(VIDEO_DIR . '/' . $name);
        error_log('No se pudo registrar el video: ' . get_class($e));
        return ['ok' => false,'mensaje' => $e instanceof RespaldoMedioException ? $e->getMessage() : 'No se pudo guardar el video en la publicación.'];
    }
    return ['ok' => true,'mensaje' => '','archivo' => $name];
}

function borrarVideoDePublicacion(int $pubId): void
{
    $st = getDB()->prepare('SELECT archivo FROM publicacion_video WHERE publicacion_id=:p');
    $st->execute([':p' => $pubId]);
    $file = $st->fetchColumn();
    if (is_string($file) && basename($file) === $file && !str_contains($file, '..') && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,70}\.(mp4|webm)$/i', $file)) {
        $base = realpath(VIDEO_DIR);
        $path = realpath(VIDEO_DIR . '/' . $file);
        if ($base !== false && $path !== false && dirname($path) === $base && is_file($path) && !is_link(VIDEO_DIR . '/' . $file)) {
            @unlink($path);
        }
    }
}

// ── Notificaciones ──────────────────────────────────────────

function notificar(int $para, int $actor, string $tipo, ?int $pubId = null, ?int $bandaId = null): void
{
    if ($para === $actor || $para <= 0) {
        return;
    }
    try {
        // Evita repetir la misma notificación sin leer (aplausos/seguidores)
        $st = getDB()->prepare(
            'INSERT INTO notificaciones (usuario_id, actor_id, tipo, publicacion_id, banda_id)
             SELECT :u, :a, :t, :p, :b FROM DUAL
              WHERE NOT EXISTS (SELECT 1 FROM notificaciones
                                 WHERE usuario_id = :u2 AND actor_id = :a2 AND tipo = :t2 AND leida = 0
                                   AND publicacion_id <=> :p2 AND banda_id <=> :b2 AND :t3 <> \'comentario\')'
        );
        $st->execute([':u' => $para, ':a' => $actor, ':t' => $tipo, ':p' => $pubId, ':b' => $bandaId,
                      ':u2' => $para, ':a2' => $actor, ':t2' => $tipo, ':p2' => $pubId, ':b2' => $bandaId, ':t3' => $tipo]);
        if ($st->rowCount() > 0) {
            $notification = (int)getDB()->lastInsertId();
            $category = ['aplauso' => 'aplausos','comentario' => 'comentarios','seguidor' => 'seguidores','banda' => 'bandas'][$tipo] ?? null;
            if ($category && function_exists('encolarAvisoPush')) {
                encolarAvisoPush($para, $actor, $category, $notification);
            }
        }
    } catch (Throwable $e) {
        error_log('Notification delivery: ' . get_class($e));
    }
}

function duenoPublicacion(int $pubId): int
{
    $st = getDB()->prepare('SELECT usuario_id FROM publicaciones WHERE id = :p');
    $st->execute([':p' => $pubId]);
    return (int) $st->fetchColumn();
}

function contarNoLeidas(int $uid): int
{
    try {
        [$block,$params] = filtroBloqueoInteraccion($uid, 'n.actor_id', 'notif_count');
        [$bandFilter,$bandParams] = filtroNotificacionesBandas($uid, 'notice_count_band');
        $params += $bandParams;
        $st = getDB()->prepare('SELECT COUNT(*) FROM notificaciones n JOIN usuarios u ON u.id=n.actor_id AND u.activo=1 WHERE n.usuario_id=:u AND n.leida=0 AND ' . $block . ' AND ' . $bandFilter);
        $st->execute([':u' => $uid] + $params);
        return (int) $st->fetchColumn() + contarAvisosEventos($uid);
    } catch (Throwable $e) {
        return 0;
    }
}

function listarNotificaciones(int $uid, int $limite = 50): array
{
    [$block,$params] = filtroBloqueoInteraccion($uid, 'n.actor_id', 'notif_list');
    $limite = max(1, min(50, $limite));
    [$bandFilter,$bandParams] = filtroNotificacionesBandas($uid, 'notice_list_band');
    $params += $bandParams;
    $bandFields = bandasWorkspaceDisponible() ? ',bn.subtipo AS banda_subtipo,bn.referencia_id AS banda_referencia' : '';
    $bandJoin = bandasWorkspaceDisponible() ? ' LEFT JOIN musicmad_band_notifications bn ON bn.notificacion_id=n.id ' : '';
    $st = getDB()->prepare(
        'SELECT n.*, u.nombre, u.apellido, u.avatar, b.nombre AS banda_nombre' . $bandFields . '
           FROM notificaciones n
           JOIN usuarios u ON u.id = n.actor_id
           LEFT JOIN bandas b ON b.id = n.banda_id' . $bandJoin . '
          WHERE n.usuario_id = :u AND u.activo=1 AND ' . $block . ' AND ' . $bandFilter . '
          ORDER BY n.id DESC LIMIT ' . (int) $limite
    );
    $st->execute([':u' => $uid] + $params);
    return $st->fetchAll();
}

function marcarNotificacionesLeidas(int $uid): void
{
    getDB()->prepare('UPDATE notificaciones SET leida = 1 WHERE usuario_id = :u AND leida = 0')->execute([':u' => $uid]);
}

/** [texto, url] de una notificación (el texto aún sin escapar). */
function textoNotificacion(array $n, int $yo): array
{
    $quien = $n['nombre'] . ' ' . $n['apellido'];
    if (!empty($n['banda_subtipo'])) {
        $text = match($n['banda_subtipo']) {
            'invitacion' => 'te invitó a','ensayo' => 'programó o actualizó un ensayo en','ensayo_cancelado' => 'canceló un ensayo en','rol' => 'actualizó tus permisos en','recordatorio' => 'te recuerda un ensayo próximo en',default => 'compartió una publicación en'
        };
        return [$quien . ' ' . $text . ' ' . ($n['banda_nombre'] ?? 'una banda'),$n['banda_subtipo'] === 'invitacion' ? 'bandas.php#invitaciones' : 'banda.php?id=' . (int)$n['banda_id'] . ((str_starts_with($n['banda_subtipo'], 'ensayo') || $n['banda_subtipo'] === 'recordatorio') ? '&tab=agenda' : '')];
    }
    return match ($n['tipo']) {
        'aplauso' => [$quien . ' aplaudió tu publicación', 'musico.php?id=' . $yo . '#pub-' . (int) $n['publicacion_id']],
        'comentario' => [$quien . ' comentó tu publicación', 'musico.php?id=' . $yo . '#pub-' . (int) $n['publicacion_id']],
        'seguidor' => [$quien . ' empezó a seguirte', 'musico.php?id=' . (int) $n['actor_id']],
        'banda' => [$quien . ' se unió a tu banda ' . ($n['banda_nombre'] ?? ''), 'banda.php?id=' . (int) $n['banda_id']],
        default => [$quien . ' interactuó contigo', 'feed.php'],
    };
}

// ── Mensajes directos ───────────────────────────────────────

function enviarMensaje(int $de, int $para, string $texto): array
{
    $texto = trim($texto);
    if ($de === $para) {
        return ['ok' => false, 'mensaje' => 'No puedes enviarte mensajes a ti mismo.'];
    }
    if ($texto === '' || mb_strlen($texto) > 1000) {
        return ['ok' => false, 'mensaje' => 'El mensaje está vacío o supera los 1000 caracteres.'];
    }
    if (function_exists('puedeEnviarMensajeSocial3') && !puedeEnviarMensajeSocial3($de, $para)) {
        return ['ok' => false, 'mensaje' => 'Este músico no recibe mensajes de tu cuenta.'];
    }
    $st = getDB()->prepare(
        'INSERT INTO mensajes (de_id, para_id, texto) SELECT :de, id, :t FROM usuarios WHERE id = :p AND activo = 1'
    );
    $st->execute([':de' => $de, ':t' => $texto, ':p' => $para]);
    return $st->rowCount() > 0 ? ['ok' => true, 'mensaje' => ''] : ['ok' => false, 'mensaje' => 'Ese músico no existe.'];
}

function conversaciones(int $yo): array
{
    [$block,$params] = filtroBloqueoInteraccion($yo, 'u.id', 'chat_list');
    $st = getDB()->prepare(
        'SELECT u.id, u.nombre, u.apellido, u.avatar, m.texto, m.created_at, m.de_id,
                (SELECT COUNT(*) FROM mensajes x WHERE x.de_id = u.id AND x.para_id = :yo AND x.leido = 0) AS sin_leer
           FROM (SELECT IF(de_id = :yo1, para_id, de_id) AS otro, MAX(id) AS ultimo
                   FROM mensajes WHERE de_id = :yo2 OR para_id = :yo3 GROUP BY otro) c
           JOIN mensajes m ON m.id = c.ultimo
           JOIN usuarios u ON u.id = c.otro
          WHERE u.activo=1 AND ' . $block . ' ORDER BY m.id DESC LIMIT 100'
    );
    $st->execute([':yo' => $yo, ':yo1' => $yo, ':yo2' => $yo, ':yo3' => $yo] + $params);
    return $st->fetchAll();
}

function hiloMensajes(int $yo, int $con, int $limite = 100): array
{
    $st = getDB()->prepare(
        'SELECT * FROM (
            SELECT id, de_id, texto, created_at FROM mensajes
             WHERE (de_id = :a AND para_id = :b) OR (de_id = :b2 AND para_id = :a2)
             ORDER BY id DESC LIMIT ' . (int) $limite . '
         ) t ORDER BY id ASC'
    );
    $st->execute([':a' => $yo, ':b' => $con, ':b2' => $con, ':a2' => $yo]);
    return $st->fetchAll();
}

function marcarMensajesLeidos(int $yo, int $con): void
{
    getDB()->prepare('UPDATE mensajes SET leido = 1 WHERE para_id = :yo AND de_id = :con AND leido = 0')
           ->execute([':yo' => $yo, ':con' => $con]);
}

function contarMensajesSinLeer(int $yo): int
{
    try {
        [$block,$params] = filtroBloqueoInteraccion($yo, 'm.de_id', 'chat_count');
        $st = getDB()->prepare('SELECT COUNT(*) FROM mensajes m JOIN usuarios u ON u.id=m.de_id AND u.activo=1 WHERE m.para_id=:yo AND m.leido=0 AND ' . $block);
        $st->execute([':yo' => $yo] + $params);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// ── Bandas ──────────────────────────────────────────────────

function crearBanda(int $uid, array $d): array
{
    $nombre = trim(textoCampo($d, 'nombre'));
    $desc = trim(textoCampo($d, 'descripcion'));
    $genero = trim(textoCampo($d, 'genero'));
    $ciudad = trim(textoCampo($d, 'ciudad'));
    $instr = trim(textoCampo($d, 'instrumento'));

    if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 60) {
        return ['ok' => false, 'mensaje' => 'El nombre de la banda debe tener entre 2 y 60 caracteres.'];
    }
    if (mb_strlen($desc) > 500 || mb_strlen($ciudad) > 80 || mb_strlen($instr) > 60) {
        return ['ok' => false, 'mensaje' => 'Algún campo es demasiado largo.'];
    }
    if ($genero !== '' && !in_array($genero, GENEROS, true)) {
        $genero = '';
    }

    $db = getDB();
    $st = $db->prepare('SELECT id FROM bandas WHERE nombre = :n');
    $st->execute([':n' => $nombre]);
    if ($st->fetch()) {
        return ['ok' => false, 'mensaje' => 'Ya existe una banda con ese nombre.'];
    }

    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $db->prepare('INSERT INTO bandas (nombre, descripcion, genero, ciudad, creador_id) VALUES (:n,:d,:g,:c,:u)')
           ->execute([':n' => $nombre, ':d' => $desc, ':g' => $genero, ':c' => $ciudad, ':u' => $uid]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO banda_miembros (banda_id, usuario_id, rol, instrumento) VALUES (:b,:u,\'admin\',:i)')
           ->execute([':b' => $id, ':u' => $uid, ':i' => $instr]);
        if ($owns) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        if (!$owns) {
            throw $e;
        }
        return ['ok' => false, 'mensaje' => 'No se pudo crear la banda.'];
    }
    return ['ok' => true, 'mensaje' => '¡Banda creada!', 'id' => $id];
}

function listarBandas(int $yo, string $q = ''): array
{
    if (bandasWorkspaceDisponible()) {
        return listarBandasWorkspace($yo, $q);
    }
    $sql = 'SELECT b.*, (SELECT COUNT(*) FROM banda_miembros m WHERE m.banda_id = b.id) AS miembros,
                   (SELECT COUNT(*) FROM banda_miembros m WHERE m.banda_id = b.id AND m.usuario_id = :yo) AS soy_miembro
              FROM bandas b';
    $p = [':yo' => $yo];
    if ($q !== '') {
        $sql .= ' WHERE b.nombre LIKE :q OR b.genero LIKE :q2 OR b.ciudad LIKE :q3';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $p += [':q' => $like, ':q2' => $like, ':q3' => $like];
    }
    $sql .= ' ORDER BY miembros DESC, b.created_at DESC LIMIT 60';
    $st = getDB()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

function obtenerBanda(int $id): ?array
{
    $st = getDB()->prepare('SELECT * FROM bandas WHERE id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

function miembrosBanda(int $bandaId): array
{
    if (bandasWorkspaceDisponible()) {
        return miembrosBandaWorkspace($bandaId);
    }
    $st = getDB()->prepare(
        'SELECT m.usuario_id, m.rol, m.instrumento, u.nombre, u.apellido, u.avatar
           FROM banda_miembros m JOIN usuarios u ON u.id = m.usuario_id
          WHERE m.banda_id = :b ORDER BY m.rol = \'admin\' DESC, m.created_at ASC'
    );
    $st->execute([':b' => $bandaId]);
    return $st->fetchAll();
}

/** null = no es miembro; 'admin' | 'miembro'. */
function rolEnBanda(int $uid, int $bandaId): ?string
{
    if (bandasWorkspaceDisponible()) {
        $st = getDB()->prepare("SELECT IF(m.rol='admin','admin',COALESCE(r.permiso,'miembro')) FROM banda_miembros m JOIN usuarios u ON u.id=m.usuario_id AND u.activo=1 LEFT JOIN musicmad_band_roles r ON r.banda_id=m.banda_id AND r.usuario_id=m.usuario_id WHERE m.banda_id=? AND m.usuario_id=?");
        $st->execute([$bandaId,$uid]);
        return $st->fetchColumn() ?: null;
    }
    $st = getDB()->prepare('SELECT rol FROM banda_miembros WHERE banda_id = :b AND usuario_id = :u');
    $st->execute([':b' => $bandaId, ':u' => $uid]);
    $r = $st->fetchColumn();
    return $r ?: null;
}

function misBandas(int $uid): array
{
    if (bandasWorkspaceDisponible()) {
        return array_values(array_filter(listarBandasWorkspace($uid), static fn ($band) => (bool)$band['soy_miembro']));
    }

    $st = getDB()->prepare(
        'SELECT b.id, b.nombre, m.rol FROM bandas b JOIN banda_miembros m ON m.banda_id = b.id
          WHERE m.usuario_id = :u ORDER BY b.nombre'
    );
    $st->execute([':u' => $uid]);
    return $st->fetchAll();
}

function unirseBanda(int $uid, int $bandaId, string $instrumento = ''): bool
{
    if (bandasWorkspaceDisponible()) {
        return unirseBandaWorkspace($uid, $bandaId, $instrumento);
    }
    $banda = obtenerBanda($bandaId);
    if (!$banda || rolEnBanda($uid, $bandaId) !== null) {
        return false;
    }
    getDB()->prepare('INSERT INTO banda_miembros (banda_id, usuario_id, rol, instrumento) VALUES (:b,:u,\'miembro\',:i)')
           ->execute([':b' => $bandaId, ':u' => $uid, ':i' => mb_substr(trim($instrumento), 0, 60)]);
    foreach (miembrosBanda($bandaId) as $m) {
        if ($m['rol'] === 'admin') {
            notificar((int) $m['usuario_id'], $uid, 'banda', null, $bandaId);
        }
    }
    return true;
}

/** Sale de la banda. Si era el último, la banda se borra; si era el único admin, el miembro más antiguo pasa a admin. */
function salirBanda(int $uid, int $bandaId): void
{
    if (bandasWorkspaceDisponible()) {
        salirBandaWorkspace($uid, $bandaId);
        return;
    }
    $db = getDB();
    if (rolEnBanda($uid, $bandaId) === null) {
        return;
    }
    $db->prepare('DELETE FROM banda_miembros WHERE banda_id = :b AND usuario_id = :u')->execute([':b' => $bandaId, ':u' => $uid]);

    $miembros = miembrosBanda($bandaId);
    if (!$miembros) {
        $db->prepare('DELETE FROM bandas WHERE id = :b')->execute([':b' => $bandaId]);
        return;
    }
    $hayAdmin = array_filter($miembros, fn ($m) => $m['rol'] === 'admin');
    if (!$hayAdmin) {
        $nuevo = (int) $miembros[0]['usuario_id'];
        $db->prepare('UPDATE banda_miembros SET rol = \'admin\' WHERE banda_id = :b AND usuario_id = :u')->execute([':b' => $bandaId, ':u' => $nuevo]);
    }
}

function expulsarMiembro(int $admin, int $bandaId, int $uid): void
{
    if (bandasWorkspaceDisponible()) {
        expulsarMiembroWorkspace($admin, $bandaId, $uid);
        return;
    }
    if ($admin === $uid || rolEnBanda($admin, $bandaId) !== 'admin') {
        return;
    }
    getDB()->prepare('DELETE FROM banda_miembros WHERE banda_id = :b AND usuario_id = :u')->execute([':b' => $bandaId, ':u' => $uid]);
}

function eliminarBanda(int $uid, int $bandaId, bool $esAdminSitio): void
{
    if (bandasWorkspaceDisponible()) {
        archivarBandaWorkspace($uid, $bandaId, false, $esAdminSitio);
        return;
    }
    if ($esAdminSitio || rolEnBanda($uid, $bandaId) === 'admin') {
        getDB()->prepare('DELETE FROM bandas WHERE id = :b')->execute([':b' => $bandaId]);
    }
}

// ── "Se busca" ──────────────────────────────────────────────

function crearBusqueda(int $uid, array $d): array
{
    if (matchingDisponible()) {
        return crearBusquedaMatching($uid, $d);
    }
    return crearBusquedaBase($uid, $d);
}
function crearBusquedaBase(int $uid, array $d): array
{
    $instr = trim(textoCampo($d, 'instrumento'));
    $genero = trim(textoCampo($d, 'genero'));
    $ciudad = trim(textoCampo($d, 'ciudad'));
    $desc = trim(textoCampo($d, 'descripcion'));
    $banda = idCampo($d, 'banda_id');

    if ($instr === '' || mb_strlen($instr) > 60) {
        return ['ok' => false, 'mensaje' => 'Indica qué instrumento o rol buscas (máx. 60 caracteres).'];
    }
    if (mb_strlen($ciudad) > 80 || mb_strlen($desc) > 500) {
        return ['ok' => false, 'mensaje' => 'Algún campo es demasiado largo.'];
    }
    if ($genero !== '' && !in_array($genero, GENEROS, true)) {
        $genero = '';
    }
    if ($banda > 0 && rolEnBanda($uid, $banda) !== 'admin') {
        return ['ok' => false,'mensaje' => 'Solo un administrador puede publicar por esta banda.'];
    }

    getDB()->prepare(
        'INSERT INTO busquedas (usuario_id, banda_id, instrumento, genero, ciudad, descripcion)
         VALUES (:u, :b, :i, :g, :c, :d)'
    )->execute([':u' => $uid, ':b' => $banda ?: null, ':i' => $instr, ':g' => $genero, ':c' => $ciudad, ':d' => $desc]);

    return ['ok' => true, 'mensaje' => '¡Publicado! Los músicos ya pueden contactarte.', 'id' => (int)getDB()->lastInsertId()];
}

function listarBusquedas(array $f = [], int $limite = 50): array
{
    $where = [];
    $params = [];
    if (social3Disponible()) {
        [$policy, $accessParams] = filtroAccesoContenido((int) sesion('usuario_id'), 'bu.usuario_id', 'wanted');
        $where[] = '(' . $policy . ')';
        $params += $accessParams;
    }
    if (!empty($f['autor'])) {
        $where[] = 'bu.usuario_id=:wanted_author';
        $params[':wanted_author'] = (int)$f['autor'];
    }
    if (empty($f['todas'])) {
        $where[] = 'bu.abierta = 1';
    }
    if (!empty($f['instrumento'])) {
        $where[] = 'bu.instrumento LIKE :i';
        $params[':i'] = '%' . addcslashes($f['instrumento'], '%_\\') . '%';
    }
    if (!empty($f['genero']) && in_array($f['genero'], GENEROS, true)) {
        $where[] = 'bu.genero = :g';
        $params[':g'] = $f['genero'];
    }
    if (!empty($f['ciudad'])) {
        $where[] = 'bu.ciudad LIKE :city';
        $params[':city'] = '%' . addcslashes(mb_substr((string)$f['ciudad'], 0, 80), '%_\\\\') . '%';
    }
    $levelFields = matchingDisponible() ? ",COALESCE(wl.nivel,'sin_definir') AS nivel" : "";
    $levelJoin = matchingDisponible() ? ' LEFT JOIN musicmad_wanted_levels wl ON wl.busqueda_id=bu.id ' : '';
    if (matchingDisponible() && !empty($f['nivel']) && $f['nivel'] !== 'sin_definir' && isset(NIVELES_MUSICALES[$f['nivel']])) {
        $where[] = 'wl.nivel=:level';
        $params[':level'] = $f['nivel'];
    }
    if (bandasWorkspaceDisponible()) {
        $where[] = '(bu.banda_id IS NULL OR NOT EXISTS(SELECT 1 FROM musicmad_band_settings bs WHERE bs.banda_id=bu.banda_id AND bs.archivada_en IS NOT NULL))';
    }
    if (!empty($f['banda'])) {
        $where[] = 'bu.banda_id = :b';
        $params[':b'] = (int) $f['banda'];
    }
    $sql = 'SELECT bu.*, u.nombre, u.apellido, u.avatar, b.nombre AS banda_nombre' . $levelFields . '
              FROM busquedas bu
              JOIN usuarios u ON u.id = bu.usuario_id AND u.activo = 1
              LEFT JOIN bandas b ON b.id = bu.banda_id' . $levelJoin
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY bu.abierta DESC, bu.created_at DESC LIMIT ' . (int) $limite;
    $st = getDB()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function cerrarBusqueda(int $id, int $uid, bool $esAdminSitio): void
{
    $sql = 'UPDATE busquedas SET abierta = 1 - abierta WHERE id = :id' . ($esAdminSitio ? '' : ' AND usuario_id = :u');
    $st = getDB()->prepare($sql);
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    if (!$esAdminSitio) {
        $st->bindValue(':u', $uid, PDO::PARAM_INT);
    }
    $st->execute();
}

function eliminarBusqueda(int $id, int $uid, bool $esAdminSitio): void
{
    $sql = 'DELETE FROM busquedas WHERE id = :id' . ($esAdminSitio ? '' : ' AND usuario_id = :u');
    $st = getDB()->prepare($sql);
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    if (!$esAdminSitio) {
        $st->bindValue(':u', $uid, PDO::PARAM_INT);
    }
    $st->execute();
}
