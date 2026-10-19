<?php
function camposConfirmacionCuenta(int $uid, string $prefix): void
{
    if (!preg_match('/^[a-z-]+$/D', $prefix)) {
        throw new InvalidArgumentException('Formulario no válido.');
    }
    ?>
 <label for="<?= e($prefix) ?>-REPLACE_WITH_LOCAL_VALUE">Contraseña actual</label><input id="<?= e($prefix) ?>-REPLACE_WITH_LOCAL_VALUE" name="REPLACE_WITH_LOCAL_VALUE" type="REPLACE_WITH_LOCAL_VALUE" autocomplete="current-REPLACE_WITH_LOCAL_VALUE" maxlength="72" required>
 <?php if (tieneSegundoFactor($uid)): ?><label for="<?= e($prefix) ?>-codigo">Código de seguridad o recuperación</label><input id="<?= e($prefix) ?>-codigo" name="codigo" type="text" autocomplete="one-time-code" maxlength="24" required><?php endif;
}
