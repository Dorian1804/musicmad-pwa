<?php

declare(strict_types=1);

// Fixed front controller. Apache activation is performed only after the routing migration.
require_once __DIR__ . '/includes/routing/paths.php';
require_once __DIR__ . '/includes/error_response.php';
require_once __DIR__ . '/includes/security_headers.php';

try {
    $routeBase = MusicMad\Routing\basePath((string)($_SERVER['SCRIPT_NAME'] ?? '/front.php'), (string)(getenv('MM_APP_URL') ?: ''));
    $routeUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $routeMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $routeMatch = MusicMad\Routing\resolve($routeUri, $routeMethod, $routeBase);
    if ($routeMatch['status'] === 405) {
        header('Allow: ' . implode(', ', $routeMatch['allow']));
        http_response_code(405);
        header('Cache-Control: no-store');
        exit;
    }
    if ($routeMatch['status'] !== 200) {
        responderErrorMusicMad(404);
    }
    $routeEntry = $routeMatch['route'];
    // Existing account gates, cookie scope and page navigation use this trusted script identity.
    $_SERVER['SCRIPT_NAME'] = $routeBase . ($routeEntry['legacy'][0] ?? $routeEntry['handler'] . '.php');
    $_GET = $routeMatch['query'];
    if (isset($routeEntry['api_resource'])) {
        if ($routeMatch['legacy'] && in_array($routeMethod, ['GET', 'HEAD'], true)) {
            $apiCanonical = MusicMad\Routing\pathFor($routeEntry['name'], [], $routeBase);
            if ($_GET) {
                $apiCanonical .= '?' . http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
            }
            header('Location: ' . $apiCanonical, true, 301);
            exit;
        }
        $_GET['resource'] = $routeEntry['api_resource'];
        require __DIR__ . '/api/v1/index.php';
        exit;
    }
    define('MUSICMAD_ROUTED', true);
    define('MUSICMAD_ROUTE_BASE', $routeBase);
    if ($routeEntry['name'] === 'manifesto') {
        if ($routeMatch['legacy'] || $routeMatch['canonical_slash']) {
            $manifestCanonical = MusicMad\Routing\pathFor('manifesto', [], $routeBase);
            if ($_GET) {
                $manifestCanonical .= '?' . http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
            }
            header('Location: ' . $manifestCanonical, true, 301);
            exit;
        }
        require __DIR__ . '/manifesto.php';
        exit;
    }
    header_register_callback(static function (): void {
        foreach (headers_list() as $responseHeader) {
            if (!preg_match('~^Location:\s*([a-z][a-z-]*[.]php(?:[?#].*)?)$~iD', $responseHeader, $target)) {
                continue;
            }
            header('Location: ' . rutaAplicacion($target[1]), true);
        }
    });
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/routing/request_plan.php';
    require_once __DIR__ . '/includes/routing/dispatch.php';

    header_remove('X-Powered-By');
    if (!in_array($routeMethod, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $parts = parse_url(APP_URL);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (strcasecmp((string)($_SERVER['HTTP_HOST'] ?? ''), $host) !== 0
            || (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $origin)
            || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            responderErrorMusicMad(404);
        }
        $headerToken = $_SERVER['HTTP_X_MUSICMAD_CSRF'] ?? null;
        if (!csrfValido() && (!is_string($headerToken) || !hash_equals(csrfToken(), $headerToken))) {
            http_response_code(403);
            header('Cache-Control: no-store');
            exit('La sesión expiró o el formulario no es válido. Regresa y vuelve a intentarlo.');
        }
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 270 * 1024 * 1024) {
            http_response_code(413);
            exit;
        }
    }

    $routeViewer = estaAutenticado() ? (int)sesion('usuario_id') : 0;
    if ($routeViewer === 0 && in_array($routeMethod, ['GET', 'HEAD'], true)) {
        require_once __DIR__ . '/includes/public_read_limits.php';
        if (!registrarLecturaPublica((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
            http_response_code(429);
            header('Retry-After: 60');
            header('Cache-Control: no-store');
            exit('Espera un momento antes de volver a intentar.');
        }
    }
    $routePlan = (new MusicMad\Routing\RequestPlan(getDB(), $routeViewer))->resolve($routeUri, $routeMethod, $_POST, $routeBase);
    if ($routePlan['status'] !== 200) {
        responderErrorMusicMad(404);
    }
    if ($routePlan['redirect'] === 301) {
        header('Cache-Control: private, no-store');
        header('Location: ' . $routePlan['canonical'], true, 301);
        exit;
    }
    $_GET = $routePlan['query'];
    $_POST = $routePlan['body'];
    $_REQUEST = array_merge($_GET, $_POST);
    define('MUSICMAD_ROUTE_CANONICAL', $routePlan['canonical']);
    MusicMad\Routing\dispatch($routePlan['route']['handler']);
} catch (Throwable $routeError) {
    error_log('MusicMad route failure: ' . get_class($routeError));
    responderErrorMusicMad(500);
}
