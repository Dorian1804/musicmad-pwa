<?php

/** Only application pages, bounded scalar filters and harmless local anchors. */
function destinoLocalSeguro(string $value, string $fallback = 'feed.php'): string
{
    if (defined('MUSICMAD_ROUTED') && MUSICMAD_ROUTED === true && defined('MUSICMAD_ROUTE_BASE')) {
        require_once __DIR__ . '/routing/destination.php';
        return MusicMad\Routing\destination(rutaAplicacion($value), rutaAplicacion($fallback), MUSICMAD_ROUTE_BASE);
    }
    if (strlen($value) > 1800 || !preg_match('/^([a-z][a-z-]*\.php)(?:\?([^#]*))?(?:#([a-zA-Z0-9_-]{1,80}))?$/D', $value, $match)) {
        return $fallback;
    }
    $pages = ['feed.php','musicos.php','mercado.php','busco.php','bandas.php','banda.php','mensajes.php','notificaciones.php','perfil.php','musico.php','configuracion.php','listas.php','eventos.php','publicacion.php','menu.php','pendientes.php','seguridad.php','avisos-dispositivo.php','buscar.php','datos.php','permisos.php','mapa.php','ubicacion.php','compatibles.php','banda-chat-vista.php','evento.php','epk.php','portafolio.php','salas.php','sala.php','colabs.php','colab.php','clips.php','clip.php','estadisticas.php','logros.php','retos.php','reto.php','recomendaciones.php','bienvenida.php','verificaciones.php'];
    $pages[] = 'restaurar-video.php';
    $pages[] = 'vista-publica.php';
    if (!in_array($match[1], $pages, true)) {
        return $fallback;
    }
    $args = [];
    parse_str($match[2] ?? '', $raw);
    $allowed = ['id','con','agregar','lista','usuario','q','instrumento','genero','tipo','modo','categoria','ciudad','min','max','orden','guardados','pagina','anuncio','txt','tag','antes','nivel','ver','vista','codigo'];
    foreach ($raw as $key => $value) {
        if (in_array($key, $allowed, true) && is_string($value) && strlen($value) <= 500 && !preg_match('/[\x00-\x1f\x7f]/', $value)) {
            $args[$key] = $value;
        }
    }
    return $match[1] . ($args ? '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986) : '') . (!empty($match[3]) ? '#' . $match[3] : '');
}
