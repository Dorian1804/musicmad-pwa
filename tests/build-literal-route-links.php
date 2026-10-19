<?php

if (PHP_SAPI !== 'cli') {
    exit(2);
}
$root = dirname(__DIR__);
$touched = [];
$files = glob($root . '/*.php');
foreach (['header','navbar','footer','publicacion','navigation'] as $name) {
    $files[] = $root . '/includes/' . $name . '.php';
}
foreach ($files as $file) {
    $text = file_get_contents($file);
    if (dirname($file) === $root && !str_contains($text, "'/includes/auth.php'")) {
        continue;
    }
    $result = '';
    foreach (token_get_all($text) as $token) {
        if (is_array($token) && $token[0] === T_INLINE_HTML) {
            $html = preg_replace_callback('~\bhref="([a-z][a-z-]*[.]php(?:[?#][^"<]*)?)"~', static function ($m) {
                $value = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return 'href="<?= e(rutaAplicacion(' . var_export($value, true) . ')) ?>"';
            }, $token[1]);
            $result .= $html;
        } else {
            $result .= is_array($token) ? $token[1] : $token;
        }
    }
    if ($result !== $text) {
        file_put_contents($file, str_replace("\r\n", "\n", $result));
        $touched[] = str_replace('\\', '/', substr($file, strlen($root) + 1));
    }
}
file_put_contents(__DIR__ . '/route-touched-files.json', json_encode($touched, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo count($touched) . " public templates updated.\n";
