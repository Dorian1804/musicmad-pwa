<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/social.php';
require_once dirname(__DIR__) . '/includes/chat_live.php';
$db = getDB();
$removed = 0;
try {
    $st = $db->prepare("SELECT id FROM musicmad_uploads WHERE estado<>'completo' AND expira_en<UTC_TIMESTAMP() ORDER BY expira_en LIMIT 100");
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $db->beginTransaction();
        try {
            $lock = $db->prepare("SELECT id FROM musicmad_uploads WHERE id=? AND estado<>'completo' AND expira_en<UTC_TIMESTAMP() FOR UPDATE");
            $lock->execute([$id]);
            if ($lock->fetchColumn()) {
                $path = rutaSubidaPendiente($id);
                if (is_link($path)) {
                    throw new RuntimeException('Temporal inseguro.');
                }
                if (is_file($path) && !unlink($path)) {
                    throw new RuntimeException('No se pudo limpiar el temporal.');
                }
                $db->prepare('DELETE FROM musicmad_uploads WHERE id=?')->execute([$id]);
                $removed++;
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
    // Retain idempotency receipts, which are tiny, for 30 days after completion.
    $db->prepare("DELETE FROM musicmad_uploads WHERE estado='completo' AND expira_en<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)")->execute();
    $db->prepare('DELETE FROM musicmad_feed_snapshots WHERE expira_en<UTC_TIMESTAMP() LIMIT 500')->execute();
    if (function_exists('chatLiveDisponible') && chatLiveDisponible()) {
        $db->prepare('DELETE FROM musicmad_chat_limits WHERE bucket<UTC_TIMESTAMP()-INTERVAL 1 DAY LIMIT 5000')->execute();
        $db->prepare('DELETE FROM musicmad_chat_receipts WHERE created_at<UTC_TIMESTAMP()-INTERVAL 30 DAY LIMIT 5000')->execute();
        $db->prepare('DELETE FROM musicmad_chat_activity WHERE visto_en<UTC_TIMESTAMP()-INTERVAL 1 DAY LIMIT 5000')->execute();
    }
    echo 'Temporales vencidos limpiados: ' . $removed . "\n";
} catch (Throwable $error) {
    error_log('Maintenance: ' . get_class($error));
    fwrite(STDERR, "Mantenimiento incompleto. Revisa el registro privado del servidor.\n");
    exit(1);
}
