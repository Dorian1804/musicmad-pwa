<?php

if (PHP_SAPI !== 'cli' || getenv('MM_RUN_QA') !== '1') {
    exit(1);
}
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/chat_live.php';
if (DB_NAME !== 'musicmad_qa') {
    exit(1);
}
$message = enviarChatSeguro(2, 1, 'Respuesta ficticia en vivo: ¡vamos a ensayar! 🎶', bin2hex(random_bytes(16)), 1);
marcarChatHasta(2, 1, PHP_INT_MAX);
actividadChat(2, 1, true);
echo 'Respuesta QA creada: ' . $message['id'] . "\n";
