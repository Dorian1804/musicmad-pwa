<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/i18n.php';
$GLOBALS['musicmad_locale_override'] = negociarIdiomaMusicMad((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
require_once dirname(__DIR__, 2) . '/includes/access_policy.php';
require_once dirname(__DIR__, 2) . '/includes/totp.php';
require_once dirname(__DIR__, 2) . '/includes/REPLACE_WITH_LOCAL_VALUE_policy.php';
require_once dirname(__DIR__, 2) . '/includes/email_required.php';
require_once dirname(__DIR__, 2) . '/includes/onboarding.php';

set_exception_handler(function (Throwable $error): void {
    error_log('API failure: ' . get_class($error));
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'temporarily_unavailable','message' => mensajeErrorApiIdioma('temporarily_unavailable')], JSON_UNESCAPED_UNICODE);
});
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function apiRespond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Language: ' . idiomaMusicMad());
    if ($status >= 400 && is_string($body['error'] ?? null)) {
        $body['message'] = mensajeErrorApiIdioma($body['error']);
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function apiB64(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
function apiSecret(): string
{
    $value = (string) (getenv('MM_JWT_SECRET') ?: '');
    if (strlen($value) < 32) {
        apiRespond(503, ['error' => 'api_unavailable', 'message' => 'El administrador debe configurar MM_JWT_SECRET con al menos 32 caracteres aleatorios.']);
    }
    return $value;
}
function apiJsonBody(): array
{
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
        apiRespond(413, ['error' => 'payload_too_large']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (!is_string($raw) || strlen($raw) > 65536) {
        apiRespond(413, ['error' => 'payload_too_large']);
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        apiRespond(400, ['error' => 'invalid_json']);
    }
    return $body;
}
function apiBearer(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                $header = (string) $value;
            }
        }
    }
    return preg_match('/^Bearer\s+([A-Za-z0-9_.-]+)$/i', $header, $m) ? $m[1] : '';
}
function apiLoginFingerprint(string $email): array
{
    try {
        return huellasLogin($email);
    } catch (RuntimeException $e) {
        apiRespond(503, ['error' => 'security_key_unavailable', 'message' => 'El administrador debe configurar MM_ENC_KEY.']);
    }
}
function apiSchemaReady(): bool
{
    try {
        $st = getDB()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('tokens_api','api_rate_limits','publicaciones_guardadas','migraciones_musicmad')");
        if ((int) $st->fetchColumn() !== 4) {
            return false;
        }
        return (bool) getDB()->query("SELECT 1 FROM migraciones_musicmad WHERE version='social3' LIMIT 1")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function apiCheckLoginRate(array $fingerprints): void
{
    try {
        if (limiteBloqueado(getDB(), $fingerprints)) {
            apiRespond(429, ['error' => 'rate_limited']);
        }
    } catch (Throwable $e) {
        apiRespond(503, ['error' => 'rate_limit_unavailable']);
    }
}
function apiRecordLogin(array $fingerprints, bool $ok): void
{
    try {
        guardarIntentoLimite(getDB(), $fingerprints, $ok);
    } catch (Throwable $e) {
        apiRespond(503, ['error' => 'rate_limit_unavailable']);
    }
}
function apiIssueToken(array $user): string
{
    $secret = apiSecret();
    $now = time();
    $jti = bin2hex(random_bytes(24));
    $head = apiB64(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_UNESCAPED_SLASHES));
    $payload = apiB64(json_encode(['iss' => 'MusicMad', 'sub' => (int) $user['id'], 'iat' => $now, 'exp' => $now + 3600, 'jti' => $jti], JSON_UNESCAPED_SLASHES));
    $signature = apiB64(hash_hmac('sha256', $head . '.' . $payload, $secret, true));
    getDB()->prepare('INSERT INTO tokens_api (usuario_id,jti_hash,expira_en) VALUES (:u,:j,FROM_UNIXTIME(:exp))')
        ->execute([':u' => (int) $user['id'], ':j' => hash('sha256', $jti), ':exp' => $now + 3600]);
    return $head . '.' . $payload . '.' . $signature;
}
function apiAuthenticate(): array
{
    $token = apiBearer();
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        apiRespond(401, ['error' => 'unauthorized']);
    }
    [$headPart, $payloadPart, $signature] = $parts;
    $head = json_decode((string) base64_decode(strtr($headPart, '-_', '+/')), true);
    $payload = json_decode((string) base64_decode(strtr($payloadPart, '-_', '+/')), true);
    if (!is_array($head) || ($head['alg'] ?? '') !== 'HS256' || !is_array($payload)) {
        apiRespond(401, ['error' => 'unauthorized']);
    }
    $expected = apiB64(hash_hmac('sha256', $headPart . '.' . $payloadPart, apiSecret(), true));
    if (!hash_equals($expected, $signature) || ($payload['iss'] ?? '') !== 'MusicMad' || (int) ($payload['exp'] ?? 0) <= time() || (int) ($payload['sub'] ?? 0) < 1 || !preg_match('/^[a-f0-9]{48}$/', (string) ($payload['jti'] ?? ''))) {
        apiRespond(401, ['error' => 'unauthorized']);
    }
    $db = getDB();
    $st = $db->prepare('SELECT u.id,u.nombre,u.apellido,u.avatar,u.rol,u.activo,t.id AS token_id,t.jti_hash FROM tokens_api t JOIN usuarios u ON u.id=t.usuario_id WHERE t.usuario_id=:u AND t.jti_hash=:j AND t.revocado_en IS NULL AND t.expira_en>NOW() AND u.activo=1 LIMIT 1');
    $st->execute([':u' => (int) $payload['sub'], ':j' => hash('sha256', (string) $payload['jti'])]);
    $user = $st->fetch();
    if (!$user) {
        apiRespond(401, ['error' => 'unauthorized']);
    }
    $bucket = gmdate('Y-m-d H:00:00');
    $db->prepare('INSERT INTO api_rate_limits (usuario_id,bucket,solicitudes) VALUES (:u,:b,1) ON DUPLICATE KEY UPDATE solicitudes=solicitudes+1')
       ->execute([':u' => (int) $user['id'], ':b' => $bucket]);
    $limit = $db->prepare('SELECT solicitudes FROM api_rate_limits WHERE usuario_id=:u AND bucket=:b');
    $limit->execute([':u' => (int) $user['id'], ':b' => $bucket]);
    if ((int) $limit->fetchColumn() > 120) {
        apiRespond(429, ['error' => 'rate_limited']);
    }
    $GLOBALS['musicmad_locale_override'] = idiomaCuentaMusicMad((int)$user['id'], negociarIdiomaMusicMad((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')));
    $user['claims_jti'] = (string) $payload['jti'];
    return $user;
}

$resource = strtolower(trim((string) ($_GET['resource'] ?? basename(parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''))));
$resource = trim($resource, '/');
$resource = preg_replace('/^v1\/?/', '', $resource) ?: $resource;
$resource = $resource ?: 'root';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$db = getDB();

if ($method === 'GET' && $resource === 'openapi.json') {
    header('Content-Type: application/json; charset=utf-8');
    readfile(__DIR__ . '/openapi.json');
    exit;
}

if ($method === 'POST' && $resource === 'login') {
    if (!apiSchemaReady()) {
        apiRespond(503, ['error' => 'migration_required', 'message' => 'Importa config/social3.sql para habilitar la API.']);
    }
    $body = apiJsonBody();
    $email = mb_substr(trim((string) ($body['email'] ?? '')), 0, 180);
    $REPLACE_WITH_LOCAL_VALUE = is_string($body['REPLACE_WITH_LOCAL_VALUE'] ?? null) ? $body['REPLACE_WITH_LOCAL_VALUE'] : '';
    if (strlen($REPLACE_WITH_LOCAL_VALUE) > 72 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiRespond(401, ['error' => 'invalid_credentials']);
    }
    $fingerprint = apiLoginFingerprint($email);
    apiCheckLoginRate($fingerprint);
    $st = $db->prepare('SELECT id,nombre,apellido,avatar,rol,REPLACE_WITH_LOCAL_VALUE,activo FROM usuarios WHERE email=:email LIMIT 1');
    $st->execute([':email' => $email]);
    $user = $st->fetch();
    $matches = verificarPasswordConTiempo($REPLACE_WITH_LOCAL_VALUE, $user ? (string)$user['REPLACE_WITH_LOCAL_VALUE'] : null);
    $ok = $user && (int) $user['activo'] === 1 && $matches;
    apiRecordLogin($fingerprint, (bool) $ok);
    if (!$ok) {
        apiRespond(401, ['error' => 'invalid_credentials']);
    }
    $db->beginTransaction();
    try {
        $locked = $db->prepare('SELECT REPLACE_WITH_LOCAL_VALUE,activo FROM usuarios WHERE id=? FOR UPDATE');
        $locked->execute([$user['id']]);
        $current = $locked->fetch();
        if (!$current || !(int)$current['activo'] || !hash_equals((string)$user['REPLACE_WITH_LOCAL_VALUE'], (string)$current['REPLACE_WITH_LOCAL_VALUE'])) {
            $db->rollBack();
            apiRespond(401, ['error' => 'invalid_credentials']);
        }
        if (tieneSegundoFactor((int)$user['id'])) {
            $code = is_string($body['code'] ?? null) ? $body['code'] : '';
            if (!consumirSegundoFactor((int)$user['id'], $code)) {
                $db->commit();
                apiRespond(401, ['error' => 'second_factor_required','message' => 'Confirma un código válido de tu app autenticadora o de recuperación.']);
            }
        }
        iniciarPlazoVerificacion((int) $user['id']);
        $token = apiIssueToken($user);
        $db->commit();
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    unset($user['REPLACE_WITH_LOCAL_VALUE'], $user['activo']);
    $user['avatar'] = musicmadPublicImageUrl((string) $user['avatar']);
    apiRespond(200, ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => $token, 'user' => $user]);
}

if ($resource === 'root' && $method === 'GET') {
    apiRespond(200, ['name' => 'MusicMad API', 'version' => '1.0', 'docs' => 'api/v1/openapi.json']);
}
if (!apiSchemaReady()) {
    apiRespond(503, ['error' => 'migration_required', 'message' => 'Importa config/social3.sql para habilitar la API.']);
}
$user = apiAuthenticate();
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && $resource !== 'logout'
    && destinoCorreoPendiente((int) $user['id'])) {
    apiRespond(403, ['error' => 'email_verification_required',
        'message' => 'Confirma tu correo para realizar esta acción.',
        'verification_url' => APP_URL . '/verificar-correo.php']);
}

if ($method === 'GET' && $resource === 'me') {
    apiRespond(200, ['id' => (int) $user['id'], 'name' => $user['nombre'], 'surname' => $user['apellido'], 'avatar' => musicmadPublicImageUrl((string) $user['avatar']), 'role' => $user['rol'], 'email_verified' => correoCuentaVerificado((int) $user['id']), 'onboarding_complete' => onboardingCompleto((int) $user['id'])]);
}
if ($resource !== 'logout' && !onboardingCompleto((int) $user['id'])) {
    apiRespond(403, ['error' => 'onboarding_required', 'message' => 'Completa tu recorrido para entrar a la comunidad.', 'onboarding_url' => APP_URL . '/bienvenida.php']);
}

if ($method === 'GET' && $resource === 'feed') {
    $cursor = isset($_GET['cursor']) && ctype_digit((string) $_GET['cursor']) ? max(0, (int) $_GET['cursor']) : PHP_INT_MAX;
    $limit = isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) ? max(1, min(40, (int) $_GET['limit'])) : 20;
    $following = ($_GET['mode'] ?? '') === 'following';
    $where = ['p.id < :cursor', 'u.activo=1'];
    [$policy, $policyParams] = filtroAccesoContenido((int) $user['id'], 'p.usuario_id', 'api_feed');
    $params = [':cursor' => $cursor] + $policyParams;
    $where[] = '(' . $policy . ')';
    if ($following) {
        $where[] = '(p.usuario_id=:self OR EXISTS (SELECT 1 FROM seguidores sf WHERE sf.seguidor_id=:self2 AND sf.seguido_id=p.usuario_id))';
        $params[':self'] = (int) $user['id'];
        $params[':self2'] = (int) $user['id'];
    }
    $sql = 'SELECT p.id,p.usuario_id,p.tipo,p.titulo,p.texto,p.enlace,p.genero,p.created_at,u.nombre,u.apellido,u.avatar,pm.instrumento FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id LEFT JOIN perfiles_musicales pm ON pm.usuario_id=p.usuario_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT ' . $limit;
    $st = $db->prepare($sql);
    $st->execute($params);
    $items = $st->fetchAll();
    foreach ($items as &$item) {
        $item['id'] = (int) $item['id'];
        $item['avatar'] = musicmadPublicImageUrl((string) $item['avatar']);
    }
    unset($item);
    apiRespond(200, ['items' => $items, 'next_cursor' => $items ? (int) end($items)['id'] : null]);
}

if ($method === 'POST' && $resource === 'posts') {
    $body = apiJsonBody();
    $title = trim((string) ($body['title'] ?? ''));
    $text = trim((string) ($body['text'] ?? ''));
    $genre = trim((string) ($body['genre'] ?? ''));
    if ($title === '' || mb_strlen($title) > 120 || mb_strlen($text) > 1000 || mb_strlen($genre) > 40) {
        apiRespond(422, ['error' => 'validation_error']);
    }
    $st = $db->prepare('INSERT INTO publicaciones (usuario_id,tipo,titulo,texto,genero) VALUES (:u,\'texto\',:t,:x,:g)');
    $st->execute([':u' => (int) $user['id'], ':t' => $title, ':x' => $text, ':g' => $genre]);
    apiRespond(201, ['id' => (int) $db->lastInsertId(), 'created' => true]);
}

if ($method === 'POST' && $resource === 'logout') {
    $db->prepare('UPDATE tokens_api SET revocado_en=UTC_TIMESTAMP() WHERE usuario_id=:u AND jti_hash=:j AND revocado_en IS NULL')
       ->execute([':u' => (int) $user['id'], ':j' => hash('sha256', $user['claims_jti'])]);
    apiRespond(200, ['revoked' => true]);
}

header('Allow: GET, POST');
apiRespond(404, ['error' => 'not_found']);
