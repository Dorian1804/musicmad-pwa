<?php

declare(strict_types=1);

namespace MusicMad\Routing;

/** Dispatch uses literal includes only, even when called with an untrusted name. */
function dispatch(string $handler): void
{
    switch ($handler) {
        case 'comprobar-almacen':
            require dirname(__DIR__, 2) . '/comprobar-almacen.php';
            return;
        case '404':
            require dirname(__DIR__, 2) . '/404.php';
            return;
        case '500':
            require dirname(__DIR__, 2) . '/500.php';
            return;
        case 'acciones-pendientes':
            require dirname(__DIR__, 2) . '/acciones-pendientes.php';
            return;
        case 'api':
            require dirname(__DIR__, 2) . '/api/v1/index.php';
            return;
        case 'audio':
            require dirname(__DIR__, 2) . '/audio.php';
            return;
        case 'avisos-dispositivo':
            require dirname(__DIR__, 2) . '/avisos-dispositivo.php';
            return;
        case 'banda':
            require dirname(__DIR__, 2) . '/banda.php';
            return;
        case 'banda-accion':
            require dirname(__DIR__, 2) . '/banda-accion.php';
            return;
        case 'banda-chat':
            require dirname(__DIR__, 2) . '/banda-chat.php';
            return;
        case 'banda-chat-vista':
            require dirname(__DIR__, 2) . '/banda-chat-vista.php';
            return;
        case 'banda-ics':
            require dirname(__DIR__, 2) . '/banda-ics.php';
            return;
        case 'bandas':
            require dirname(__DIR__, 2) . '/bandas.php';
            return;
        case 'bienvenida':
            require dirname(__DIR__, 2) . '/bienvenida.php';
            return;
        case 'bienvenida-accion':
            require dirname(__DIR__, 2) . '/bienvenida-accion.php';
            return;
        case 'buscar':
            require dirname(__DIR__, 2) . '/buscar.php';
            return;
        case 'busco':
            require dirname(__DIR__, 2) . '/busco.php';
            return;
        case 'busqueda-global':
            require dirname(__DIR__, 2) . '/busqueda-global.php';
            return;
        case 'cartel-evento':
            require dirname(__DIR__, 2) . '/cartel-evento.php';
            return;
        case 'chat-live':
            require dirname(__DIR__, 2) . '/chat-live.php';
            return;
        case 'chat-stream':
            require dirname(__DIR__, 2) . '/chat-stream.php';
            return;
        case 'clip':
            require dirname(__DIR__, 2) . '/clip.php';
            return;
        case 'clip-accion':
            require dirname(__DIR__, 2) . '/clip-accion.php';
            return;
        case 'clip-medio':
            require dirname(__DIR__, 2) . '/clip-medio.php';
            return;
        case 'clips':
            require dirname(__DIR__, 2) . '/clips.php';
            return;
        case 'colab':
            require dirname(__DIR__, 2) . '/colab.php';
            return;
        case 'colab-accion':
            require dirname(__DIR__, 2) . '/colab-accion.php';
            return;
        case 'colab-subir':
            require dirname(__DIR__, 2) . '/colab-subir.php';
            return;
        case 'colabs':
            require dirname(__DIR__, 2) . '/colabs.php';
            return;
        case 'comentarios':
            require dirname(__DIR__, 2) . '/comentarios.php';
            return;
        case 'compartir':
            require dirname(__DIR__, 2) . '/compartir.php';
            return;
        case 'compatibles':
            require dirname(__DIR__, 2) . '/compatibles.php';
            return;
        case 'configuracion':
            require dirname(__DIR__, 2) . '/configuracion.php';
            return;
        case 'confirmar-correo':
            require dirname(__DIR__, 2) . '/confirmar-correo.php';
            return;
        case 'datos':
            require dirname(__DIR__, 2) . '/datos.php';
            return;
        case 'dos-pasos':
            require dirname(__DIR__, 2) . '/dos-pasos.php';
            return;
        case 'eliminar-cuenta':
            require dirname(__DIR__, 2) . '/eliminar-cuenta.php';
            return;
        case 'embed':
            require dirname(__DIR__, 2) . '/embed.php';
            return;
        case 'epk':
            require dirname(__DIR__, 2) . '/epk.php';
            return;
        case 'escucha':
            require dirname(__DIR__, 2) . '/escucha.php';
            return;
        case 'estadisticas':
            require dirname(__DIR__, 2) . '/estadisticas.php';
            return;
        case 'evento':
            require dirname(__DIR__, 2) . '/evento.php';
            return;
        case 'evento-accion':
            require dirname(__DIR__, 2) . '/evento-accion.php';
            return;
        case 'evento-ics':
            require dirname(__DIR__, 2) . '/evento-ics.php';
            return;
        case 'eventos':
            require dirname(__DIR__, 2) . '/eventos.php';
            return;
        case 'exportar-datos':
            require dirname(__DIR__, 2) . '/exportar-datos.php';
            return;
        case 'feed':
            require dirname(__DIR__, 2) . '/feed.php';
            return;
        case 'feedback-accion':
            require dirname(__DIR__, 2) . '/feedback-accion.php';
            return;
        case 'google-inicio':
            require dirname(__DIR__, 2) . '/google-inicio.php';
            return;
        case 'google-regreso':
            require dirname(__DIR__, 2) . '/google-regreso.php';
            return;
        case 'idioma':
            require dirname(__DIR__, 2) . '/idioma.php';
            return;
        case 'index':
            require dirname(__DIR__, 2) . '/index.php';
            return;
        case 'listas':
            require dirname(__DIR__, 2) . '/listas.php';
            return;
        case 'login':
            require dirname(__DIR__, 2) . '/login.php';
            return;
        case 'logout':
            require dirname(__DIR__, 2) . '/logout.php';
            return;
        case 'logros':
            require dirname(__DIR__, 2) . '/logros.php';
            return;
        case 'manifesto':
            require dirname(__DIR__, 2) . '/manifesto.php';
            return;
        case 'mapa':
            require dirname(__DIR__, 2) . '/mapa.php';
            return;
        case 'mapa-datos':
            require dirname(__DIR__, 2) . '/mapa-datos.php';
            return;
        case 'media-image':
            require dirname(__DIR__, 2) . '/media-image.php';
            return;
        case 'mensajes':
            require dirname(__DIR__, 2) . '/mensajes.php';
            return;
        case 'menu':
            require dirname(__DIR__, 2) . '/menu.php';
            return;
        case 'mercado':
            require dirname(__DIR__, 2) . '/mercado.php';
            return;
        case 'mercado-reportes':
            require dirname(__DIR__, 2) . '/mercado-reportes.php';
            return;
        case 'moderacion':
            require dirname(__DIR__, 2) . '/moderacion.php';
            return;
        case 'musico':
            require dirname(__DIR__, 2) . '/musico.php';
            return;
        case 'musicos':
            require dirname(__DIR__, 2) . '/musicos.php';
            return;
        case 'notificaciones':
            require dirname(__DIR__, 2) . '/notificaciones.php';
            return;
        case 'onda':
            require dirname(__DIR__, 2) . '/onda.php';
            return;
        case 'partitura':
            require dirname(__DIR__, 2) . '/partitura.php';
            return;
        case 'pendientes':
            require dirname(__DIR__, 2) . '/pendientes.php';
            return;
        case 'perfil':
            require dirname(__DIR__, 2) . '/perfil.php';
            return;
        case 'perfil-estilos':
            require dirname(__DIR__, 2) . '/perfil-estilos.php';
            return;
        case 'permisos':
            require dirname(__DIR__, 2) . '/permisos.php';
            return;
        case 'pista-publica':
            require dirname(__DIR__, 2) . '/pista-publica.php';
            return;
        case 'playlist-equipo':
            require dirname(__DIR__, 2) . '/playlist-equipo.php';
            return;
        case 'playlist-equipo-accion':
            require dirname(__DIR__, 2) . '/playlist-equipo-accion.php';
            return;
        case 'portafolio':
            require dirname(__DIR__, 2) . '/portafolio.php';
            return;
        case 'publicacion':
            require dirname(__DIR__, 2) . '/publicacion.php';
            return;
        case 'push-suscripcion':
            require dirname(__DIR__, 2) . '/push-suscripcion.php';
            return;
        case 'reactivar-cuenta':
            require dirname(__DIR__, 2) . '/reactivar-cuenta.php';
            return;
        case 'recomendacion-accion':
            require dirname(__DIR__, 2) . '/recomendacion-accion.php';
            return;
        case 'recomendaciones':
            require dirname(__DIR__, 2) . '/recomendaciones.php';
            return;
        case 'recuperar':
            require dirname(__DIR__, 2) . '/recuperar.php';
            return;
        case 'registro':
            require dirname(__DIR__, 2) . '/registro.php';
            return;
        case 'repost-accion':
            require dirname(__DIR__, 2) . '/repost-accion.php';
            return;
        case 'restablecer':
            require dirname(__DIR__, 2) . '/restablecer.php';
            return;
        case 'restaurar-video':
            require dirname(__DIR__, 2) . '/restaurar-video.php';
            return;
        case 'reto':
            require dirname(__DIR__, 2) . '/reto.php';
            return;
        case 'reto-accion':
            require dirname(__DIR__, 2) . '/reto-accion.php';
            return;
        case 'retos':
            require dirname(__DIR__, 2) . '/retos.php';
            return;
        case 'sala':
            require dirname(__DIR__, 2) . '/sala.php';
            return;
        case 'sala-accion':
            require dirname(__DIR__, 2) . '/sala-accion.php';
            return;
        case 'sala-datos':
            require dirname(__DIR__, 2) . '/sala-datos.php';
            return;
        case 'salas':
            require dirname(__DIR__, 2) . '/salas.php';
            return;
        case 'seguridad':
            require dirname(__DIR__, 2) . '/seguridad.php';
            return;
        case 'social-accion':
            require dirname(__DIR__, 2) . '/social-accion.php';
            return;
        case 'subir-archivo':
            require dirname(__DIR__, 2) . '/subir-archivo.php';
            return;
        case 'ubicacion':
            require dirname(__DIR__, 2) . '/ubicacion.php';
            return;
        case 'verificaciones':
            require dirname(__DIR__, 2) . '/verificaciones.php';
            return;
        case 'verificar-correo':
            require dirname(__DIR__, 2) . '/verificar-correo.php';
            return;
        case 'video':
            require dirname(__DIR__, 2) . '/video.php';
            return;
        case 'vista-publica':
            require dirname(__DIR__, 2) . '/vista-publica.php';
            return;
        default:
            throw new \OutOfBoundsException('Route not found.');
    }
}
