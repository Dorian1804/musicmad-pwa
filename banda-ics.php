<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/calendar_export.php';
header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
requerirSesion();
$yo = (int)sesion('usuario_id');
$band = idCampo($_GET, 'id');
try {
    if (!bandasWorkspaceDisponible()) {
        throw new OutOfBoundsException();
    }
    $events = ensayosBanda($yo, $band);
    $name = bandaDisponible($yo, $band)['nombre'];
} catch (OutOfBoundsException $error) {
    http_response_code(404);
    exit('Calendario no disponible.');
}
session_write_close();
$lines = ['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//MusicMad//Ensayos//ES','CALSCALE:GREGORIAN','X-WR-CALNAME:' . textoCalendario($name)];
foreach ($events as $event) {
    $start = new DateTimeImmutable($event['inicio'], new DateTimeZone('UTC'));
    $end = new DateTimeImmutable($event['fin'], new DateTimeZone('UTC'));
    array_push($lines, 'BEGIN:VEVENT', 'UID:ensayo-' . $event['id'] . '-banda-' . $band . '@musicmad', 'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'DTSTART:' . $start->format('Ymd\THis\Z'), 'DTEND:' . $end->format('Ymd\THis\Z'), 'SEQUENCE:' . (int)$event['revision'], 'STATUS:' . ($event['estado'] === 'cancelado' ? 'CANCELLED' : 'CONFIRMED'), 'SUMMARY:' . textoCalendario($event['titulo']), 'LOCATION:' . textoCalendario($event['lugar']), 'DESCRIPTION:' . textoCalendario($event['notas']), 'END:VEVENT');
}
$lines[] = 'END:VCALENDAR';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="musicmad-ensayos-' . $band . '.ics"');
echo exportarCalendario($lines);
