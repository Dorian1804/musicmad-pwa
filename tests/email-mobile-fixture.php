<?php

if (PHP_SAPI !== 'cli' || getenv('MM_RUN_QA') !== '1') {
    exit(2);
}
require __DIR__ . '/bootstrap.php';
if (DB_NAME !== 'musicmad_qa') {
    exit(2);
}
if (($argv[1] ?? '') === 'create') {
    $email = 'email-mobile-' . bin2hex(random_bytes(12)) . '@musicmad.invalid';
    $REPLACE_WITH_LOCAL_VALUE = 'MusicMad_Email_Mobile_QA_42!';
    getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')
        ->execute(['Correo', 'QA móvil', $email, REPLACE_WITH_LOCAL_VALUE_hash($REPLACE_WITH_LOCAL_VALUE, PASSWORD_BCRYPT)]);
    echo json_encode(['uid' => (int) getDB()->lastInsertId(), 'email' => $email, 'REPLACE_WITH_LOCAL_VALUE' => $REPLACE_WITH_LOCAL_VALUE]);
} elseif (($argv[1] ?? '') === 'cleanup' && ctype_digit($argv[2] ?? '')) {
    getDB()->prepare("DELETE FROM usuarios WHERE id=? AND email LIKE 'email-mobile-%@musicmad.invalid'")->execute([(int) $argv[2]]);
} else {
    exit(2);
}
