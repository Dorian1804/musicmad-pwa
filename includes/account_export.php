<?php

/** Static query/column allowlists: new schema fields cannot enter an export automatically. */
function coleccionesExportacionCuenta(): array
{
    $own = [];
    $add = static function (string $table, string $columns, string $where = 'usuario_id=?') use (&$own) {
        $own[$table] = ['SELECT ' . $columns . ' FROM ' . $table . ' WHERE ' . $where,substr_count($where, '?')];
    };
    $add('usuarios', 'id,nombre,apellido,email,avatar,bio,color_perfil,rol,activo,created_at', 'id=?');
    $add('perfiles_musicales', 'instrumento,generos,ciudad');
    $add('perfiles_privacidad', 'visibilidad,permitir_mensajes,updated_at');
    $add('perfiles_publicos', 'slug,verificado,licencia_predeterminada,redes,updated_at');
    $add('fondos_perfil', 'archivo,updated_at');
    $add('publicaciones', 'id,tipo,titulo,texto,enlace,genero,created_at');
    foreach (['audio','video','partitura'] as $type) {
        $own['publicacion_' . $type] = ['SELECT m.publicacion_id,m.archivo,m.bytes FROM publicacion_' . $type . ' m JOIN publicaciones p ON p.id=m.publicacion_id WHERE p.usuario_id=?',1];
    }
    $own['licencias_publicacion'] = ['SELECT l.publicacion_id,l.licencia FROM licencias_publicacion l JOIN publicaciones p ON p.id=l.publicacion_id WHERE p.usuario_id=?',1];
    $add('aplausos', 'publicacion_id,created_at');
    $add('publicacion_reacciones', 'publicacion_id,reaccion,created_at');
    $add('publicaciones_guardadas', 'publicacion_id,created_at');
    $add('comentarios', 'id,publicacion_id,texto,created_at');
    $add('respuestas_comentarios', 'id,comentario_id,texto,created_at');
    $add('comentarios_tiempo', 'id,publicacion_id,segundo,texto,created_at');
    $add('reposts', 'id,publicacion_id,created_at');
    $add('seguidores', 'seguidor_id,seguido_id,created_at', 'seguidor_id=? OR seguido_id=?');
    $add('solicitudes_seguimiento', 'id,solicitante_id,destino_id,estado,created_at,respondida_en', 'solicitante_id=? OR destino_id=?');
    $add('musicmad_user_locale', 'locale');
    $add('musicmad_push_quiet', 'activo,desde,hasta,zona');
    $add('usuarios_bloqueados', 'objetivo_id,modo,created_at');
    $add('mensajes', 'id,de_id,para_id,texto,leido,created_at', 'de_id=? OR para_id=?');
    $own['mensajes_adjuntos'] = ['SELECT a.mensaje_id,a.publicacion_id,a.tipo FROM mensajes_adjuntos a JOIN mensajes m ON m.id=a.mensaje_id WHERE m.de_id=? OR m.para_id=?',2];
    $add('notificaciones', 'id,actor_id,tipo,publicacion_id,banda_id,leida,created_at');
    $add('notificaciones_ext', 'id,actor_id,tipo,referencia_id,leida_en,created_at');
    $add('bandas', 'id,nombre,descripcion,genero,ciudad,created_at', 'creador_id=?');
    $add('banda_miembros', 'banda_id,rol,instrumento,created_at');
    $add('mensajes_banda', 'id,banda_id,texto,created_at');
    $add('musicmad_band_invites', 'banda_id,invitado_id,invitado_por,estado,created_at,expira_en,respondida_en', 'invitado_id=? OR invitado_por=?');
    $add('bandas_ensayos', 'id,banda_id,titulo,lugar,inicio,notas', 'creado_por=?');
    $add('musicmad_band_rsvp', 'ensayo_id,estado,aviso');
    $add('busquedas', 'id,banda_id,instrumento,genero,ciudad,descripcion,abierta,created_at');
    $add('instrumentos_usados', 'id,titulo,descripcion,categoria,condicion,precio,ciudad,foto,estado,created_at');
    $own['instrumentos_fotos'] = ['SELECT f.id,f.instrumento_id,f.archivo,f.orden FROM instrumentos_fotos f JOIN instrumentos_usados i ON i.id=f.instrumento_id WHERE i.usuario_id=?',1];
    $add('instrumentos_favoritos', 'instrumento_id,created_at');
    $add('instrumentos_reportes', 'instrumento_id,motivo,detalle,estado,created_at,revisado_en');
    $add('solicitudes_moderacion', 'id,usuario_reportado_id,publicacion_id,motivo,detalle,estado,created_at,revisado_en', 'reportante_id=?');
    $add('playlists', 'id,nombre,descripcion,publica,colaborativa,created_at,updated_at');
    $own['playlist_pistas'] = ['SELECT p.playlist_id,p.publicacion_id,p.orden,p.agregado_por,p.created_at FROM playlist_pistas p JOIN playlists l ON l.id=p.playlist_id WHERE l.usuario_id=? OR p.agregado_por=?',2];
    $add('eventos_musicales', 'id,titulo,descripcion,sede,ciudad,inicio,estado,created_at', 'creador_id=?');
    $add('evento_asistentes', 'evento_id,estado,created_at');
    $own['musicmad_event_details'] = ['SELECT d.evento_id,d.inicio_utc,d.fin_utc,d.zona,d.revision FROM musicmad_event_details d JOIN eventos_musicales e ON e.id=d.evento_id WHERE e.creador_id=?',1];
    $add('clips_musicales', 'id,archivo,tipo,duracion_segundos,texto,expira_en,created_at');
    $add('colaboraciones', 'id,titulo,descripcion,pista_base_id,estado,created_at');
    $add('colaboracion_stems', 'id,colaboracion_id,archivo,titulo,created_at');
    $add('salas_escucha', 'id,publicacion_id,nombre,estado,posicion_segundos,created_at', 'anfitrion_id=?');
    $add('salas_integrantes', 'sala_id,visto_en');
    $add('reto_participaciones', 'reto_id,publicacion_id,created_at');
    $add('reto_votos', 'reto_id,participante_id,created_at', 'votante_id=?');
    $add('recomendaciones_musicos', 'autor_id,musico_id,texto,created_at', 'autor_id=? OR musico_id=?');
    $add('musicmad_onboarding', 'paso,revision,completado_en');
    $add('musicmad_onboarding_required', 'paso,revision,notificaciones,instalacion,completado_en');
    $own['musicmad_playlist_members'] = ['SELECT m.playlist_id,m.usuario_id,m.estado,m.invitado_en,m.aceptado_en FROM musicmad_playlist_members m JOIN playlists p ON p.id=m.playlist_id WHERE m.usuario_id=? OR p.usuario_id=?',2];
    $own['musicmad_repost_notes'] = ['SELECT n.repost_id,r.publicacion_id,n.texto,n.updated_at FROM musicmad_repost_notes n JOIN reposts r ON r.id=n.repost_id WHERE r.usuario_id=?',1];
    $own['musicmad_track_feedback'] = ['SELECT f.publicacion_id,f.actualizado_en FROM musicmad_track_feedback f JOIN publicaciones p ON p.id=f.publicacion_id WHERE p.usuario_id=?',1];
    $add('musicmad_endorsement_hidden', 'autor_id,ocultada_en', 'musico_id=?');
    $add('musicmad_confirmed_plays', 'publicacion_id,fecha,confirmada_en');
    $add('musicmad_artist_awards', 'codigo,obtenida_en');
    $add('reproducciones', 'publicacion_id,fecha,created_at');
    $add('sesiones_dispositivos', 'etiqueta,ultimo_uso,revocada_en,created_at');
    $add('musicmad_chat_preferences', 'mostrar_presencia,mostrar_escribiendo,confirmar_lectura');
    $add('musicmad_push_preferences', 'mensajes,aplausos,comentarios,seguidores,bandas,eventos');
    $add('musicmad_musician_levels', 'nivel');
    $add('ubicaciones_publicas', 'latitud,longitud,visible,updated_at');
    $add('auditoria_acciones', 'accion,objeto,objeto_id,created_at');
    $add('musicmad_account_deletions', 'solicitada_en,ejecutar_en,estado');
    $add('pedidos', 'id,direccion,telefono,notas,total,estado,created_at');
    $own['detalle_pedido'] = ['SELECT d.id,d.pedido_id,d.producto_id,d.nombre,d.precio_unitario,d.cantidad FROM detalle_pedido d JOIN pedidos p ON p.id=d.pedido_id WHERE p.usuario_id=?',1];
    $own['publicacion_medios'] = ['SELECT m.publicacion_id,m.sha256,m.duracion,m.mime,m.ancho,m.alto,m.codec FROM publicacion_medios m JOIN publicaciones p ON p.id=m.publicacion_id WHERE p.usuario_id=?',1];
    $own['musicmad_band_posts'] = ['SELECT m.publicacion_id,m.banda_id FROM musicmad_band_posts m JOIN publicaciones p ON p.id=m.publicacion_id WHERE p.usuario_id=?',1];
    $add('musicmad_event_preferences', 'evento_id,aviso');
    $add('musicmad_location_consent', 'consentimiento_en');
    $add('autenticacion_2fa', 'habilitada');
    $add('musicmad_email_verified', 'verificado_en');
    $add('musicmad_email_enrollment', 'iniciada_en,vence_en');
    $add('musicmad_verification_review', 'estado,slug_revisado,evidencia,motivo,revisado_en');
    $add('musicmad_epk_options', 'habilitado,color,updated_at');
    $add('musicmad_epk_featured', 'publicacion_id,orden');
    $add('musicmad_epk_embed', 'permitido');
    $add('musicmad_room_messages', 'id,sala_id,texto,created_at');
    $own['musicmad_room_state'] = ['SELECT r.sala_id,r.modo,r.reproduccion,r.posicion,r.revision,r.actualizada_utc,r.expira_en FROM musicmad_room_state r JOIN salas_escucha s ON s.id=r.sala_id WHERE s.anfitrion_id=?',1];
    $own['musicmad_timed_comment_ms'] = ['SELECT m.comentario_id,m.momento_ms FROM musicmad_timed_comment_ms m JOIN comentarios_tiempo c ON c.id=m.comentario_id WHERE c.usuario_id=?',1];
    $own['publicacion_audio_metadata'] = ['SELECT w.publicacion_id,w.duracion_ms,w.waveform,w.procesamiento,w.updated_at FROM publicacion_audio_metadata w JOIN publicaciones p ON p.id=w.publicacion_id WHERE p.usuario_id=?',1];
    $own['musicmad_collab_details'] = ['SELECT d.colaboracion_id,d.archivada,d.final_publicacion_id FROM musicmad_collab_details d JOIN colaboraciones c ON c.id=d.colaboracion_id WHERE c.usuario_id=?',1];
    $own['musicmad_stem_tracks'] = ['SELECT t.stem_id,t.publicacion_id,t.version_de,t.offset_ms,t.volumen FROM musicmad_stem_tracks t JOIN colaboracion_stems s ON s.id=t.stem_id WHERE s.usuario_id=?',1];
    $own['musicmad_clip_sources'] = ['SELECT s.clip_id,s.publicacion_id,s.inicio_ms,s.duracion_ms FROM musicmad_clip_sources s JOIN clips_musicales c ON c.id=s.clip_id WHERE c.usuario_id=?',1];
    return $own;
}
/** Uses an unbuffered cursor and one consistent snapshot. Missing optional tables are recorded. */
function escribirExportacionCuenta(int $uid, callable $write): void
{
    $db = getDB();
    if ($db->inTransaction()) {
        throw new LogicException('La exportación necesita su propia transacción.');
    }
    $st = $db->prepare('SELECT 1 FROM usuarios WHERE id=? AND activo=1');
    $st->execute([$uid]);
    if (!$st->fetchColumn()) {
        throw new OutOfBoundsException('Cuenta no disponible.');
    }
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $old = $db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        $collections = [];
        $missing = [];
        foreach (coleccionesExportacionCuenta() as $name => [$sql,$count]) {
            try {
                $check = $db->prepare($sql . ' LIMIT 0');
                $check->execute(array_fill(0, $count, $uid));
                $check->closeCursor();
                $collections[$name] = [$sql,$count];
            } catch (PDOException $error) {
                if ($error->getCode() === '42S02') {
                    $missing[] = $name;
                    continue;
                }
                throw $error;
            }
        }
        $write('{"formato":"MusicMad-datos-v1","generado_utc":' . json_encode(gmdate('c')) . ',"archivos":"Las referencias conservan tus nombres de archivo. Descarga tus medios desde tus publicaciones antes de eliminar la cuenta.","colecciones":{');
        $first = true;
        foreach ($collections as $name => [$sql,$count]) {
            try {
                $st = $db->prepare($sql);
                $st->execute(array_fill(0, $count, $uid));
            } catch (PDOException $error) {
                if ($error->getCode() === '42S02') {
                    $missing[] = $name;
                    continue;
                }
                throw $error;
            }
            $write(($first ? '' : ',') . json_encode($name) . ':[');
            $first = false;
            $rowFirst = true;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $write(($rowFirst ? '' : ',') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
                $rowFirst = false;
            }
            $st->closeCursor();
            $write(']');
        }
        $write('},"colecciones_no_instaladas":' . json_encode($missing) . '}');
        $db->commit();
    } catch (Throwable $error) {
        if (isset($st)) {
            $st->closeCursor();
        }
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    } finally {
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $old);
    }
}
