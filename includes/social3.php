<?php

// Funciones opcionales de MusicMad social v3. Las tablas se agregan con config/social3.sql.
require_once __DIR__ . '/access_policy.php';

const TABLAS_SOCIAL3 = ['publicacion_reacciones','publicaciones_guardadas','usuarios_bloqueados','perfiles_privacidad','solicitudes_seguimiento','respuestas_comentarios','comentarios_tiempo','reproducciones','playlists','playlist_pistas','push_subscriptions','eventos_musicales','evento_asistentes','clips_musicales','colaboraciones','colaboracion_stems','salas_escucha','salas_integrantes','hashtags','publicacion_hashtags','bandas_invitaciones','bandas_ensayos','retos_musicales','reto_participaciones','reto_votos','recomendaciones_musicos','perfiles_publicos','licencias_publicacion','mensajes_adjuntos','notificaciones_ext','auditoria_acciones','solicitudes_moderacion','tokens_api','email_tokens','sesiones_dispositivos','configuracion_social','api_rate_limits','ubicaciones_publicas','publicacion_audio_metadata','mensajes_banda','mensajes_banda_leidos','presencia_usuarios','autenticacion_2fa','notificaciones_dispositivos','migraciones_musicmad'];
function validarEstadoEsquemaSocial3(array $tables, bool $marker): bool
{
    if (!$marker && !array_intersect(['usuarios_bloqueados','perfiles_privacidad'], $tables)) {
        return false;
    }
    if (!$marker || array_diff(TABLAS_SOCIAL3, $tables)) {
        throw new RuntimeException('Actualización incompleta.');
    }
    return true;
}
function social3Disponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $db = getDB();
        $st = $db->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (' . implode(',', array_fill(0, count(TABLAS_SOCIAL3), '?')) . ')');
        $st->execute(TABLAS_SOCIAL3);
        $tables = $st->fetchAll(PDO::FETCH_COLUMN);
        $marker = false;
        if (in_array('migraciones_musicmad', $tables, true)) {
            $st = $db->prepare('SELECT 1 FROM migraciones_musicmad WHERE version=? LIMIT 1');
            $st->execute(['social3']);
            $marker = (bool)$st->fetchColumn();
        }
        return $ready = validarEstadoEsquemaSocial3($tables, $marker);
    } catch (Throwable $error) {
        error_log('Social schema unavailable: ' . get_class($error));
        http_response_code(503);
        exit('MusicMad necesita terminar una actualización. Vuelve a intentarlo más tarde.');
    }
}

function filtroSocial3Feed(int $viewer, string $alias = 'p'): array
{
    if (!social3Disponible()) {
        return ['', []];
    }
    return filtroAccesoContenido($viewer, $alias . '.usuario_id', 's3_' . $alias);
}

function estadoAccesoPerfilSocial3(int $viewer, int $owner): string
{
    if ($viewer < 1 || $owner < 1) {
        return 'bloqueado';
    }
    if ($viewer === $owner || !social3Disponible()) {
        return 'permitido';
    }
    $db = getDB();
    $block = $db->prepare('SELECT 1 FROM usuarios_bloqueados WHERE modo = \'bloquear\' AND ((usuario_id=:a AND objetivo_id=:b) OR (usuario_id=:b2 AND objetivo_id=:a2)) LIMIT 1');
    $block->execute([':a' => $viewer, ':b' => $owner, ':b2' => $owner, ':a2' => $viewer]);
    if ($block->fetchColumn()) {
        return 'bloqueado';
    }
    $st = $db->prepare('SELECT visibilidad FROM perfiles_privacidad WHERE usuario_id=:u LIMIT 1');
    $st->execute([':u' => $owner]);
    $visibility = $st->fetchColumn() ?: 'publico';
    $follows = $db->prepare('SELECT 1 FROM seguidores WHERE seguidor_id=:a AND seguido_id=:b LIMIT 1');
    $follows->execute([':a' => $viewer, ':b' => $owner]);
    if ($follows->fetchColumn()) {
        return 'permitido';
    }
    if ($visibility === 'publico') {
        return 'permitido';
    }
    return $visibility === 'privado' ? 'solicitar' : 'seguir';
}

function puedeVerPerfilSocial3(int $viewer, int $owner): bool
{
    return estadoAccesoPerfilSocial3($viewer, $owner) === 'permitido';
}

function estadoSolicitudSeguimiento(int $solicitante, int $destino): string
{
    if (!social3Disponible()) {
        return '';
    }
    $st = getDB()->prepare('SELECT estado FROM solicitudes_seguimiento WHERE solicitante_id=:s AND destino_id=:d ORDER BY id DESC LIMIT 1');
    $st->execute([':s' => $solicitante, ':d' => $destino]);
    return (string) ($st->fetchColumn() ?: '');
}

function solicitudesPendientesSeguimiento(int $usuarioId): array
{
    if (!social3Disponible()) {
        return [];
    }
    $st = getDB()->prepare('SELECT s.id,s.created_at,u.id AS solicitante_id,u.nombre,u.apellido,u.avatar,pm.instrumento FROM solicitudes_seguimiento s JOIN usuarios u ON u.id=s.solicitante_id AND u.activo=1 LEFT JOIN perfiles_musicales pm ON pm.usuario_id=u.id WHERE s.destino_id=:u AND s.estado=\'pendiente\' ORDER BY s.created_at DESC LIMIT 50');
    $st->execute([':u' => $usuarioId]);
    return $st->fetchAll();
}

function contarSolicitudesPendientesSeguimiento(int $usuarioId): int
{
    if (!social3Disponible()) {
        return 0;
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM solicitudes_seguimiento WHERE destino_id=:u AND estado=\'pendiente\'');
    $st->execute([':u' => $usuarioId]);
    return (int) $st->fetchColumn();
}

function responderSolicitudSeguimiento(int $usuarioId, int $solicitudId, bool $aceptar): bool
{
    if (!social3Disponible() || $solicitudId < 1) {
        return false;
    }
    $db = getDB();
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT solicitante_id FROM solicitudes_seguimiento WHERE id=:id AND destino_id=:u AND estado=\'pendiente\' FOR UPDATE');
        $st->execute([':id' => $solicitudId, ':u' => $usuarioId]);
        $solicitante = (int) $st->fetchColumn();
        if (!$solicitante) {
            $db->rollBack();
            return false;
        }
        if ($aceptar && estadoAccesoPerfilSocial3($usuarioId, $solicitante) === 'bloqueado') {
            $db->rollBack();
            return false;
        }
        $estado = $aceptar ? 'aceptada' : 'rechazada';
        $db->prepare('UPDATE solicitudes_seguimiento SET estado=:e,respondida_en=UTC_TIMESTAMP() WHERE id=:id AND destino_id=:u')
           ->execute([':e' => $estado, ':id' => $solicitudId, ':u' => $usuarioId]);
        if ($aceptar) {
            $db->prepare('INSERT IGNORE INTO seguidores (seguidor_id,seguido_id) VALUES (:s,:d)')
               ->execute([':s' => $solicitante, ':d' => $usuarioId]);
            notificar($solicitante, $usuarioId, 'seguidor');
            solicitarHitosArtista($usuarioId);
        }
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('No se pudo responder una solicitud de seguimiento.');
        return false;
    }
}

function puedeEnviarMensajeSocial3(int $sender, int $recipient): bool
{
    if (!social3Disponible()) {
        return true;
    }
    $db = getDB();
    $block = $db->prepare('SELECT 1 FROM usuarios_bloqueados WHERE modo=\'bloquear\' AND ((usuario_id=:a AND objetivo_id=:b) OR (usuario_id=:b2 AND objetivo_id=:a2)) LIMIT 1');
    $block->execute([':a' => $sender, ':b' => $recipient, ':b2' => $recipient, ':a2' => $sender]);
    if ($block->fetchColumn()) {
        return false;
    }
    $st = $db->prepare('SELECT permitir_mensajes FROM perfiles_privacidad WHERE usuario_id=:u LIMIT 1');
    $st->execute([':u' => $recipient]);
    $setting = $st->fetchColumn() ?: 'todos';
    if ($setting === 'nadie') {
        return false;
    }
    if ($setting === 'seguidores') {
        $follows = $db->prepare('SELECT 1 FROM seguidores WHERE seguidor_id=:a AND seguido_id=:b LIMIT 1');
        $follows->execute([':a' => $recipient, ':b' => $sender]);
        return (bool) $follows->fetchColumn();
    }
    return true;
}

function alternarGuardadoPublicacion(int $usuarioId, int $pubId): bool
{
    if (!social3Disponible() || !puedeVerPublicacionSocial3($usuarioId, $pubId)) {
        return false;
    }
    $db = getDB();
    $st = $db->prepare('DELETE FROM publicaciones_guardadas WHERE usuario_id=:u AND publicacion_id=:p');
    $st->execute([':u' => $usuarioId, ':p' => $pubId]);
    if ($st->rowCount() > 0) {
        return false;
    }
    $db->prepare('INSERT IGNORE INTO publicaciones_guardadas (usuario_id,publicacion_id) SELECT :u,id FROM publicaciones WHERE id=:p')
       ->execute([':u' => $usuarioId, ':p' => $pubId]);
    return true;
}

function puedeVerPublicacionSocial3(int $viewer, int $pubId): bool
{
    if (!social3Disponible()) {
        return false;
    }
    $st = getDB()->prepare('SELECT usuario_id FROM publicaciones WHERE id=:p LIMIT 1');
    $st->execute([':p' => $pubId]);
    $owner = (int) $st->fetchColumn();
    return $owner > 0 && puedeVerPerfilSocial3($viewer, $owner);
}

function guardarReaccionPublicacion(int $usuarioId, int $pubId, string $reaccion): bool
{
    $permitidas = ['fuego', 'guitarra', 'rock'];
    if (!social3Disponible() || !in_array($reaccion, $permitidas, true) || !puedeVerPublicacionSocial3($usuarioId, $pubId)) {
        return false;
    }
    $db = getDB();
    $st = $db->prepare('SELECT reaccion FROM publicacion_reacciones WHERE usuario_id=:u AND publicacion_id=:p');
    $st->execute([':u' => $usuarioId, ':p' => $pubId]);
    $actual = $st->fetchColumn();
    if ($actual === $reaccion) {
        $db->prepare('DELETE FROM publicacion_reacciones WHERE usuario_id=:u AND publicacion_id=:p')->execute([':u' => $usuarioId, ':p' => $pubId]);
        return true;
    }
    $db->prepare('INSERT INTO publicacion_reacciones (usuario_id,publicacion_id,reaccion) VALUES (:u,:p,:r) ON DUPLICATE KEY UPDATE reaccion=VALUES(reaccion),created_at=CURRENT_TIMESTAMP')
       ->execute([':u' => $usuarioId, ':p' => $pubId, ':r' => $reaccion]);
    return true;
}

function responderComentario(int $usuarioId, int $comentarioId, string $texto): bool
{
    $texto = trim($texto);
    if (!social3Disponible() || $comentarioId < 1 || $texto === '' || mb_strlen($texto) > 400) {
        return false;
    }
    [$policy,$params] = filtroAccesoContenido($usuarioId, 'c.usuario_id', 'reply_parent');
    $access = getDB()->prepare('SELECT c.publicacion_id FROM comentarios c JOIN usuarios u ON u.id=c.usuario_id AND u.activo=1 WHERE c.id=:c AND (' . $policy . ') LIMIT 1');
    $access->execute([':c' => $comentarioId] + $params);
    $pubId = (int) $access->fetchColumn();
    if ($pubId < 1 || !puedeVerPublicacionSocial3($usuarioId, $pubId)) {
        return false;
    }
    $st = getDB()->prepare('INSERT INTO respuestas_comentarios (comentario_id,usuario_id,texto) SELECT id,:u,:t FROM comentarios WHERE id=:c');
    $st->execute([':u' => $usuarioId, ':t' => $texto, ':c' => $comentarioId]);
    return $st->rowCount() > 0;
}

function respuestasComentarios(array $ids, int $limite = 3, ?int $viewer = null): array
{
    if (!social3Disponible() || !$ids) {
        return [];
    }
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0))), 0, 100);
    $limit = max(1, min(30, $limite));
    $result = [];
    [$policy,$params] = filtroAccesoContenido($viewer ?? (int)sesion('usuario_id'), 'r.usuario_id', 'initial_reply');
    $query = getDB()->prepare('SELECT r.id,r.comentario_id,r.usuario_id AS uid,r.texto,r.created_at,u.nombre,u.apellido,u.avatar FROM respuestas_comentarios r JOIN usuarios u ON u.id=r.usuario_id AND u.activo=1 WHERE r.comentario_id=:c AND (' . $policy . ') ORDER BY r.id DESC LIMIT :n');
    foreach ($params as $key => $value) {
        $query->bindValue($key, $value, PDO::PARAM_INT);
    }
    foreach ($ids as $id) {
        $query->bindValue(':c', $id, PDO::PARAM_INT);
        $query->bindValue(':n', $limit, PDO::PARAM_INT);
        $query->execute();
        $rows = $query->fetchAll();
        if ($rows) {
            $result[$id] = array_reverse($rows);
        }
    }
    return $result;
}

require_once __DIR__ . '/audio_moments.php';

function cambiarPrivacidadPerfil(int $usuarioId, string $visibilidad, string $mensajes): bool
{
    if (!social3Disponible() || !in_array($visibilidad, ['publico', 'seguidores', 'privado'], true) || !in_array($mensajes, ['todos', 'seguidores', 'nadie'], true)) {
        return false;
    }
    getDB()->prepare('INSERT INTO perfiles_privacidad (usuario_id,visibilidad,permitir_mensajes) VALUES (:u,:v,:m) ON DUPLICATE KEY UPDATE visibilidad=VALUES(visibilidad),permitir_mensajes=VALUES(permitir_mensajes)')
       ->execute([':u' => $usuarioId, ':v' => $visibilidad, ':m' => $mensajes]);
    return true;
}

function obtenerPrivacidadPerfil(int $usuarioId): array
{
    if (!social3Disponible()) {
        return ['visibilidad' => 'publico', 'permitir_mensajes' => 'todos'];
    }
    $st = getDB()->prepare('SELECT visibilidad,permitir_mensajes FROM perfiles_privacidad WHERE usuario_id=:u LIMIT 1');
    $st->execute([':u' => $usuarioId]);
    return $st->fetch() ?: ['visibilidad' => 'publico', 'permitir_mensajes' => 'todos'];
}

function listarRelacionesMusico(int $usuarioId, string $tipo, int $limite = 100, ?int $viewer = null): array
{
    if (!in_array($tipo, ['seguidores', 'siguiendo'], true)) {
        return [];
    }
    $limite = max(1, min(200, $limite));
    [$policy, $policyParams] = social3Disponible() ? filtroAccesoContenido($viewer ?? (int) sesion('usuario_id'), 'u.id', 'relations') : ['1=1', []];
    $join = $tipo === 'seguidores' ? 's.seguidor_id' : 's.seguido_id';
    $filtro = $tipo === 'seguidores' ? 's.seguido_id' : 's.seguidor_id';
    $st = getDB()->prepare('SELECT u.id,u.nombre,u.apellido,u.avatar,pm.instrumento,pm.ciudad,pm.generos FROM seguidores s JOIN usuarios u ON u.id=' . $join . ' AND u.activo=1 LEFT JOIN perfiles_musicales pm ON pm.usuario_id=u.id WHERE ' . $filtro . '=:u AND (' . $policy . ') ORDER BY s.created_at DESC LIMIT ' . $limite);
    $st->execute([':u' => $usuarioId] + $policyParams);
    return $st->fetchAll();
}

function cambiarBloqueoUsuario(int $usuarioId, int $objetivoId, string $modo): bool
{
    if (!social3Disponible() || $usuarioId === $objetivoId || !in_array($modo, ['bloquear', 'silenciar'], true)) {
        return false;
    }
    $db = getDB();
    $st = $db->prepare('SELECT modo FROM usuarios_bloqueados WHERE usuario_id=:u AND objetivo_id=:o LIMIT 1');
    $st->execute([':u' => $usuarioId, ':o' => $objetivoId]);
    $anterior = $st->fetchColumn();
    if ($anterior === $modo) {
        $db->prepare('DELETE FROM usuarios_bloqueados WHERE usuario_id=:u AND objetivo_id=:o')->execute([':u' => $usuarioId, ':o' => $objetivoId]);
        return false;
    }
    if ($anterior !== false) {
        $db->prepare('UPDATE usuarios_bloqueados SET modo=:m WHERE usuario_id=:u AND objetivo_id=:o')->execute([':m' => $modo, ':u' => $usuarioId, ':o' => $objetivoId]);
    } else {
        $db->prepare('INSERT IGNORE INTO usuarios_bloqueados (usuario_id,objetivo_id,modo) SELECT :u,id,:m FROM usuarios WHERE id=:o AND activo=1')
           ->execute([':u' => $usuarioId, ':o' => $objetivoId, ':m' => $modo]);
    }
    if ($modo === 'bloquear') {
        $db->prepare('DELETE FROM seguidores WHERE (seguidor_id=:a AND seguido_id=:b) OR (seguidor_id=:b2 AND seguido_id=:a2)')
           ->execute([':a' => $usuarioId, ':b' => $objetivoId, ':b2' => $objetivoId, ':a2' => $usuarioId]);
    }
    return true;
}

function usuariosIgnorados(int $usuarioId): array
{
    if (!social3Disponible()) {
        return [];
    }
    $st = getDB()->prepare('SELECT b.objetivo_id AS id,b.modo,b.created_at,u.nombre,u.apellido,u.avatar FROM usuarios_bloqueados b JOIN usuarios u ON u.id=b.objetivo_id WHERE b.usuario_id=:u ORDER BY b.created_at DESC LIMIT 200');
    $st->execute([':u' => $usuarioId]);
    return $st->fetchAll();
}

function guardarLicenciaPublicacion(int $usuarioId, int $pubId, string $licencia): bool
{
    $permitidas = ['todos_derechos', 'cc_by', 'cc_by_sa', 'cc_by_nc', 'cc0'];
    if (!social3Disponible() || !in_array($licencia, $permitidas, true) || $pubId < 1) {
        return false;
    }
    $st = getDB()->prepare('INSERT INTO licencias_publicacion (publicacion_id,licencia) SELECT id,:l FROM publicaciones WHERE id=:p AND usuario_id=:u ON DUPLICATE KEY UPDATE licencia=VALUES(licencia)');
    $st->execute([':l' => $licencia, ':p' => $pubId, ':u' => $usuarioId]);
    return $st->rowCount() > 0;
}

function licenciaDePublicacion(int $pubId): ?string
{
    if (!social3Disponible() || $pubId < 1) {
        return null;
    }
    $st = getDB()->prepare('SELECT licencia FROM licencias_publicacion WHERE publicacion_id=:p LIMIT 1');
    $st->execute([':p' => $pubId]);
    $licencia = $st->fetchColumn();
    return is_string($licencia) ? $licencia : null;
}

function etiquetaLicenciaPublicacion(?string $licencia): string
{
    return match ($licencia) {
        'cc_by' => 'Creative Commons BY',
        'cc_by_sa' => 'Creative Commons BY-SA',
        'cc_by_nc' => 'Creative Commons BY-NC',
        'cc0' => 'CC0 · dedicar al dominio público',
        'todos_derechos' => 'Todos los derechos reservados',
        default => '',
    };
}

function reportarContenidoSocial3(int $reportante, int $pubId, string $motivo, string $detalle = ''): array
{
    $motivos = ['spam', 'acoso', 'suplantacion', 'derechos', 'contenido', 'otro'];
    $detalle = trim($detalle);
    if (!social3Disponible()) {
        return ['ok' => false, 'mensaje' => 'Importa config/social3.sql para habilitar reportes.'];
    }
    if ($pubId < 1 || !in_array($motivo, $motivos, true) || mb_strlen($detalle) > 500 || !puedeVerPublicacionSocial3($reportante, $pubId)) {
        return ['ok' => false, 'mensaje' => 'No se pudo enviar el reporte. Revisa la publicación y el motivo.'];
    }
    $db = getDB();
    $st = $db->prepare('SELECT usuario_id FROM publicaciones WHERE id=:p LIMIT 1');
    $st->execute([':p' => $pubId]);
    $autor = (int) $st->fetchColumn();
    if (!$autor || $autor === $reportante) {
        return ['ok' => false, 'mensaje' => 'No puedes reportar tu propia publicación.'];
    }
    $limite = $db->prepare('SELECT COUNT(*) FROM solicitudes_moderacion WHERE reportante_id=:u AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 DAY');
    $limite->execute([':u' => $reportante]);
    if ((int) $limite->fetchColumn() >= 20) {
        return ['ok' => false, 'mensaje' => 'Llegaste al límite diario de reportes.'];
    }
    $duplicado = $db->prepare('SELECT 1 FROM solicitudes_moderacion WHERE reportante_id=:u AND publicacion_id=:p AND estado IN (\'pendiente\',\'revisando\') LIMIT 1');
    $duplicado->execute([':u' => $reportante, ':p' => $pubId]);
    if ($duplicado->fetchColumn()) {
        return ['ok' => false, 'mensaje' => 'Ya enviaste un reporte pendiente para esta publicación.'];
    }
    $db->prepare('INSERT INTO solicitudes_moderacion (reportante_id,usuario_reportado_id,publicacion_id,motivo,detalle) VALUES (:r,:a,:p,:m,:d)')
       ->execute([':r' => $reportante, ':a' => $autor, ':p' => $pubId, ':m' => $motivo, ':d' => $detalle]);
    return ['ok' => true, 'mensaje' => 'Reporte enviado. El equipo de MusicMad lo revisará.'];
}

function reportarUsuarioSocial3(int $reportante, int $objetivo, string $motivo, string $detalle = ''): array
{
    $motivos = ['spam', 'acoso', 'suplantacion', 'derechos', 'contenido', 'otro'];
    $detalle = trim($detalle);
    if (!social3Disponible() || $objetivo < 1 || $objetivo === $reportante || !in_array($motivo, $motivos, true) || mb_strlen($detalle) > 500) {
        return ['ok' => false, 'mensaje' => 'No se pudo enviar el reporte. Revisa el motivo y vuelve a intentar.'];
    }
    $db = getDB();
    $limite = $db->prepare('SELECT COUNT(*) FROM solicitudes_moderacion WHERE reportante_id=:u AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 DAY');
    $limite->execute([':u' => $reportante]);
    if ((int) $limite->fetchColumn() >= 20) {
        return ['ok' => false, 'mensaje' => 'Llegaste al límite diario de reportes.'];
    }
    $duplicate = $db->prepare('SELECT 1 FROM solicitudes_moderacion WHERE reportante_id=:r AND usuario_reportado_id=:u AND publicacion_id IS NULL AND estado IN (\'pendiente\',\'revisando\') LIMIT 1');
    $duplicate->execute([':r' => $reportante, ':u' => $objetivo]);
    if ($duplicate->fetchColumn()) {
        return ['ok' => false, 'mensaje' => 'Ya tienes un reporte pendiente de este perfil.'];
    }
    $db->prepare('INSERT INTO solicitudes_moderacion (reportante_id,usuario_reportado_id,motivo,detalle) SELECT :r,id,:m,:d FROM usuarios WHERE id=:u AND activo=1')
       ->execute([':r' => $reportante, ':m' => $motivo, ':d' => $detalle, ':u' => $objetivo]);
    return $db->lastInsertId() ? ['ok' => true, 'mensaje' => 'Reporte enviado. El equipo de MusicMad lo revisará.'] : ['ok' => false, 'mensaje' => 'No se encontró ese perfil.'];
}

function listarReportesSocial3(int $limite = 100): array
{
    if (!social3Disponible()) {
        return [];
    }
    $limite = max(1, min(200, $limite));
    $st = getDB()->query('SELECT r.id,r.publicacion_id,r.usuario_reportado_id,r.motivo,r.detalle,r.estado,r.created_at,CONCAT(ru.nombre,\' \',ru.apellido) AS reportante,CONCAT(au.nombre,\' \',au.apellido) AS autor,p.titulo,p.texto,p.tipo FROM solicitudes_moderacion r JOIN usuarios ru ON ru.id=r.reportante_id LEFT JOIN usuarios au ON au.id=r.usuario_reportado_id LEFT JOIN publicaciones p ON p.id=r.publicacion_id WHERE r.estado IN (\'pendiente\',\'revisando\') ORDER BY r.created_at ASC LIMIT ' . $limite);
    return $st->fetchAll();
}

function contarReportesSocial3(): int
{
    if (!social3Disponible()) {
        return 0;
    }
    return (int) getDB()->query("SELECT COUNT(*) FROM solicitudes_moderacion WHERE estado IN ('pendiente','revisando')")->fetchColumn();
}

function crearPlaylistSocial3(int $usuarioId, string $nombre, string $descripcion, bool $publica): array
{
    $nombre = trim($nombre);
    $descripcion = trim($descripcion);
    if (!social3Disponible()) {
        return ['ok' => false, 'mensaje' => 'Importa config/social3.sql para activar playlists.'];
    }
    if ($nombre === '' || mb_strlen($nombre) > 80 || mb_strlen($descripcion) > 300) {
        return ['ok' => false, 'mensaje' => 'Pon un nombre de hasta 80 caracteres y una descripción de hasta 300.'];
    }
    $count = getDB()->prepare('SELECT COUNT(*) FROM playlists WHERE usuario_id=:u');
    $count->execute([':u' => $usuarioId]);
    if ((int) $count->fetchColumn() >= 50) {
        return ['ok' => false, 'mensaje' => 'Puedes tener hasta 50 playlists.'];
    }
    $st = getDB()->prepare('INSERT INTO playlists (usuario_id,nombre,descripcion,publica) VALUES (:u,:n,:d,:p)');
    $st->execute([':u' => $usuarioId, ':n' => $nombre, ':d' => $descripcion, ':p' => $publica ? 1 : 0]);
    return ['ok' => true, 'id' => (int) getDB()->lastInsertId(), 'mensaje' => 'Playlist creada.'];
}

function playlistsDelUsuarioSocial3(int $usuarioId, int $limite = 100): array
{
    if (!social3Disponible()) {
        return [];
    }
    $limite = max(1, min(150, $limite));
    $st = getDB()->prepare('SELECT pl.id,pl.nombre,pl.descripcion,pl.publica,pl.updated_at,(SELECT COUNT(*) FROM playlist_pistas pp WHERE pp.playlist_id=pl.id) AS pistas FROM playlists pl WHERE pl.usuario_id=:u ORDER BY pl.updated_at DESC LIMIT ' . $limite);
    $st->execute([':u' => $usuarioId]);
    return $st->fetchAll();
}

function playlistsPublicasSocial3(int $excluirUsuario, int $limite = 20): array
{
    if (!social3Disponible()) {
        return [];
    }
    $limite = max(1, min(40, $limite));
    [$policy, $policyParams] = filtroAccesoContenido($excluirUsuario, 'pl.usuario_id', 'public_lists');
    $st = getDB()->prepare('SELECT pl.id,pl.nombre,pl.descripcion,pl.usuario_id,u.nombre AS autor,u.apellido AS autor_apellido,(SELECT COUNT(*) FROM playlist_pistas pp WHERE pp.playlist_id=pl.id) AS pistas FROM playlists pl JOIN usuarios u ON u.id=pl.usuario_id AND u.activo=1 WHERE pl.publica=1 AND pl.usuario_id<>:u AND (' . $policy . ') ORDER BY pl.updated_at DESC LIMIT ' . $limite);
    $st->execute([':u' => $excluirUsuario] + $policyParams);
    return $st->fetchAll();
}

function playlistsDePerfilSocial3(int $usuarioId, int $viewer): array
{
    if (!social3Disponible()) {
        return [];
    }
    $st = getDB()->prepare('SELECT pl.id,pl.nombre,pl.descripcion,pl.publica,pl.updated_at FROM playlists pl WHERE pl.usuario_id=:u AND (pl.publica=1 OR pl.usuario_id=:viewer) ORDER BY pl.updated_at DESC LIMIT 30');
    $st->execute([':u' => $usuarioId, ':viewer' => $viewer]);
    return $st->fetchAll();
}

function obtenerPlaylistSocial3(int $playlistId, int $viewer): ?array
{
    if (!social3Disponible() || $playlistId < 1) {
        return null;
    }
    [$policy, $policyParams] = filtroAccesoContenido($viewer, 'pl.usuario_id', 'list_owner', false);
    $team = playlistsEquipoDisponible() && funcionalidadMusicMad('playlists_equipo', $viewer) ? " OR (pl.colaborativa=1 AND EXISTS(SELECT 1 FROM musicmad_playlist_members m WHERE m.playlist_id=pl.id AND m.usuario_id=:member AND m.estado='aceptada'))" : '';
    if ($team !== '') {
        $policyParams[':member'] = $viewer;
    }
    $st = getDB()->prepare('SELECT pl.*,u.nombre AS autor,u.apellido AS autor_apellido,u.avatar AS autor_avatar FROM playlists pl JOIN usuarios u ON u.id=pl.usuario_id AND u.activo=1 WHERE pl.id=:id AND (pl.publica=1 OR pl.usuario_id=:viewer' . $team . ') AND (' . $policy . ') LIMIT 1');
    $st->execute([':id' => $playlistId, ':viewer' => $viewer] + $policyParams);
    return $st->fetch() ?: null;
}

function pistasPlaylistSocial3(int $playlistId, int $viewer): array
{
    if (!social3Disponible() || $playlistId < 1) {
        return [];
    }
    if (!obtenerPlaylistSocial3($playlistId, $viewer)) {
        return [];
    }
    [$policy, $params] = filtroAccesoContenido($viewer, 'p.usuario_id', 'playlist_track');
    $sql = 'SELECT p.id,p.usuario_id,p.titulo,p.texto,p.genero,p.created_at,u.nombre,u.apellido,u.avatar,pa.archivo AS audio,pp.orden,pp.agregado_por FROM playlist_pistas pp JOIN publicaciones p ON p.id=pp.publicacion_id AND p.tipo IN (\'cancion\',\'cover\',\'demo\') JOIN publicacion_audio pa ON pa.publicacion_id=p.id JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 WHERE pp.playlist_id=:id AND (' . $policy . ') ORDER BY pp.orden,pp.created_at';
    $st = getDB()->prepare($sql);
    $st->execute([':id' => $playlistId] + $params);
    return $st->fetchAll();
}

function agregarPistaPlaylistSocial3(int $usuarioId, int $playlistId, int $pubId): bool
{
    if (playlistsEquipoDisponible()) {
        return cambiarPistaEquipoPlaylist($usuarioId, $playlistId, $pubId, true);
    }
    if (!social3Disponible() || $playlistId < 1 || $pubId < 1) {
        return false;
    }
    $db = getDB();
    $owner = $db->prepare('SELECT id FROM playlists WHERE id=:p AND usuario_id=:u LIMIT 1');
    $owner->execute([':p' => $playlistId, ':u' => $usuarioId]);
    if (!$owner->fetchColumn() || !puedeVerPublicacionSocial3($usuarioId, $pubId)) {
        return false;
    }
    $track = $db->prepare('SELECT p.id FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.id=:p AND p.tipo IN (\'cancion\',\'cover\',\'demo\') LIMIT 1');
    $track->execute([':p' => $pubId]);
    if (!$track->fetchColumn()) {
        return false;
    }
    $count = $db->prepare('SELECT COUNT(*) FROM playlist_pistas WHERE playlist_id=:p');
    $count->execute([':p' => $playlistId]);
    if ((int) $count->fetchColumn() >= 300) {
        return false;
    }
    $order = $db->prepare('SELECT COALESCE(MAX(orden),0)+1 FROM playlist_pistas WHERE playlist_id=:p');
    $order->execute([':p' => $playlistId]);
    $st = $db->prepare('INSERT IGNORE INTO playlist_pistas (playlist_id,publicacion_id,agregado_por,orden) VALUES (:l,:p,:u,:o)');
    $st->execute([':l' => $playlistId, ':p' => $pubId, ':u' => $usuarioId, ':o' => (int) $order->fetchColumn()]);
    return $st->rowCount() === 1;
}

function quitarPistaPlaylistSocial3(int $usuarioId, int $playlistId, int $pubId): bool
{
    if (playlistsEquipoDisponible()) {
        return cambiarPistaEquipoPlaylist($usuarioId, $playlistId, $pubId, false);
    }
    if (!social3Disponible()) {
        return false;
    }
    $st = getDB()->prepare('DELETE pp FROM playlist_pistas pp JOIN playlists pl ON pl.id=pp.playlist_id WHERE pp.playlist_id=:l AND pp.publicacion_id=:p AND pl.usuario_id=:u');
    $st->execute([':l' => $playlistId, ':p' => $pubId, ':u' => $usuarioId]);
    return $st->rowCount() === 1;
}

function eliminarPlaylistSocial3(int $usuarioId, int $playlistId): bool
{
    if (!social3Disponible()) {
        return false;
    }
    $st = getDB()->prepare('DELETE FROM playlists WHERE id=:p AND usuario_id=:u');
    $st->execute([':p' => $playlistId, ':u' => $usuarioId]);
    return $st->rowCount() === 1;
}

function crearEventoSocial3(int $usuarioId, array $datos): array
{
    if (eventosWorkspaceDisponible()) {
        try {
            return ['ok' => true,'id' => guardarEventoWorkspace($usuarioId, $datos),'mensaje' => 'Evento publicado.'];
        } catch (LogicException|OutOfBoundsException|OverflowException $error) {
            return ['ok' => false,'mensaje' => $error->getMessage()];
        }
    }

    if (!social3Disponible()) {
        return ['ok' => false, 'mensaje' => 'Importa config/social3.sql para activar eventos.'];
    }
    $titulo = trim(textoCampo($datos, 'titulo'));
    $descripcion = trim(textoCampo($datos, 'descripcion'));
    $sede = trim(textoCampo($datos, 'sede'));
    $ciudad = trim(textoCampo($datos, 'ciudad'));
    $fechaRaw = textoCampo($datos, 'inicio');
    if ($titulo === '' || mb_strlen($titulo) > 120 || mb_strlen($descripcion) > 1000 || mb_strlen($sede) > 160 || mb_strlen($ciudad) > 80) {
        return ['ok' => false, 'mensaje' => 'Revisa el título, descripción, sede y ciudad del evento.'];
    }
    $zona = new DateTimeZone('America/Mexico_City');
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $fechaRaw, $zona);
    $errores = DateTimeImmutable::getLastErrors();
    if (!$fecha || ($errores && ($errores['warning_count'] || $errores['error_count'])) || $fecha <= new DateTimeImmutable('now', $zona) || $fecha > (new DateTimeImmutable('now', $zona))->modify('+2 years')) {
        return ['ok' => false, 'mensaje' => 'Elige una fecha válida dentro de los próximos dos años.'];
    }
    $st = getDB()->prepare('INSERT INTO eventos_musicales (creador_id,titulo,descripcion,sede,ciudad,inicio) VALUES (:u,:t,:d,:s,:c,:i)');
    $st->execute([':u' => $usuarioId, ':t' => $titulo, ':d' => $descripcion, ':s' => $sede, ':c' => $ciudad, ':i' => $fecha->format('Y-m-d H:i:s')]);
    return ['ok' => true, 'id' => (int) getDB()->lastInsertId(), 'mensaje' => 'Evento publicado.'];
}

function eventosSocial3(int $viewer, int $limite = 60): array
{
    if (eventosWorkspaceDisponible()) {
        return listarEventosWorkspace($viewer, [], $limite);
    }
    if (!social3Disponible()) {
        return [];
    }
    $limite = max(1, min(100, $limite));
    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');
    [$policy, $params] = filtroAccesoContenido($viewer, 'e.creador_id', 'events');
    $sql = 'SELECT e.id,e.creador_id,e.titulo,e.descripcion,e.sede,e.ciudad,e.inicio,e.created_at,u.nombre,u.apellido,u.avatar,(SELECT COUNT(*) FROM evento_asistentes ea WHERE ea.evento_id=e.id AND ea.estado=\'asistire\') AS asistentes,(SELECT COUNT(*) FROM evento_asistentes ei WHERE ei.evento_id=e.id AND ei.estado=\'interesado\') AS interesados,(SELECT estado FROM evento_asistentes ev WHERE ev.evento_id=e.id AND ev.usuario_id=:viewer2 LIMIT 1) AS mi_estado FROM eventos_musicales e JOIN usuarios u ON u.id=e.creador_id AND u.activo=1 WHERE e.estado=\'publicado\' AND e.inicio>=:now AND (' . $policy . ') ORDER BY e.inicio LIMIT ' . $limite;
    $st = getDB()->prepare($sql);
    $st->execute([':viewer2' => $viewer, ':now' => $now] + $params);
    return $st->fetchAll();
}

function actualizarRsvpEventoSocial3(int $usuarioId, int $eventoId, string $estado): bool
{
    if (eventosWorkspaceDisponible()) {
        try {
            responderEventoWorkspace($usuarioId, $eventoId, $estado, false);
            return true;
        } catch (LogicException|OutOfBoundsException|OverflowException $error) {
            return false;
        }
    }
    if (!social3Disponible() || $eventoId < 1 || !in_array($estado, ['asistire', 'interesado'], true)) {
        return false;
    }
    $access = getDB()->prepare('SELECT creador_id FROM eventos_musicales WHERE id=:id AND estado=\'publicado\' AND inicio>=:now LIMIT 1');
    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');
    $access->execute([':id' => $eventoId, ':now' => $now]);
    $creador = (int) $access->fetchColumn();
    if (!$creador || !puedeVerPerfilSocial3($usuarioId, $creador)) {
        return false;
    }
    $st = getDB()->prepare('INSERT INTO evento_asistentes (evento_id,usuario_id,estado) VALUES (:e,:u,:s) ON DUPLICATE KEY UPDATE estado=VALUES(estado)');
    $st->execute([':e' => $eventoId, ':u' => $usuarioId, ':s' => $estado]);
    return true;
}

function quitarRsvpEventoSocial3(int $usuarioId, int $eventoId): bool
{
    if (eventosWorkspaceDisponible()) {
        try {
            responderEventoWorkspace($usuarioId, $eventoId, 'quitar', false);
            return true;
        } catch (LogicException|OutOfBoundsException|OverflowException $error) {
            return false;
        }
    }
    if (!social3Disponible()) {
        return false;
    }
    $st = getDB()->prepare('DELETE FROM evento_asistentes WHERE evento_id=:e AND usuario_id=:u');
    $st->execute([':e' => $eventoId, ':u' => $usuarioId]);
    return $st->rowCount() === 1;
}

function cancelarEventoSocial3(int $usuarioId, int $eventoId): bool
{
    if (eventosWorkspaceDisponible()) {
        try {
            cancelarEventoWorkspace($usuarioId, $eventoId);
            return true;
        } catch (LogicException|OutOfBoundsException|OverflowException $error) {
            return false;
        }
    }
    if (!social3Disponible()) {
        return false;
    }
    $st = getDB()->prepare('UPDATE eventos_musicales SET estado=\'cancelado\' WHERE id=:e AND creador_id=:u AND estado=\'publicado\'');
    $st->execute([':e' => $eventoId, ':u' => $usuarioId]);
    return $st->rowCount() === 1;
}

function resolverReporteSocial3(int $reporteId, int $adminId, string $decision): bool
{
    if (!social3Disponible() || $reporteId < 1 || !in_array($decision, ['retirar','revisar','descartar'], true)) {
        return false;
    }
    return transaccionModeracion($adminId, function (PDO $db) use ($reporteId, $adminId, $decision) {
        $st = $db->prepare("SELECT publicacion_id,estado FROM solicitudes_moderacion WHERE id=? AND estado IN ('pendiente','revisando') FOR UPDATE");
        $st->execute([$reporteId]);
        $r = $st->fetch();
        if (!$r || ($decision === 'retirar' && !(int)$r['publicacion_id'])) {
            return false;
        }
        if ($decision === 'retirar') {
            eliminarPublicacion((int)$r['publicacion_id'], $adminId, true);
        }
        $db->prepare('UPDATE solicitudes_moderacion SET estado=?,revisado_por=?,revisado_en=UTC_TIMESTAMP() WHERE id=?')->execute([$decision === 'descartar' ? 'descartado' : 'resuelto',$adminId,$reporteId]);
        auditarCuenta($adminId, 'reporte_resuelto', 'reporte', $reporteId);
        return true;
    });
}



require_once __DIR__ . '/artist_statistics.php';


require_once __DIR__ . '/challenges.php';


require_once __DIR__ . '/endorsements.php';
