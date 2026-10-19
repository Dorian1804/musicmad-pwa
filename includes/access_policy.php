<?php

/**
 * Política única para contenido: dueño, perfil público o seguimiento aprobado.
 * Expresiones y prefijos son identificadores internos, nunca texto del usuario.
 */
function filtroAccesoContenido(int $viewer, string $owner = 'p.usuario_id', string $prefix = 'access', bool $muted = true): array
{
    if (!preg_match('/^[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*$/i', $owner)
        || !preg_match('/^[a-z][a-z0-9_]*$/i', $prefix)) {
        throw new InvalidArgumentException('Identificador de acceso inválido.');
    }
    $params = [];
    $param = static function (string $name) use (&$params, $prefix, $viewer): string {
        $key = ':' . $prefix . '_' . $name;
        $params[$key] = $viewer;
        return $key;
    };
    $sql = "NOT EXISTS (SELECT 1 FROM usuarios_bloqueados ab WHERE ab.modo='bloquear' AND "
         . "((ab.usuario_id=" . $param('blocker') . " AND ab.objetivo_id=$owner) OR "
         . "(ab.usuario_id=$owner AND ab.objetivo_id=" . $param('blocked') . ")))";
    if ($muted) {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM usuarios_bloqueados am WHERE am.modo='silenciar' "
              . "AND am.usuario_id=" . $param('muted') . " AND am.objetivo_id=$owner)";
    }
    $sql .= " AND ($owner=" . $param('self')
          . " OR NOT EXISTS (SELECT 1 FROM perfiles_privacidad ap WHERE ap.usuario_id=$owner "
          . "AND ap.visibilidad IN ('privado','seguidores'))"
          . " OR EXISTS (SELECT 1 FROM seguidores af WHERE af.seguidor_id=" . $param('follower')
          . " AND af.seguido_id=$owner))";
    return [$sql, $params];
}


/** Basic identity/DM access: blocking is bilateral; private posts have separate rules. */
function filtroBloqueoInteraccion(int $viewer, string $owner, string $prefix): array
{
    if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/i', $owner) || !preg_match('/^[a-z][a-z0-9_]*$/i', $prefix)) {
        throw new InvalidArgumentException('Identificador no válido.');
    }
    if (!social3Disponible()) {
        return ['1=1',[]];
    }
    return ["NOT EXISTS (SELECT 1 FROM usuarios_bloqueados ib WHERE ib.modo='bloquear' AND ((ib.usuario_id=:{$prefix}_a AND ib.objetivo_id=$owner) OR (ib.usuario_id=$owner AND ib.objetivo_id=:{$prefix}_b)))",[":{$prefix}_a" => $viewer,":{$prefix}_b" => $viewer]];
}
