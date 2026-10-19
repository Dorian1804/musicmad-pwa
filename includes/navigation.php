<?php

/** One menu catalogue for sidebar, phone dock and the More screen. */
function catalogoNavegacion(int $uid): array
{
    return [
        ['id' => 'feed','href' => 'feed.php','label' => 'Comunidad','description' => 'Escucha y comparte ideas','icon' => 'home','group' => 'Escena','dock' => true],
        ['id' => 'musicos','href' => 'buscar.php','label' => 'Buscar','description' => 'Músicos, pistas y amigos','icon' => 'search','group' => 'Escena','dock' => true],
        ['id' => 'subir','href' => 'feed.php#compartir','label' => 'Subir','description' => 'Comparte tu música','icon' => 'plus','group' => 'Escena','dock' => true,'compose' => true],
        ['id' => 'mensajes','href' => 'mensajes.php','label' => 'Chat','description' => 'Tus conversaciones','icon' => 'chat','group' => 'Escena','dock' => true,'badge' => 'messages'],
        ['id' => 'perfil','href' => 'perfil.php','label' => 'Perfil','description' => 'Foto, portada y ficha musical','icon' => 'avatar','group' => 'Tu cuenta','dock' => true],
        ['id' => 'menu','href' => 'menu.php','label' => 'Más','description' => 'Todas las secciones','icon' => 'grid','group' => 'Tu cuenta','dock' => true],
        ['id' => 'perfil_publico','href' => 'musico.php?id=' . $uid,'label' => 'Mi perfil público','description' => 'Publicaciones y actividad','icon' => 'person','group' => 'Tu cuenta'],
        ['id' => 'bandas','href' => 'bandas.php','label' => 'Bandas','description' => 'Encuentra a tu grupo','icon' => 'band','group' => 'Escena'],
        ['id' => 'busco','href' => 'busco.php','label' => 'Se busca','description' => 'Tu próxima colaboración','icon' => 'search','group' => 'Escena'],
        ['id' => 'compatibles','href' => 'compatibles.php','label' => 'Músicos compatibles','description' => 'Instrumento, ciudad y nivel','icon' => 'person','group' => 'Escena'],
        ['id' => 'mapa','href' => 'mapa.php','label' => 'Mapa de la escena','description' => 'Músicos, bandas y eventos por zona','icon' => 'map','group' => 'Escena'],
        ['id' => 'eventos','href' => 'eventos.php','label' => 'Eventos','description' => 'Tocadas y encuentros','icon' => 'calendar','group' => 'Escena'],
        ['id' => 'mercado','href' => 'mercado.php','label' => 'Instrumentos','description' => 'Equipo usado entre músicos','icon' => 'market','group' => 'Escena'],
        ['id' => 'salas','href' => 'salas.php','label' => 'Salas de escucha','description' => 'La misma pista con otros músicos','icon' => 'headphones','group' => 'Tu música'],
        ['id' => 'clips','href' => 'clips.php','label' => 'Clips de la escena','description' => 'Momentos de música de 24 horas','icon' => 'play','group' => 'Tu música'],
        ['id' => 'colabs','href' => 'colabs.php','label' => 'Estudio de colaboraciones','description' => 'Pistas base, aportes y versiones','icon' => 'sliders','group' => 'Tu música'],
        ['id' => 'listas','href' => 'listas.php','label' => 'Playlists','description' => 'Tu biblioteca','icon' => 'list','group' => 'Tu música'],
        ['id' => 'retos','href' => 'retos.php','label' => 'Retos de la escena','description' => 'Consignas, pistas y votaciones','icon' => 'award','group' => 'Tu música'],
        ['id' => 'estadisticas','href' => 'estadisticas.php','label' => 'Mi música en números','description' => 'Escuchas, audiencia y pistas','icon' => 'chart','group' => 'Tu música'],
        ['id' => 'recomendaciones','href' => 'recomendaciones.php','label' => 'Recomendaciones musicales','description' => 'Voces de la escena y experiencias','icon' => 'chat','group' => 'Tu música'],
        ['id' => 'logros','href' => 'logros.php','label' => 'Mis insignias','description' => 'Hitos y nivel de comunidad','icon' => 'award','group' => 'Tu música'],
        ['id' => 'epk','href' => 'epk.php','label' => 'Portafolio de artista','description' => 'Tu música y tus redes en un enlace','icon' => 'person','group' => 'Tu cuenta'],
        ['id' => 'notificaciones','href' => 'notificaciones.php','label' => 'Notificaciones','description' => 'Lo que pasa en tu comunidad','icon' => 'bell','group' => 'Tu cuenta','badge' => 'notifications'],
        ['id' => 'pendientes','href' => 'pendientes.php','label' => 'Acciones pendientes','description' => 'Revisar y sincronizar','icon' => 'sync','group' => 'Tu cuenta'],
        ['id' => 'configuracion','href' => 'configuracion.php','label' => 'Configuración','description' => 'Seguridad y privacidad','icon' => 'settings','group' => 'Tu cuenta'],
        ['id' => 'datos','href' => 'datos.php','label' => 'Tus datos','description' => 'Descarga y control de cuenta','icon' => 'shield','group' => 'Tu cuenta'],
        ['id' => 'bienvenida','href' => 'bienvenida.php','label' => 'Armar mi escena','description' => 'Instrumentos, géneros y conexiones','icon' => 'sliders','group' => 'Tu cuenta'],
        ['id' => 'moderacion','href' => 'moderacion.php','label' => 'Moderación','description' => 'Reportes de la comunidad','icon' => 'shield','group' => 'Administración','role' => 'moderador'],
        ['id' => 'verificaciones','href' => 'verificaciones.php','label' => 'Verificaciones artísticas','description' => 'Revisión manual de perfiles','icon' => 'award','group' => 'Administración','role' => 'administrador'],
        ['id' => 'logout','href' => 'logout.php','method' => 'POST','label' => 'Cerrar sesión','description' => 'Salir de este dispositivo','icon' => 'exit','group' => 'Tu cuenta'],
    ];
}

function iconoNavegacion(string $name): string
{
    $paths = [
        'home' => '<path d="m3 10 9-7 9 7v10h-6v-7H9v7H3z"/>',
        'search' => '<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
        'plus' => '<path d="M12 4v16M4 12h16"/>',
        'chat' => '<path d="M21 11a9 8 0 0 1-9 8H8l-5 3v-6a8 8 0 1 1 18-5Z"/>',
        'person' => '<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
        'band' => '<circle cx="8" cy="7" r="3"/><circle cx="18" cy="8" r="3"/><path d="M1 21v-3a7 7 0 0 1 14 0v3m2-8a6 6 0 0 1 6 6v2"/>',
        'map' => '<path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2zm6-2v16m6-14v16"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 2v6m10-6v6M3 11h18"/>',
        'market' => '<path d="M3 9h18l-2-6H5zm1 0v12h16V9M9 21v-8h6v8"/>',
        'headphones' => '<path d="M3 14v-3a9 9 0 0 1 18 0v3M3 13h4v8H3zm14 0h4v8h-4z"/>',
        'play' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="m10 8 7 4-7 4z"/>',
        'sliders' => '<path d="M5 3v18m7-18v18m7-18v18M2 8h6m1 8h6m1-10h6"/>',
        'list' => '<path d="M4 5h16M4 11h16M4 17h8m5-2v7m-3-3 3 3 3-3"/>',
        'award' => '<circle cx="12" cy="8" r="5"/><path d="m8 12-2 10 6-3 6 3-2-10"/>',
        'chart' => '<path d="M4 3v18h17M9 17v-6m5 6V7m5 10V4"/>',
        'bell' => '<path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/>',
        'sync' => '<path d="M3 11a9 9 0 0 1 15-7l3 3M21 2v5h-5m5 6a9 9 0 0 1-15 7l-3-3m0 5v-5h5"/>',
        'settings' => '<path d="m9 3 1-2h4l1 2 3 2 3 1v4l-2 2 2 2v4l-3 1-3 2-1 2h-4l-1-2-3-2-3-1v-4l2-2-2-2V6l3-1z"/><circle cx="12" cy="12" r="3"/>',
        'shield' => '<path d="m12 2 9 4v6c0 6-9 10-9 10S3 18 3 12V6zm-4 9 3 3 5-5"/>',
        'exit' => '<path d="M9 3H3v18h6m0-9h12m-5-5 5 5-5 5"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['person']) . '</svg>';
}

function navegacionPermitida(int $uid, string $role): array
{
    return array_values(array_filter(catalogoNavegacion($uid), static function ($item) use ($role) {
        return !isset($item['role']) || $role === 'administrador' || ($item['role'] === 'moderador' && $role === 'moderador');
    }));
}

function seccionNavegacionActual(string $script): string
{
    $page = basename($script);
    foreach (catalogoNavegacion(0) as $item) {
        if (strtok($item['href'], '?#') === $page) {
            return $item['id'];
        }
    }
    return match ($page) {
        'sala.php' => 'salas','clip.php' => 'clips','colab.php' => 'colabs','evento.php' => 'eventos',
        'banda.php','banda-chat-vista.php' => 'bandas','musico.php' => 'perfil_publico',
        'publicacion.php' => 'feed','reto.php' => 'retos','seguridad.php','avisos-dispositivo.php' => 'configuracion',
        default => '',
    };
}

function renderEnlaceNavegacion(array $item, string $active, array $badges = [], string $surface = 'sidebar'): void
{
    if (($item['method'] ?? 'GET') === 'POST') {
        ?><form method="POST" action="<?= e(rutaAplicacion($item['href'], 'POST')) ?>" class="nav-logout-form"><?= csrfCampo() ?><button type="submit" class="nav-section-action"><?= iconoNavegacion($item['icon']) ?><span><?= e(mmTexto($item['label'])) ?></span></button></form><?php
        return;
    }
    $selected = $item['id'] === $active;
    $count = (int) ($badges[$item['badge'] ?? ''] ?? 0);
    $class = ($selected ? 'active ' : '') . (!empty($item['compose']) ? 'dock-compose' : '');
    $icon = $item['icon'] === 'avatar'
        ? '<img src="' . e(musicmadPublicImageUrl(sesion('usuario_avatar') ?: AVATAR_DEFECTO)) . '" alt="">'
        : iconoNavegacion($item['icon']);
    ?>
    <a href="<?= e(rutaAplicacion($item['href'])) ?>" class="<?= e(trim($class)) ?>" <?= $selected ? 'aria-current="page"' : '' ?> <?= !empty($item['compose']) ? 'data-open-composer aria-label="Subir una publicación"' : '' ?>>
      <span class="nav-section-icon"><?= $icon ?><?php if ($count > 0): ?><b class="dock-badge"><?= $count > 99 ? '99+' : $count ?></b><?php endif; ?></span>
      <?php if ($surface === 'dock'): ?><small><?= e(mmTexto($item['label'])) ?></small><?php else: ?><span><?= e(mmTexto($item['label'])) ?></span><?php endif; ?>
    </a>
    <?php
}
