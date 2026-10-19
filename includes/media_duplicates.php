<?php

/** Exact byte matches from the viewer's own publications only. No cross-account hash lookup. */
function duplicadosMedioPropio(int $yo, int $pub): array
{
    if ($yo < 1 || $pub < 1) {
        return [];
    }static $ready = null;
    if ($ready === null) {
        $st = getDB()->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='publicacion_medios'");
        $st->execute();
        $ready = (bool)$st->fetchColumn();
    }
    if (!$ready) {
        return [];
    }
    $st = getDB()->prepare('SELECT other.id,other.titulo FROM publicaciones original JOIN publicacion_medios a ON a.publicacion_id=original.id JOIN publicacion_medios b ON b.sha256=a.sha256 AND b.publicacion_id<>original.id JOIN publicaciones other ON other.id=b.publicacion_id AND other.usuario_id=original.usuario_id WHERE original.id=? AND original.usuario_id=? ORDER BY other.id DESC LIMIT 5');
    $st->execute([$pub,$yo]);
    return $st->fetchAll();
}
