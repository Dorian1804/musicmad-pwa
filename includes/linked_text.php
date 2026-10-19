<?php

/** Parse raw text before escaping; never interpret user HTML or link an email/URL fragment. */
function tokensSocialesTexto(string $text): array
{
    preg_match_all('/(?<![\p{L}\p{N}_.\/?:&=+@#-])(?:#([\p{L}\p{N}_]{2,40})(?![\p{L}\p{N}_])|@([a-z][a-z0-9_]{2,29})(?![\p{L}\p{N}_]))/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    return array_slice($matches, 0, 32);
}
function mencionesVisiblesTexto(int $viewer, array $aliases): array
{
    $aliases = array_slice(array_values(array_unique($aliases)), 0, 8);
    if (!$aliases || !social3Disponible()) {
        return [];
    }
    [$policy,$params] = filtroAccesoContenido($viewer, 'u.id', 'text_mention');
    $holders = [];
    foreach ($aliases as $i => $alias) {
        $key = ':mention_alias_' . $i;
        $holders[] = $key;
        $params[$key] = $alias;
    }
    $st = getDB()->prepare('SELECT p.slug,u.id FROM perfiles_publicos p JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 WHERE p.slug IN (' . implode(',', $holders) . ') AND ' . $policy);
    $st->execute($params);
    $result = [];
    foreach ($st->fetchAll() as $row) {
        $result[$row['slug']] = (int)$row['id'];
    }
    return $result;
}
/** Safe links only to local tag/profile pages. Missing/inaccessible mentions remain plain text. */
function renderTextoSocialSeguro(string $text, array $mentions = []): string
{
    $out = '';
    $position = 0;
    $linkedTags = [];
    foreach (tokensSocialesTexto($text) as $token) {
        [$raw,$offset] = $token[0];
        $out .= e(substr($text, $position, $offset - $position));
        $url = null;
        if (($token[1][0] ?? '') !== '') {
            $tag = mb_strtolower($token[1][0]);
            if (isset($linkedTags[$tag]) || count($linkedTags) < 8) {
                $linkedTags[$tag] = true;
                $url = 'buscar.php?tag=' . rawurlencode($tag);
            }
        } elseif (isset($mentions[$token[2][0] ?? '']) && is_int($mentions[$token[2][0]]) && $mentions[$token[2][0]] > 0) {
            $url = 'musico.php?id=' . $mentions[$token[2][0]];
        }
        $out .= $url === null ? e($raw) : '<a class="social-text-link" href="' . e($url) . '">' . e($raw) . '</a>';
        $position = $offset + strlen($raw);
    }
    return nl2br($out . e(substr($text, $position)));
}
function textoSocialConEnlaces(string $text, int $viewer): string
{
    $aliases = [];
    foreach (tokensSocialesTexto($text) as $token) {
        if (($token[2][0] ?? '') !== '') {
            $aliases[] = $token[2][0];
        }
    }
    return renderTextoSocialSeguro($text, mencionesVisiblesTexto($viewer, $aliases));
}
