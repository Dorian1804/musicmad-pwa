<?php

// ============================================================
//  MusicMad – Autenticación, sesión y CSRF
//  includes/auth.php
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/account_sessions.php';
require_once __DIR__ . '/local_destination.php';
require_once __DIR__ . '/routing/urls.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/REPLACE_WITH_LOCAL_VALUE_policy.php';
require_once __DIR__ . '/account_controls.php';
require_once __DIR__ . '/onboarding.php';
require_once __DIR__ . '/feature_flags.php';
require_once __DIR__ . '/email_required.php';

if (session_status() === PHP_SESSION_NONE) {
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $secureCookie = !($esLocal ?? false) || parse_url(APP_URL, PHP_URL_SCHEME) === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (getenv('MM_TRUST_PROXY') === '1' && $forwardedProto === 'https');
    $sessionPath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
    if ($sessionPath === '//') {
        $sessionPath = '/';
    }
    session_name(defined('MUSICMAD_TEST_RUN') && MUSICMAD_TEST_RUN === true && PHP_SAPI === 'cli-server' ? 'MUSICMADQASESSID' : 'MUSICMADSESSID');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $sessionPath,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secureCookie,
    ]);
    session_start();
    header('Cache-Control: no-store, private, max-age=0');
}

const COLOR_PERFIL_DEFECTO = '#d2a679';
const AVATAR_DEFECTO = 'img/avatares/musicos/m0.svg';
const COOKIE_RECORDAR = 'MUSICMADREMEMBER';
const RECORDAR_SEGUNDOS = 2592000; // 30 días

// ── Helpers de sesión ───────────────────────────────────────

function estaAutenticado(): bool
{
    return !empty($_SESSION['usuario_id']) && validarSesionActual();
}

function esAdmin(): bool
{
    return estaAutenticado() && ($_SESSION['usuario_rol'] ?? '') === 'administrador';
}

/** Indica si el acceso opcional con Google tiene configuración completa. */
function googleOAuthConfigurado(): bool
{
    return musicmadConfigValue('GOOGLE_CLIENT_ID') !== ''
        && musicmadConfigValue('GOOGLE_CLIENT_SECRET') !== ''
        && musicmadConfigValue('GOOGLE_REDIRECT_URI') !== '';
}

/** Solo administradores; cualquier otro va a la página principal. */
function requerirAdmin(): void
{
    if (!esAdmin()) {
        header('Location: index.php');
        exit;
    }
}

/** Compatibilidad con el nombre anterior. */
function requerirLogin(): void
{
    requerirAdmin();
}

/** Cualquier usuario con sesión; si no, al login. */
function requerirSesion(): void
{
    if (!estaAutenticado()) {
        $pagina = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        $_SESSION['destino_post_login'] = destinoLocalSeguro($pagina . ($query !== '' ? '?' . $query : ''));
        header('Location: login.php?acceso=1');
        exit;
    }
}

function redirigirSiAutenticado(): void
{
    if (estaAutenticado()) {
        header('Location: feed.php');
        exit;
    }
}

function sesion(string $clave): mixed
{
    return $_SESSION[$clave] ?? null;
}

function cerrarSesion(): void
{
    if (!empty($_SESSION['device_selector']) && registroSesionesDisponible()) {
        getDB()->prepare('UPDATE sesiones_dispositivos SET revocada_en=UTC_TIMESTAMP() WHERE selector=? AND usuario_id=?')->execute([$_SESSION['device_selector'],(int)sesion('usuario_id')]);
    }
    revocarCookieRecordada();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Mantiene el acceso en este dispositivo con un token aleatorio revocable. */
function recordarSesionUsuario(int $usuarioId): void
{
    if ($usuarioId < 1) {
        return;
    }
    try {
        $params = session_get_cookie_params();
        $previo = $_COOKIE[COOKIE_RECORDAR] ?? '';
        if (is_string($previo) && preg_match('/^([a-f0-9]{32})\.[a-f0-9]{64}$/', $previo, $m)) {
            getDB()->prepare('DELETE FROM sesiones_recordadas WHERE selector = :s')->execute([':s' => $m[1]]);
        }

        $selector = bin2hex(random_bytes(16));
        $validador = bin2hex(random_bytes(32));
        getDB()->prepare(
            'INSERT INTO sesiones_recordadas (selector, usuario_id, validador_hash, expira_en)
             VALUES (:s, :u, :v, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))'
        )->execute([':s' => $selector, ':u' => $usuarioId, ':v' => hash('sha256', $validador)]);

        $_SESSION['remember_selector'] = $selector;
        setcookie(COOKIE_RECORDAR, $selector . '.' . $validador, [
            'expires' => time() + RECORDAR_SEGUNDOS,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (Throwable $e) {
        error_log('No se pudo emitir el acceso recordado; importa social2.sql actualizado: ' . get_class($e));
    }
}

/** Revoca el token del dispositivo actual y borra su cookie persistente. */
function revocarCookieRecordada(): void
{
    $params = session_get_cookie_params();
    $valor = $_COOKIE[COOKIE_RECORDAR] ?? '';
    if (is_string($valor) && preg_match('/^([a-f0-9]{32})\.[a-f0-9]{64}$/', $valor, $m)) {
        try {
            getDB()->prepare('DELETE FROM sesiones_recordadas WHERE selector = :s')->execute([':s' => $m[1]]);
        } catch (Throwable $e) {
            error_log('No se pudo revocar el acceso recordado: ' . get_class($e));
        }
    }
    unset($_COOKIE[COOKIE_RECORDAR],$_SESSION['remember_selector']);
    setcookie(COOKIE_RECORDAR, '', [
        'expires' => time() - 3600,
        'path' => $params['path'],
        'secure' => $params['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Restaura la cuenta tras cerrar el navegador, validando el token en MySQL. */
function restaurarSesionRecordada(): void
{
    if (estaAutenticado()) {
        return;
    }
    $valor = $_COOKIE[COOKIE_RECORDAR] ?? '';
    if (!is_string($valor) || !preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/', $valor, $m)) {
        return;
    }
    $db = getDB();
    try {
        $db->beginTransaction();
        $st = $db->prepare(
            'SELECT u.id, u.nombre, u.apellido, u.avatar, u.bio, u.color_perfil, u.email, u.rol,
                    t.validador_hash
               FROM sesiones_recordadas t
               JOIN usuarios u ON u.id = t.usuario_id AND u.activo = 1
              WHERE t.selector = :s AND t.expira_en > UTC_TIMESTAMP() LIMIT 1 FOR UPDATE'
        );
        $st->execute([':s' => $m[1]]);
        $u = $st->fetch();
        if (!$u || !hash_equals((string) $u['validador_hash'], hash('sha256', $m[2]))) {
            revocarCookieRecordada();
            return;
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf'], $_SESSION['offline_scope']);
        $_SESSION['usuario_id'] = (int) $u['id'];
        $_SESSION['usuario_nombre'] = $u['nombre'];
        $_SESSION['usuario_apellido'] = $u['apellido'];
        $_SESSION['usuario_email'] = $u['email'];
        $_SESSION['usuario_rol'] = $u['rol'];
        $_SESSION['usuario_avatar'] = $u['avatar'] ?: AVATAR_DEFECTO;
        $_SESSION['usuario_bio'] = $u['bio'] ?? '';
        $_SESSION['usuario_color'] = $u['color_perfil'] ?: COLOR_PERFIL_DEFECTO;
        registrarDispositivoSesion((int)$u['id'], $m[1]);
        iniciarPlazoVerificacion((int) $u['id']);
        $db->commit();
    } catch (Throwable $e) {
        error_log('No se pudo restaurar la sesión recordada; importa social2.sql actualizado: ' . get_class($e));
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

function e(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

/** Acepta solo valores de formulario escalares de texto. */
function textoCampo(array $datos, string $campo): string
{
    $valor = $datos[$campo] ?? '';
    return is_string($valor) ? $valor : '';
}

/** Convierte un ID de formulario únicamente si es un entero positivo válido. */
function idCampo(array $datos, string $campo): int
{
    $valor = $datos[$campo] ?? null;
    if (is_int($valor)) {
        return $valor > 0 ? $valor : 0;
    }
    if (!is_string($valor) || $valor === '' || !ctype_digit($valor)) {
        return 0;
    }
    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? 0 : $id;
}

/** Huellas actual y temporal anterior durante la migración HMAC. */
function huellaIntentoLogin(string $email): array
{
    return huellasLogin($email);
}
function huellaIntentoRegistro(): array
{
    return huellasRegistro();
}
function loginBloqueado(array|string $huella): bool
{
    try {
        return limiteBloqueado(getDB(), (array) $huella);
    } catch (Throwable $e) {
        error_log('Control de intentos no disponible.');
        return true;
    }
}
function registrarIntentoLogin(array|string $huella, bool $correcto): bool
{
    try {
        guardarIntentoLimite(getDB(), (array) $huella, $correcto);
        return true;
    } catch (Throwable $e) {
        error_log('No se pudo registrar el intento de acceso.');
        return false;
    }
}

function dinero(float|string $valor): string
{
    return '$' . number_format((float) $valor, 2);
}

// ── CSRF ────────────────────────────────────────────────────

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfCampo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function csrfValido(): bool
{
    $enviado = $_POST['csrf'] ?? '';
    $esperado = $_SESSION['csrf'] ?? '';
    return is_string($enviado) && is_string($esperado) && $enviado !== '' && hash_equals($esperado, $enviado);
}

/** Corta la petición POST si el token no coincide. */
function exigirCsrf(): void
{
    if (!csrfValido()) {
        http_response_code(403);
        die('La sesión expiró o el formulario no es válido. Regresa y vuelve a intentarlo.');
    }
}

// ── Registro ────────────────────────────────────────────────

function registrarUsuario(array $datos, ?callable $dns = null): array
{
    $nombre = trim(textoCampo($datos, 'nombre'));
    $apellido = trim(textoCampo($datos, 'apellido'));
    $email = trim(textoCampo($datos, 'email'));
    $REPLACE_WITH_LOCAL_VALUE = textoCampo($datos, 'REPLACE_WITH_LOCAL_VALUE');
    $confirm = textoCampo($datos, 'confirm');

    if ($nombre === '' || $apellido === '' || $email === '' || $REPLACE_WITH_LOCAL_VALUE === '') {
        return ['ok' => false, 'mensaje' => 'Todos los campos son obligatorios.'];
    }
    $policy = evaluarCorreoRegistro($email, $dns);
    if (!$policy['ok']) {
        return $policy;
    }
    if (!verificacionObligatoriaDisponible() || !correoConfigurado()) {
        return ['ok' => false, 'mensaje' => 'El registro necesita el servicio de correo. Inténtalo cuando esté disponible.'];
    }
    if (mb_strlen($nombre) > 100 || mb_strlen($apellido) > 100 || strlen($email) > 254) {
        return ['ok' => false, 'mensaje' => 'El nombre, apellido o correo supera el límite permitido.'];
    }
    if (mb_strlen($REPLACE_WITH_LOCAL_VALUE) < 12 || strlen($REPLACE_WITH_LOCAL_VALUE) > 72 || str_contains($REPLACE_WITH_LOCAL_VALUE, chr(0))) {
        return ['ok' => false, 'mensaje' => 'La contraseña debe tener al menos 12 caracteres y máximo 72 bytes.'];
    }
    if ($REPLACE_WITH_LOCAL_VALUE !== $confirm) {
        return ['ok' => false, 'mensaje' => 'Las contraseñas no coinciden.'];
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'mensaje' => 'Ya existe una cuenta con ese correo.'];
    }

    $ownsRegistration = !$pdo->inTransaction();
    if ($ownsRegistration) {
        $pdo->beginTransaction();
    }
    try {
        // El rol NUNCA viene del formulario: todo registro público es "cliente".
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nombre, apellido, avatar, bio, color_perfil, email, REPLACE_WITH_LOCAL_VALUE, rol)
         VALUES (:nombre, :apellido, :avatar, :bio, :color, :email, :REPLACE_WITH_LOCAL_VALUE, :rol)'
        );
        $stmt->execute([
            ':nombre' => $nombre,
            ':apellido' => $apellido,
            ':avatar' => AVATAR_DEFECTO,
            ':bio' => '',
            ':color' => COLOR_PERFIL_DEFECTO,
            ':email' => $email,
            ':REPLACE_WITH_LOCAL_VALUE' => REPLACE_WITH_LOCAL_VALUE_hash($REPLACE_WITH_LOCAL_VALUE, PASSWORD_BCRYPT, ['cost' => 12]),
            ':rol' => 'cliente',
        ]);

        $newUid = (int)$pdo->lastInsertId();
        iniciarOnboardingCuentaNueva($newUid);
        if (idiomaCuentaDisponible()) {
            guardarIdiomaCuenta($newUid, idiomaMusicMad());
        }
        iniciarPlazoVerificacion($newUid);
        solicitarCorreoCuenta($email, 'verificar');
        if ($ownsRegistration) {
            $pdo->commit();
            if (parametroCorreoMusicMad('TRANSPORT') === 'mail' && PHP_SAPI !== 'cli') {
                try {
                    $pendingMail = $pdo->prepare("SELECT dedupe FROM musicmad_jobs WHERE usuario_id=? AND tipo='correo' AND estado='pendiente' AND intentos=0 ORDER BY id DESC LIMIT 1");
                    $pendingMail->execute([$newUid]);
                    $mailReference = $pendingMail->fetchColumn();
                    if (is_string($mailReference)) {
                        despacharCorreoInmediato($mailReference);
                    }
                } catch (Throwable $mailError) {
                    // The account is committed; a transport failure cannot undo registration.
                    error_log('MusicMad registration mail pending: ' . get_class($mailError));
                }
            }
        }
        return ['ok' => true, 'mensaje' => 'Cuenta creada. Abre tu correo para confirmarla.', 'id' => $newUid];
    } catch (PDOException $error) {
        if ($ownsRegistration && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int)($error->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false,'mensaje' => 'Ya existe una cuenta con ese correo.'];
        }
        throw $error;
    } catch (Throwable $error) {
        if ($ownsRegistration && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

}

// ── Login ───────────────────────────────────────────────────

function completarAccesoUsuario(array $u, bool $recordar): void
{
    iniciarPlazoVerificacion((int) $u['id']);
    session_regenerate_id(true);
    unset($_SESSION['csrf'],$_SESSION['offline_scope'],$_SESSION['device_selector'],$_SESSION['auth_version'],$_SESSION['login_pendiente']);

    $_SESSION['usuario_id'] = (int) $u['id'];
    $_SESSION['usuario_nombre'] = $u['nombre'];
    $_SESSION['usuario_apellido'] = $u['apellido'];
    $_SESSION['usuario_email'] = $u['email'];
    $_SESSION['usuario_rol'] = $u['rol'];
    $_SESSION['usuario_avatar'] = $u['avatar'] ?: AVATAR_DEFECTO;
    $_SESSION['usuario_bio'] = $u['bio'] ?? '';
    $_SESSION['usuario_color'] = $u['color_perfil'] ?: COLOR_PERFIL_DEFECTO;
    if ($recordar) {
        recordarSesionUsuario((int) $u['id']);
    } else {
        revocarCookieRecordada();
    }

    registrarDispositivoSesion((int)$u['id'], $_SESSION['remember_selector'] ?? null);

}
function prepararRetoAcceso(int $uid, bool $remember): void
{
    $st = getDB()->prepare('SELECT REPLACE_WITH_LOCAL_VALUE FROM usuarios WHERE id=? AND activo=1');
    $st->execute([$uid]);
    $hash = $st->fetchColumn();
    if (!is_string($hash)) {
        throw new RuntimeException('Cuenta no disponible.');
    }
    revocarCookieRecordada();
    session_regenerate_id(true);
    unset($_SESSION['csrf']);
    $_SESSION['login_pendiente'] = ['uid' => $uid,'stamp' => hash('sha256', $hash),'recordar' => $remember,'expira' => time() + 300];
}

function iniciarSesion(string $email, string $REPLACE_WITH_LOCAL_VALUE, bool $recordar = true): array
{
    $email = trim($email);

    if ($email === '' || $REPLACE_WITH_LOCAL_VALUE === '' || strlen($REPLACE_WITH_LOCAL_VALUE) > 72 || str_contains($REPLACE_WITH_LOCAL_VALUE, "\0")) {
        return ['ok' => false, 'mensaje' => 'Completa todos los campos.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'mensaje' => 'Correo electrónico no válido.'];
    }

    try {
        $huella = huellaIntentoLogin($email);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'mensaje' => $e->getMessage()];
    }
    if (loginBloqueado($huella)) {
        return ['ok' => false, 'mensaje' => 'Demasiados intentos. Espera 15 minutos antes de probar otra vez.'];
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT id, nombre, apellido, avatar, bio, color_perfil, email, REPLACE_WITH_LOCAL_VALUE, rol, activo
         FROM usuarios WHERE email = :email LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':email' => $email]);
        $u = $stmt->fetch();

        $REPLACE_WITH_LOCAL_VALUEMatches = verificarPasswordConTiempo($REPLACE_WITH_LOCAL_VALUE, $u ? $u['REPLACE_WITH_LOCAL_VALUE'] : null);
        if (!$u || !$REPLACE_WITH_LOCAL_VALUEMatches) {
            if (!registrarIntentoLogin($huella, false)) {
                return ['ok' => false,'mensaje' => 'El control de acceso no está disponible.'];
            }
            $db->commit();
            return ['ok' => false, 'mensaje' => '¡Credenciales incorrectas!'];
        }
        if (!registrarIntentoLogin($huella, true)) {
            return ['ok' => false, 'mensaje' => 'El control de acceso no está disponible. Inténtalo más tarde.'];
        }
        if (!$u['activo']) {
            return ['ok' => false, 'mensaje' => 'Tu cuenta está desactivada. Contacta al administrador.'];
        }

        if (tieneSegundoFactor((int)$u['id'])) {
            prepararRetoAcceso((int)$u['id'], $recordar);
            $db->commit();
            return ['ok' => false,'segundo_factor' => true,'mensaje' => 'Confirma tu código de seguridad.'];
        }
        completarAccesoUsuario($u, $recordar);
        $db->commit();
        return ['ok' => true, 'mensaje' => 'Bienvenido/a, ' . $u['nombre'] . '.'];
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

// ── Perfil ──────────────────────────────────────────────────

function avataresDisponibles(): array
{
    $avatares = [];
    for ($i = 0; $i < 10; $i++) {
        $avatares[] = "img/avatares/musicos/m{$i}.svg";
    }
    return $avatares;
}

function obtenerPerfil(int $usuarioId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT id, nombre, apellido, avatar, bio, color_perfil, email, rol
         FROM usuarios WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $usuarioId]);
    return $stmt->fetch() ?: null;
}

function actualizarPerfil(int $usuarioId, array $datos): array
{
    $avatar = textoCampo($datos, 'avatar');
    $bio = trim(textoCampo($datos, 'bio'));
    $color = textoCampo($datos, 'color') ?: COLOR_PERFIL_DEFECTO;

    $avatarPersonal = preg_match(
        '#^uploads/avatars/' . preg_quote((string) $usuarioId, '#') . '_[a-f0-9]{24}\.jpg$#',
        $avatar
    ) === 1 && is_file(musicmadMediaDirectoryForFile('avatars', basename($avatar)) . '/' . basename($avatar)) && !is_link(musicmadMediaDirectoryForFile('avatars', basename($avatar)) . '/' . basename($avatar));
    if (!in_array($avatar, avataresDisponibles(), true) && !$avatarPersonal) {
        return ['ok' => false, 'mensaje' => 'Selecciona un avatar válido.'];
    }
    if (mb_strlen($bio) > 280) {
        $bio = mb_substr($bio, 0, 280);
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = COLOR_PERFIL_DEFECTO;
    }

    getDB()->prepare('UPDATE usuarios SET avatar = :a, bio = :b, color_perfil = :c WHERE id = :id')
           ->execute([':a' => $avatar, ':b' => $bio, ':c' => $color, ':id' => $usuarioId]);

    $_SESSION['usuario_avatar'] = $avatar;
    $_SESSION['usuario_bio'] = $bio;
    $_SESSION['usuario_color'] = $color;

    return ['ok' => true, 'mensaje' => '¡Perfil actualizado correctamente!'];
}

// ── Catálogo / carrito (compartido por varias páginas) ──────

/** Devuelve el id del carrito del usuario; lo crea si $crear es true. */
function carritoId(int $usuarioId, bool $crear = false): ?int
{
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM carrito WHERE usuario_id = :u LIMIT 1');
    $stmt->execute([':u' => $usuarioId]);
    $id = $stmt->fetchColumn();

    if ($id) {
        return (int) $id;
    }
    if (!$crear) {
        return null;
    }
    $db->prepare('INSERT INTO carrito (usuario_id) VALUES (:u)')->execute([':u' => $usuarioId]);
    return (int) $db->lastInsertId();
}

/** Lista de imágenes de la galería de un producto (para sliders). */
function galeriaProducto(array $p): array
{
    $lista = array_filter(array_map('trim', explode(',', $p['galeria'] ?? '')));
    return $lista ?: ($p['imagen_url'] ? [$p['imagen_url']] : []);
}

// Ejecutar al cargar el autenticador, antes de resolver permisos de cada página.
restaurarSesionRecordada();
exigirFlagRutaMusicMad();
exigirCorreoEnEscrituraWeb();
exigirOnboardingWeb();
