<?php

function textoCalendario(string $value): string
{
    return str_replace(["\\","\r\n","\r","\n",';',','], ["\\\\","\\n","\\n","\\n","\\;","\\,"], $value);
}
/** RFC 5545 lines are at most 75 octets; never split an UTF-8 sequence. */
function exportarCalendario(array $lines): string
{
    $result = [];
    foreach ($lines as $line) {
        $first = true;
        while (strlen($line) > ($first ? 75 : 74)) {
            $size = $first ? 75 : 74;
            while ($size > 0 && (ord($line[$size]) & 0xC0) === 0x80) {
                $size--;
            }
            $result[] = ($first ? '' : ' ') . substr($line, 0, $size);
            $line = substr($line, $size);
            $first = false;
        }
        $result[] = ($first ? '' : ' ') . $line;
    }
    return implode("\r\n", $result) . "\r\n";
}
