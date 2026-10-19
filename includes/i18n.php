<?php

const MUSICMAD_LOCALES = [
    'es' => 'Español','en' => 'English','pt-BR' => 'Português (Brasil)','fr' => 'Français',
    'de' => 'Deutsch','it' => 'Italiano','ja' => '日本語','ko' => '한국어','zh-CN' => '简体中文',
];

function normalizarIdiomaMusicMad(mixed $value): ?string
{
    if (!is_string($value) || strlen($value) > 35 || !preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z]{2,4})?$/D', $value)) {
        return null;
    }
    foreach (array_keys(MUSICMAD_LOCALES) as $locale) {
        if (strcasecmp($value, $locale) === 0) {
            return $locale;
        }
    }
    return match(strtolower(explode('-', $value)[0])) {
        'es' => 'es','en' => 'en','pt' => 'pt-BR','fr' => 'fr','de' => 'de','it' => 'it','ja' => 'ja','ko' => 'ko','zh' => 'zh-CN',default => null,
    };
}

function negociarIdiomaMusicMad(string $header): string
{
    if (strlen($header) > 2048) {
        return 'es';
    }
    $choices = [];
    foreach (array_slice(explode(',', $header), 0, 25) as $index => $part) {
        if (!preg_match('/^\s*([a-zA-Z-]+)(?:\s*;\s*q=(0(?:\.[0-9]{1,3})?|1(?:\.0{1,3})?))?\s*$/D', $part, $matches)) {
            continue;
        }
        $locale = normalizarIdiomaMusicMad($matches[1]);
        $quality = isset($matches[2]) ? (float)$matches[2] : 1.0;
        if ($locale !== null && $quality > 0) {
            $choices[] = ['locale' => $locale,'q' => $quality,'index' => $index];
        }
    }
    usort($choices, static fn ($a, $b) => ($b['q'] <=> $a['q']) ?: ($a['index'] <=> $b['index']));
    return $choices[0]['locale'] ?? 'es';
}

function idiomaCuentaDisponible(): bool
{
    static $ready;
    if ($ready !== null) {
        return $ready;
    }
    $statement = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $statement->execute(['musicmad_user_locale']);
    return $ready = (int)$statement->fetchColumn() === 1;
}

function idiomaCuentaMusicMad(int $uid, string $fallback = 'es'): string
{
    if ($uid < 1 || !idiomaCuentaDisponible()) {
        return normalizarIdiomaMusicMad($fallback) ?? 'es';
    }
    $statement = getDB()->prepare('SELECT locale FROM musicmad_user_locale WHERE usuario_id=?');
    $statement->execute([$uid]);
    return normalizarIdiomaMusicMad($statement->fetchColumn()) ?? (normalizarIdiomaMusicMad($fallback) ?? 'es');
}

function guardarIdiomaCuenta(int $uid, string $locale): void
{
    if ($uid < 1 || !array_key_exists($locale, MUSICMAD_LOCALES)) {
        throw new InvalidArgumentException('Idioma no válido.');
    }
    if (!idiomaCuentaDisponible()) {
        throw new RuntimeException('Actualiza las preferencias de idioma.');
    }
    getDB()->prepare('INSERT INTO musicmad_user_locale(usuario_id,locale) VALUES(?,?) ON DUPLICATE KEY UPDATE locale=VALUES(locale)')->execute([$uid,$locale]);
}

function idiomaMusicMad(): string
{
    if (isset($GLOBALS['musicmad_locale_override'])) {
        return normalizarIdiomaMusicMad($GLOBALS['musicmad_locale_override']) ?? 'es';
    }
    $guest = normalizarIdiomaMusicMad($_SESSION['musicmad_locale'] ?? $_COOKIE['MUSICMAD_LANG'] ?? null)
        ?? negociarIdiomaMusicMad((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $uid = (int)($_SESSION['usuario_id'] ?? 0);
    static $resolved = [];
    $key = $uid . ':' . $guest;
    return $resolved[$key] ??= ($uid > 0 ? idiomaCuentaMusicMad($uid, $guest) : $guest);
}

function catalogoIdiomaMusicMad(string $locale): array
{
    // Literal paths only: input can never become an include path.
    static $catalogues = [];
    $paths = [
        'es' => __DIR__ . '/locales/es.php','en' => __DIR__ . '/locales/en.php',
        'pt-BR' => __DIR__ . '/locales/pt-BR.php','fr' => __DIR__ . '/locales/fr.php',
        'de' => __DIR__ . '/locales/de.php','it' => __DIR__ . '/locales/it.php',
        'ja' => __DIR__ . '/locales/ja.php','ko' => __DIR__ . '/locales/ko.php','zh-CN' => __DIR__ . '/locales/zh-CN.php',
    ];
    $locale = array_key_exists($locale, $paths) ? $locale : 'es';
    return $catalogues[$locale] ??= require $paths[$locale];
}

function mmTexto(string $key, array $parameters = [], ?string $locale = null): string
{
    $locale = normalizarIdiomaMusicMad($locale ?? idiomaMusicMad()) ?? 'es';
    $base = catalogoIdiomaMusicMad('es');
    $catalogue = catalogoIdiomaMusicMad($locale);
    $text = $catalogue[$key] ?? $base[$key] ?? $key;
    $replace = [];
    foreach ($parameters as $name => $value) {
        if (is_scalar($value)) {
            $replace['{' . $name . '}'] = (string)$value;
        }
    }
    return strtr($text, $replace);
}

function selectorIdiomaMusicMad(string $context): void
{
    if (!in_array($context, ['login','registro','configuracion','bienvenida'], true)) {
        return;
    }
    $current = idiomaMusicMad();
    ?><form method="POST" action="<?= e(rutaAplicacion('idioma.php', 'POST')) ?>" class="language-selector" data-native-navigation>
      <?= csrfCampo() ?><input type="hidden" name="contexto" value="<?= e($context) ?>">
      <label><?= e(mmTexto('Idioma')) ?><select name="idioma" autocomplete="language">
      <?php foreach (MUSICMAD_LOCALES as $locale => $name): ?><option value="<?= e($locale) ?>" lang="<?= e($locale) ?>" <?= $locale === $current ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
      </select></label><button type="submit" class="btn btn-ghost"><?= e(mmTexto('Guardar idioma')) ?></button>
    </form><?php
}

function mensajeErrorApiIdioma(string $error, ?string $locale = null): string
{
    $key = match($error) {
        'unauthorized' => 'Acceso requerido','invalid_credentials' => 'Credenciales no válidas',
        'invalid_json','validation_error' => 'Datos no válidos','not_found' => 'Recurso no disponible',
        'rate_limited' => 'Demasiadas solicitudes','email_verification_required' => 'Confirma tu correo',
        'onboarding_required' => 'Completa tu recorrido','second_factor_required' => 'Código de autenticación requerido',
        'payload_too_large' => 'Archivo demasiado grande',default => 'Error general',
    };
    return mmTexto($key, [], $locale);
}

/** Page titles may contain artist names: translate only fixed application screens. */
function tituloAplicacionIdioma(string $title, string $script, ?string $locale = null): string
{
    return in_array(basename($script), ['login.php','registro.php','configuracion.php','menu.php','feed.php','perfil.php','bienvenida.php'], true)
        ? mmTexto($title, [], $locale) : $title;
}
