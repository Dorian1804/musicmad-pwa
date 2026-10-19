<?php

function onboardingDisponible(): bool
{
    static $ready;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?)');
    $st->execute(['musicmad_onboarding_required', 'musicmad_onboarding_receipts']);
    return $ready = (int) $st->fetchColumn() === 2;
}

function iniciarOnboardingCuentaNueva(int $uid): void
{
    if (onboardingDisponible()) {
        getDB()->prepare('INSERT IGNORE INTO musicmad_onboarding_required(usuario_id) SELECT id FROM usuarios WHERE id=? AND activo=1')->execute([$uid]);
    }
}

/** No implicit writes or inherited completion from the old optional tutorial. */
function estadoOnboarding(int $uid): ?array
{
    if (!onboardingDisponible()) {
        return null;
    }
    $st = getDB()->prepare('SELECT o.paso,o.revision,o.completado_en,o.notificaciones,o.instalacion FROM usuarios u LEFT JOIN musicmad_onboarding_required o ON o.usuario_id=u.id WHERE u.id=? AND u.activo=1');
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    return $row['paso'] === null ? ['paso' => 1, 'revision' => 0, 'completado_en' => null, 'notificaciones' => 'pendiente', 'instalacion' => 'pendiente'] : $row;
}

function onboardingCompleto(int $uid): bool
{
    $state = estadoOnboarding($uid);
    return $state !== null && (int) $state['paso'] === 6 && $state['completado_en'] !== null;
}

function destinoAccesoMusicMad(int $uid, string $requested = 'feed.php'): string
{
    if ($pending = destinoCorreoPendiente($uid)) {
        return $pending;
    }
    return onboardingCompleto($uid) ? destinoLocalSeguro($requested) : 'bienvenida.php';
}

function datosPasoOnboarding(array $data, int $step, bool $skip = false): array
{
    if ($skip) {
        throw new InvalidArgumentException('Completa cada paso para preparar tu escena.');
    }
    if ($step === 1) {
        $instrument = trim(textoCampo($data, 'instrumento'));
        $city = trim(textoCampo($data, 'ciudad'));
        if (mb_strlen($instrument) < 2 || mb_strlen($instrument) > 60 || mb_strlen($city) > 80 || preg_match('/[\x00-\x1f\x7f]/', $instrument . $city)) {
            throw new InvalidArgumentException('Indica al menos un instrumento de 2 a 60 caracteres.');
        }
        return ['instrumento' => $instrument, 'ciudad' => $city];
    }
    if ($step === 2) {
        $genres = $data['generos'] ?? null;
        if (!is_array($genres) || count($genres) > 6) {
            throw new InvalidArgumentException('Elige de tres a seis géneros.');
        }
        foreach ($genres as $genre) {
            if (!is_string($genre) || !in_array($genre, GENEROS, true)) {
                throw new InvalidArgumentException('Elige géneros de la lista.');
            }
        }
        $genres = array_values(array_unique($genres));
        if (count($genres) < 3) {
            throw new InvalidArgumentException('Elige al menos tres géneros diferentes.');
        }
        return ['generos' => $genres];
    }
    if ($step === 3) {
        $raw = $data['musicos'] ?? null;
        if (!is_array($raw) || count($raw) > 12) {
            throw new InvalidArgumentException('Elige de tres a doce músicos.');
        }
        $ids = [];
        foreach ($raw as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id) || (int) $id > 4294967295) {
                throw new InvalidArgumentException('Selección de músicos no válida.');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) < 3) {
            throw new InvalidArgumentException('Elige al menos tres perfiles distintos.');
        }
        sort($ids, SORT_NUMERIC);
        return ['musicos' => $ids];
    }
    if ($step === 4) {
        return ['slug' => validarAliasPortafolio(textoCampo($data, 'slug'))];
    }
    if ($step === 5) {
        $notifications = textoCampo($data, 'notificaciones');
        $installation = textoCampo($data, 'instalacion');
        if (textoCampo($data, 'dispositivo_revisado') !== '1' || !in_array($notifications, ['activa','sin_activar','bloqueada','sin_soporte','servicio_pendiente'], true) || !in_array($installation, ['standalone','instrucciones'], true)) {
            throw new InvalidArgumentException('Revisa las opciones de instalación y avisos de tu dispositivo.');
        }
        return ['notificaciones' => $notifications, 'instalacion' => $installation];
    }
    throw new InvalidArgumentException('Paso no válido.');
}

function validarMinimosOnboarding(int $uid): void
{
    $music = obtenerPerfilMusical($uid);
    if (mb_strlen(trim((string) $music['instrumento'])) < 2 || count(array_intersect(GENEROS, array_unique(array_map('trim', explode(',', $music['generos']))))) < 3) {
        throw new DomainException('Revisa tu instrumento y elige al menos tres géneros antes de terminar.');
    }
    [$policy, $params] = filtroAccesoContenido($uid, 'u.id', 'onboard_minimum');
    $st = getDB()->prepare('SELECT COUNT(*) FROM seguidores s JOIN usuarios u ON u.id=s.seguido_id AND u.activo=1 WHERE s.seguidor_id=:minimum_uid AND s.seguido_id<>:minimum_self AND (' . $policy . ')');
    $st->execute([':minimum_uid' => $uid, ':minimum_self' => $uid] + $params);
    if ((int) $st->fetchColumn() < 3) {
        throw new DomainException('Sigue a tres músicos activos antes de terminar.');
    }
    $st = getDB()->prepare('SELECT slug FROM perfiles_publicos WHERE usuario_id=?');
    $st->execute([$uid]);
    validarAliasPortafolio((string) $st->fetchColumn());
}

function guardarPasoOnboarding(PDO $db, int $uid, int $step, array $payload): void
{
    if ($step === 1) {
        $db->prepare('INSERT INTO perfiles_musicales(usuario_id,instrumento,ciudad) VALUES(?,?,?) ON DUPLICATE KEY UPDATE instrumento=VALUES(instrumento),ciudad=VALUES(ciudad)')->execute([$uid,$payload['instrumento'],$payload['ciudad']]);
    }
    if ($step === 2) {
        $db->prepare('INSERT INTO perfiles_musicales(usuario_id,generos) VALUES(?,?) ON DUPLICATE KEY UPDATE generos=VALUES(generos)')->execute([$uid,implode(', ', $payload['generos'])]);
    }
    if ($step === 3) {
        foreach ($payload['musicos'] as $other) {
            $st = $db->prepare("SELECT u.id FROM usuarios u LEFT JOIN perfiles_privacidad p ON p.usuario_id=u.id WHERE u.id=? AND u.activo=1 AND COALESCE(p.visibilidad,'publico')='publico' FOR UPDATE");
            $st->execute([$other]);
            if ($other === $uid || !$st->fetchColumn() || !puedeVerPerfilSocial3($uid, $other)) {
                throw new OutOfBoundsException('Un perfil elegido ya no está disponible. Elige otro.');
            }
        }
        foreach ($payload['musicos'] as $other) {
            $st = $db->prepare('INSERT IGNORE INTO seguidores(seguidor_id,seguido_id) VALUES(?,?)');
            $st->execute([$uid,$other]);
            if ($st->rowCount()) {
                notificar($other, $uid, 'seguidor');
                solicitarHitosArtista($other);
            }
        }
    }
    if ($step === 4) {
        // Updating by owner prevents a duplicate alias from changing its owner.
        $st = $db->prepare('SELECT usuario_id FROM perfiles_publicos WHERE usuario_id=? FOR UPDATE');
        $st->execute([$uid]);
        if ($st->fetchColumn()) {
            $db->prepare('UPDATE perfiles_publicos SET slug=? WHERE usuario_id=?')->execute([$payload['slug'],$uid]);
        } else {
            $db->prepare('INSERT INTO perfiles_publicos(usuario_id,slug) VALUES(?,?)')->execute([$uid,$payload['slug']]);
        }
    }
    if ($step === 5) {
        validarMinimosOnboarding($uid);
        if ($payload['notificaciones'] === 'activa') {
            $st = $db->prepare('SELECT 1 FROM push_subscriptions p JOIN musicmad_push_bindings b ON b.subscription_id=p.id JOIN sesiones_dispositivos d ON d.selector=b.device_selector AND d.revocada_en IS NULL WHERE p.usuario_id=? AND d.usuario_id=? AND b.device_selector=? LIMIT 1');
            $st->execute([$uid,$uid,(string) ($_SESSION['device_selector'] ?? '')]);
            if (!$st->fetchColumn()) {
                throw new DomainException('Activa los avisos antes de marcarlos como activos.');
            }
        } elseif ($payload['notificaciones'] === 'servicio_pendiente' && pushConfigurado()) {
            throw new DomainException('El servicio de avisos está listo. Revisa el permiso del navegador.');
        }
        $db->prepare('UPDATE musicmad_onboarding_required SET notificaciones=?,instalacion=? WHERE usuario_id=?')->execute([$payload['notificaciones'],$payload['instalacion'],$uid]);
    }
}

function actualizarOnboarding(int $uid, array $data): void
{
    if (!onboardingDisponible()) {
        throw new RuntimeException('Importa la actualización del tutorial para continuar.');
    }
    $request = textoCampo($data, 'solicitud');
    $action = textoCampo($data, 'accion');
    $step = idCampo($data, 'paso');
    $revision = idCampo($data, 'revision');
    if (!preg_match('/^[a-f0-9]{32}$/D', $request) || !in_array($action, ['guardar','comenzar'], true)) {
        throw new InvalidArgumentException('Completa el paso actual. No se pueden omitir pasos.');
    }
    $payload = $action === 'comenzar' ? [] : datosPasoOnboarding($data, $step);
    $hash = hash('sha256', json_encode(['required-v1',$action,$step,$revision,$payload], JSON_THROW_ON_ERROR));
    limiteChat($uid, 'onboard_change', 30);
    $db = getDB();
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$uid]);
        if (!$st->fetchColumn() || !correoCuentaVerificado($uid)) {
            throw new OutOfBoundsException('Confirma tu cuenta para completar el recorrido.');
        }
        $st = $db->prepare('SELECT payload_hash FROM musicmad_onboarding_receipts WHERE usuario_id=? AND solicitud=?');
        $st->execute([$uid,$request]);
        $old = $st->fetchColumn();
        if ($old !== false) {
            if (!hash_equals($old, $hash)) {
                throw new UnexpectedValueException('Ese envío ya tiene otro contenido.');
            }
            if ($own) {
                $db->commit();
            }
            return;
        }
        $db->prepare('INSERT IGNORE INTO musicmad_onboarding_required(usuario_id,revision) VALUES(?,0)')->execute([$uid]);
        $st = $db->prepare('SELECT paso,revision FROM musicmad_onboarding_required WHERE usuario_id=? FOR UPDATE');
        $st->execute([$uid]);
        $state = $st->fetch();
        if ((int) $state['paso'] !== $step || (int) $state['revision'] !== $revision) {
            throw new DomainException('Tu recorrido cambió en otra pestaña. Revisa el paso actual.');
        }
        if ($action === 'comenzar') {
            $db->prepare('UPDATE musicmad_onboarding_required SET paso=1,revision=revision+1,completado_en=NULL WHERE usuario_id=?')->execute([$uid]);
        } else {
            guardarPasoOnboarding($db, $uid, $step, $payload);
            $db->prepare('UPDATE musicmad_onboarding_required SET paso=?,revision=revision+1,completado_en=IF(?=6,UTC_TIMESTAMP(),NULL) WHERE usuario_id=?')->execute([$step + 1,$step + 1,$uid]);
        }
        $db->prepare('INSERT INTO musicmad_onboarding_receipts(usuario_id,solicitud,payload_hash,created_at) VALUES(?,?,?,UTC_TIMESTAMP())')->execute([$uid,$request,$hash]);
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $error) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new InvalidArgumentException('Ese alias ya está ocupado. Elige otro.');
        }
        throw $error;
    }
}

function exigirOnboardingWeb(): void
{
    if (PHP_SAPI === 'cli' || empty($_SESSION['usuario_id']) || !estaAutenticado()) {
        return;
    }
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowed = ['idioma.php', 'login.php','registro.php','logout.php','dos-pasos.php','google-inicio.php','google-regreso.php','confirmar-correo.php','verificar-correo.php','recuperar.php','restablecer.php','cambiar-REPLACE_WITH_LOCAL_VALUE.php','eliminar-cuenta.php','exportar-datos.php','datos.php','seguridad.php','bienvenida.php','bienvenida-accion.php'];
    if (in_array($page, $allowed, true)) {
        return;
    }
    $uid = (int) $_SESSION['usuario_id'];
    if ($page === 'push-suscripcion.php' && correoCuentaVerificado($uid) && (int) (estadoOnboarding($uid)['paso'] ?? 0) === 5) {
        return;
    }
    if ($page === 'configuracion.php' && in_array($_POST['accion'] ?? '', ['verificar_correo','revocar_dispositivo','revocar_otros'], true)) {
        return;
    }
    if (onboardingCompleto($uid)) {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_SESSION['destino_post_login'])) {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $_SESSION['destino_post_login'] = destinoLocalSeguro($page . ($query !== '' ? '?' . $query : ''));
    }
    $target = destinoCorreoPendiente($uid) ?? 'bienvenida.php';
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_SERVER['HTTP_X_MUSICMAD_CSRF']) || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false,'error' => 'onboarding_required','mensaje' => mmTexto('Completa tu recorrido'),'redirect' => $target], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: ' . $target, true, in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true) ? 302 : 303);
    }
    exit;
}

function limpiarRecibosOnboarding(): void
{
    if (onboardingDisponible()) {
        getDB()->exec('DELETE FROM musicmad_onboarding_receipts WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY) LIMIT 1000');
    }
}
