<?php

// Local terminal only. No shared/default REPLACE_WITH_LOCAL_VALUE is created or printed.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/includes/auth.php';
$email = (string)getenv('MM_ADMIN_EMAIL');
$REPLACE_WITH_LOCAL_VALUE = (string)getenv('MM_ADMIN_PASSWORD');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || mb_strlen($REPLACE_WITH_LOCAL_VALUE) < 12 || strlen($REPLACE_WITH_LOCAL_VALUE) > 72 || str_contains($REPLACE_WITH_LOCAL_VALUE, chr(0))) {
    fwrite(STDERR, "Configura MM_ADMIN_EMAIL y MM_ADMIN_PASSWORD en el entorno privado de la terminal. Contraseña de12 caracteres o más, máximo72 bytes.\n");
    exit(1);
}
$db = getDB();
$lockName = 'musicmad-bootstrap-' . substr(hash('sha256', DB_NAME), 0, 40);
$locked = false;
try {
    $lock = $db->prepare('SELECT GET_LOCK(?,5)');
    $lock->execute([$lockName]);
    $locked = (int)$lock->fetchColumn() === 1;
    if (!$locked) {
        throw new RuntimeException('Bootstrap ocupado.');
    }
    $db->beginTransaction();
    $st = $db->prepare('SELECT id FROM usuarios WHERE rol=? AND activo=1 LIMIT 1 FOR UPDATE');
    $st->execute(['administrador']);
    if ($st->fetchColumn()) {
        fwrite(STDERR, "Ya hay un administrador activo. No se cambió ninguna cuenta.\n");
        $db->rollBack();
        exit(1);
    }
    $db->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,rol) VALUES(?,?,?,?,?)')->execute(['Administrador','MusicMad',$email,REPLACE_WITH_LOCAL_VALUE_hash($REPLACE_WITH_LOCAL_VALUE, PASSWORD_BCRYPT, ['cost' => 12]),'administrador']);
    $db->commit();
    echo "Primer administrador creado. Inicia sesión con tus datos.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Administrator bootstrap: ' . get_class($error));
    fwrite(STDERR, "No se pudo crear el administrador. Revisa los datos y la configuración privada.\n");
    exit(1);
} finally {
    if ($locked) {
        $st = $db->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute([$lockName]);
    }
}
