<?php

/** A configured canonical origin; never create a QR using an arbitrary Host header. */
function basePublicaMusicMad(?string $configured = null): ?string
{
    $configured ??= (string) (getenv('MM_PUBLIC_URL') ?: getenv('MM_APP_URL') ?: '');
    $url = rtrim($configured, '/');
    $parts = parse_url($url);
    if (strlen($url) > 1000 || !$parts || !filter_var($url, FILTER_VALIDATE_URL)
        || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !preg_match('#^(/[A-Za-z0-9_-]+)*$#D', $parts['path'] ?? '')) {
        return null;
    }
    return $url;
}

/** Returns only metadata already visible to an anonymous visitor. */
function recursoCompartiblePublico(string $kind, int $id): ?array
{
    if ($id < 1 || $id > 4294967295 || !in_array($kind, ['perfil','banda','evento','sala'], true)) {
        return null;
    }
    $db = getDB();
    if ($kind === 'perfil') {
        $st = $db->prepare('SELECT slug FROM perfiles_publicos WHERE usuario_id=?');
        $st->execute([$id]);
        $slug = $st->fetchColumn();
        $profile = is_string($slug) ? portafolioPorAlias($slug, 0) : null;
        return $profile && $profile['publico'] ? ['tipo' => 'perfil','id' => $id,'titulo' => $profile['nombre'] . ' ' . $profile['apellido'],
            'descripcion' => $profile['bio'],'ciudad' => $profile['ciudad'],'slug' => $slug,
            'ruta' => 'portafolio.php?usuario=' . rawurlencode($slug)] : null;
    }
    if ($kind === 'evento') {
        $event = eventoDisponiblePara(0, $id);
        return $event ? ['tipo' => 'evento','id' => $id,'titulo' => $event['titulo'],'descripcion' => $event['descripcion'],
            'ciudad' => $event['ciudad'],'fecha' => $event['inicio_utc'] . ' UTC','ruta' => 'evento.php?id=' . $id] : null;
    }
    if ($kind === 'banda') {
        [$policy, $params] = filtroAccesoContenido(0, 'u.id', 'public_band');
        $st = $db->prepare("SELECT b.nombre,b.descripcion,b.ciudad FROM bandas b JOIN usuarios u ON u.id=b.creador_id AND u.activo=1
            LEFT JOIN musicmad_band_settings s ON s.banda_id=b.id
            WHERE b.id=:band AND s.archivada_en IS NULL AND COALESCE(s.ingreso,'abierto')='abierto' AND ($policy)");
        $st->execute([':band' => $id] + $params);
        $band = $st->fetch();
        return $band ? ['tipo' => 'banda','id' => $id,'titulo' => $band['nombre'],'descripcion' => $band['descripcion'],
            'ciudad' => $band['ciudad'],'ruta' => 'banda.php?id=' . $id] : null;
    }
    if (!funcionalidadMusicMad('salas', 0) || !salasEscuchaDisponibles()) {
        return null;
    }
    [$hostPolicy, $hostParams] = filtroAccesoContenido(0, 'h.id', 'public_room_host');
    [$trackPolicy, $trackParams] = filtroAccesoContenido(0, 'a.id', 'public_room_artist');
    $st = $db->prepare("SELECT s.nombre,p.titulo FROM salas_escucha s
        JOIN musicmad_room_state r ON r.sala_id=s.id AND r.modo='publica' AND r.expira_en>UTC_TIMESTAMP()
        JOIN usuarios h ON h.id=s.anfitrion_id AND h.activo=1
        JOIN publicaciones p ON p.id=s.publicacion_id JOIN usuarios a ON a.id=p.usuario_id AND a.activo=1
        WHERE s.id=:room AND s.estado='activa' AND ($hostPolicy) AND ($trackPolicy)");
    $st->execute([':room' => $id] + $hostParams + $trackParams);
    $room = $st->fetch();
    return $room ? ['tipo' => 'sala','id' => $id,'titulo' => $room['nombre'],'descripcion' => 'Escucha compartida: ' . $room['titulo'],
        'ciudad' => '','ruta' => 'sala.php?id=' . $id] : null;
}

function enlaceCompartiblePublico(string $kind, int $id): ?string
{
    $base = basePublicaMusicMad();
    if (!$base) {
        return null;
    }
    $resource = recursoCompartiblePublico($kind, $id);
    if (!$resource) {
        return null;
    }
    if (defined('MUSICMAD_ROUTED') && MUSICMAD_ROUTED === true) {
        if ($kind === 'perfil') {
            return $base . '/@' . $resource['slug'];
        }
        require_once __DIR__ . '/routing/public_binding.php';
        $path = MusicMad\Routing\basePath('/front.php', $base);
        $binding = (new MusicMad\Routing\PublicRouteBinding(getDB()))->bind(
            $path . 'vista-publica.php?tipo=' . $kind . '&id=' . $id,
            'GET',
            $path
        );
        return $binding['status'] === 200
            ? (string)parse_url($base, PHP_URL_SCHEME) . '://' . (string)parse_url($base, PHP_URL_HOST)
                . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '') . $binding['canonical']
            : null;
    }
    return $base . '/' . ($kind === 'perfil' ? $resource['ruta'] : 'vista-publica.php?tipo=' . $kind . '&id=' . $id);
}

function renderCompartirPublico(string $kind, int $id): void
{
    $url = enlaceCompartiblePublico($kind, $id);
    if (!$url) {
        ?><p class="public-share-unavailable">No hay un enlace público disponible. Para compartir un perfil, su portafolio debe estar publicado y su privacidad en Público.</p><?php
        return;
    }
    ?>
    <details class="public-share" data-sheet data-sheet-title="Compartir enlace y QR">
      <summary class="btn btn-ghost">Compartir y QR ↗</summary>
      <p>Este enlace puede abrirse sin iniciar sesión. Las acciones y conversaciones requieren una cuenta.</p>
      <label>Enlace público<input type="url" readonly value="<?= e($url) ?>" aria-label="Enlace público para compartir"></label>
      <div class="account-qr" data-qr-kind="<?= e($kind) ?>" data-qr-value="<?= e($url) ?>"></div>
    </details>
    <?php
}
