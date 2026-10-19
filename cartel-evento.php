<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$event = eventosWorkspaceDisponible() ? eventoDisponiblePara((int)sesion('usuario_id'), idCampo($_GET, 'id'), true) : null;
if (!$event) {
    http_response_code(404);
    exit;
}
session_write_close();
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Content-Disposition: inline; filename="musicmad-cartel-' . (int)$event['id'] . '.svg"');
$palettes = ['violeta' => ['#21132c','#d7f542','#af91d0'],'azul' => ['#142c42','#a1e5ed','#4c849e'],'coral' => ['#4a202a','#ffdec3','#be6862']];
[$background,$accent,$secondary] = $palettes[textoCampo($_GET, 'color')] ?? $palettes['violeta'];
$date = (new DateTimeImmutable($event['inicio_utc'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($event['zona']));
$words = preg_split('/\s+/u', $event['titulo']);
$lines = [];
$line = '';
foreach ($words as $word) {
    if (mb_strlen($line . ' ' . $word) > 19 && $line !== '') {
        $lines[] = $line;
        $line = '';
    }
    $line = trim($line . ' ' . $word);
}
if ($line !== '') {
    $lines[] = $line;
}
$lines = array_slice($lines, 0, 6);
?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 1200" role="img" aria-label="<?= e($event['titulo']) ?>"><rect width="900" height="1200" fill="<?= $background ?>"/><path d="M800 -80 460 1300M940 -80 600 1300" stroke="<?= $secondary ?>" stroke-width="95" opacity=".2"/><circle cx="790" cy="120" r="230" fill="none" stroke="<?= $accent ?>" stroke-width="3" opacity=".45"/><text x="70" y="100" fill="<?= $accent ?>" font-family="sans-serif" font-size="24" font-weight="700" letter-spacing="4">MUSICMAD / EN VIVO</text><text x="70" y="225" fill="<?= $accent ?>" font-family="sans-serif" font-size="72" font-weight="800"><?= e($date->format('d.m.Y')) ?></text><text x="70" y="280" fill="#fff" font-family="sans-serif" font-size="27"><?= e($date->format('H:i') . ' · ' . $event['zona']) ?></text><text fill="#fff" font-family="sans-serif" font-size="66" font-weight="800"><?php foreach ($lines as $i => $title): ?><tspan x="70" y="<?= 400 + $i * 82 ?>"><?= e(mb_strimwidth($title, 0, 27, '…')) ?></tspan><?php endforeach; ?></text><rect x="70" y="940" width="760" height="3" fill="<?= $accent ?>"/><text x="70" y="1010" fill="<?= $accent ?>" font-family="sans-serif" font-size="31" font-weight="700"><?= e(mb_strimwidth($event['ciudad'], 0, 37, '…')) ?></text><text x="70" y="1060" fill="#fff" font-family="sans-serif" font-size="25"><?= e(mb_strimwidth($event['sede'] ?: 'Sede por confirmar', 0, 50, '…')) ?></text><text x="70" y="1140" fill="#fff" font-family="sans-serif" font-size="19"><?= $event['estado'] === 'cancelado' ? 'EVENTO CANCELADO' : 'Encuentra este evento en MusicMad' ?></text></svg>
