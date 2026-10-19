<?php

// Disposable local fixtures only. This file must not be packaged or served in production.
if (PHP_SAPI !== 'cli') {
    exit;
}
putenv('MM_RUN_QA=1');
require __DIR__ . '/bootstrap.php';
if (DB_NAME !== 'musicmad_qa') {
    throw new RuntimeException('QA only.');
}
$id = (int)($argv[1] ?? 0);
$band = obtenerBanda($id);
if (!$band || !str_ends_with($band['nombre'], ' QA') || (int)$band['creador_id'] !== 1) {
    throw new RuntimeException('Expected fictitious band.');
}
if (rolEnBanda(2, $id) === null) {
    responderInvitacionBanda(2, $id, true, 'Batería');
}
$st = getDB()->prepare('SELECT MAX(id) FROM mensajes_banda WHERE banda_id=?');
$st->execute([$id]);
marcarChatBanda(2, $id, (int)$st->fetchColumn());
enviarChatBanda(2, $id, 'Buen riff. Lo vemos en el ensayo. Respuesta ficticia QA.', bin2hex(random_bytes(16)), 1);
echo "Demo QA preparada.\n";
