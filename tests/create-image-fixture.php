<?php

require_once __DIR__ . '/paths.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$directory = musicmadQaRoot();
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$image = imagecreatetruecolor(2400, 1600);
for ($x = 0;$x < 2400;$x += 200) {
    $color = imagecolorallocate($image, 40 + (int)($x / 20), 70, 170);
    imagefilledrectangle($image, $x, 0, $x + 199, 1599, $color);
}
imagepng($image, $directory . '/crop-fixture.png');
imagedestroy($image);
echo "Imagen ficticia de pruebas creada.\n";


$wav = '';
for ($i = 0;$i < 480000;$i++) {
    $wav .= pack('v', (int)(sin($i * 440 * 2 * pi() / 8000) * 7000) & 65535);
}
$header = 'RIFF' . pack('V', 36 + strlen($wav)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', strlen($wav));
file_put_contents($directory . '/qa-long.wav',$header . $wav);
echo "Audio ficticio de 60 segundos creado.\n";
