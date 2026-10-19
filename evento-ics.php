<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/calendar_export.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$id = idCampo($_GET, 'id');
$yo = (int)sesion('usuario_id');
if (eventosWorkspaceDisponible()) {
    $event = eventoDisponiblePara($yo, $id, true);
} else {
    $st = getDB()->prepare('SELECT * FROM eventos_musicales WHERE id=?');
    $st->execute([$id]);
    $event = $st->fetch();
    if ($event && puedeVerPerfilSocial3($yo, (int)$event['creador_id'])) {
        $date = new DateTimeImmutable($event['inicio'], new DateTimeZone('America/Mexico_City'));
        $event['inicio_utc'] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $event['fin_utc'] = $date->modify('+2 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $event['revision'] = 1;
    } else {
        $event = null;
    }
}
if (!$event) {
    http_response_code(404);
    exit('Evento no disponible.');
}
session_write_close();
$start = new DateTimeImmutable($event['inicio_utc'], new DateTimeZone('UTC'));
$end = new DateTimeImmutable($event['fin_utc'], new DateTimeZone('UTC'));
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="musicmad-evento-' . $id . '.ics"');
header('Cache-Control: private, no-store');
echo exportarCalendario(['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//MusicMad//Eventos//ES','CALSCALE:GREGORIAN','BEGIN:VEVENT','UID:evento-' . $id . '@musicmad','DTSTAMP:' . gmdate('Ymd\\THis\\Z'),'DTSTART:' . $start->format('Ymd\\THis\\Z'),'DTEND:' . $end->format('Ymd\\THis\\Z'),'SEQUENCE:' . (int)$event['revision'],'STATUS:' . ($event['estado'] === 'cancelado' ? 'CANCELLED' : 'CONFIRMED'),'SUMMARY:' . textoCalendario($event['titulo']),'DESCRIPTION:' . textoCalendario($event['descripcion']),'LOCATION:' . textoCalendario(trim($event['sede'] . ', ' . $event['ciudad'], ', ')),'END:VEVENT','END:VCALENDAR']);
