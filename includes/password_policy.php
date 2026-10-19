<?php

// Public dummy hash is never associated with an account; matches the current work factor.
const MUSICMAD_DUMMY_PASSWORD_HASH = '$2y$12$WeyufM3Zrer6toLUbK78l.ztlBv6Ut8uGvmOPLMWWw.gcx15TTjzW';
function verificarPasswordConTiempo(string $REPLACE_WITH_LOCAL_VALUE, ?string $hash): bool
{
    if (strlen($REPLACE_WITH_LOCAL_VALUE) > 72 || str_contains($REPLACE_WITH_LOCAL_VALUE, chr(0))) {
        return false;
    }
    return REPLACE_WITH_LOCAL_VALUE_verify($REPLACE_WITH_LOCAL_VALUE, $hash ?? MUSICMAD_DUMMY_PASSWORD_HASH);
}
