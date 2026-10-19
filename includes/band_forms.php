<?php
function abrirAccionBanda(int $id, string $action, string $class = 'band-form', string $confirm = ''): void
{ ?><form method="POST" action="banda-accion.php" class="<?= e($class) ?>" <?= $confirm !== '' ? 'data-confirm="' . e($confirm) . '"' : '' ?>><?= csrfCampo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="<?= e($action) ?>"><?php }
function camposBanda(array $band = [], array $settings = []): void
{ ?>
 <label>Nombre<input name="nombre" maxlength="60" minlength="2" required value="<?= e($band['nombre'] ?? '') ?>" placeholder="Cómo se llama tu banda"></label>
 <div class="band-field-row"><label>Género<select name="genero"><option value="">Sin definir</option><?php foreach (GENEROS as $g): ?><option <?= ($band['genero'] ?? '') === $g ? 'selected' : '' ?> value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?></select></label><label>Ciudad<input name="ciudad" maxlength="80" value="<?= e($band['ciudad'] ?? '') ?>"></label></div>
 <label>Sobre la banda<textarea name="descripcion" rows="3" maxlength="500" placeholder="Su sonido, proyectos y qué están buscando"><?= e($band['descripcion'] ?? '') ?></textarea></label>
 <div class="band-field-row"><label>Ingreso<select name="ingreso"><option value="abierto" <?= ($settings['ingreso'] ?? '') === 'abierto' ? 'selected' : '' ?>>Cualquier músico puede unirse</option><option value="invitacion" <?= ($settings['ingreso'] ?? '') === 'invitacion' ? 'selected' : '' ?>>Solo con invitación</option></select></label><label>Color<select name="color"><?php foreach (['violeta' => 'Violeta','azul' => 'Azul','coral' => 'Coral','verde' => 'Verde'] as $v => $label): ?><option value="<?= $v ?>" <?= ($settings['color'] ?? 'violeta') === $v ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label></div>
 <p class="band-hint">El perfil y las publicaciones visibles son públicos. El chat y los ensayos son exclusivos para integrantes.</p>
<?php }
function formularioEnsayo(int $band, ?array $event = null): void
{
    $zone = $event['zona'] ?? 'America/Mexico_City';
    $start = $event ? new DateTimeImmutable($event['inicio'], new DateTimeZone('UTC')) : null;
    $duration = $event ? (int)((strtotime($event['fin'] . ' UTC') - strtotime($event['inicio'] . ' UTC')) / 60) : 120;
    abrirAccionBanda($band, 'ensayo'); ?>
 <input type="hidden" name="ensayo" value="<?= (int)($event['id'] ?? 0) ?>"><label>Título<input name="titulo" maxlength="100" minlength="2" required value="<?= e($event['titulo'] ?? '') ?>" placeholder="Ensayo de repertorio"></label>
 <div class="band-field-row"><label>Fecha y hora<input type="datetime-local" name="inicio" required value="<?= $start ? e($start->setTimezone(new DateTimeZone($zone))->format('Y-m-d\TH:i')) : '' ?>"></label><label>Duración (minutos)<input type="number" name="duracion" min="30" max="360" step="15" value="<?= $duration ?>" required></label></div>
 <label>Zona horaria<select name="zona"><?php foreach (['America/Mexico_City' => 'Ciudad de México','America/Tijuana' => 'Tijuana','America/Hermosillo' => 'Hermosillo','America/Cancun' => 'Cancún','America/Monterrey' => 'Monterrey','America/New_York' => 'Nueva York','America/Los_Angeles' => 'Los Ángeles','Europe/Madrid' => 'Madrid','America/Bogota' => 'Bogotá','America/Argentina/Buenos_Aires' => 'Buenos Aires','UTC' => 'UTC'] as $value => $label): ?><option value="<?= $value ?>" <?= $value === $zone ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
 <label>Lugar<input name="lugar" maxlength="160" value="<?= e($event['lugar'] ?? '') ?>" placeholder="Solo lo ven integrantes"></label><label>Notas<textarea name="notas" rows="3" maxlength="500"><?= e($event['notas'] ?? '') ?></textarea></label><button class="btn btn-primary" type="submit">Guardar ensayo</button></form>
<?php }
