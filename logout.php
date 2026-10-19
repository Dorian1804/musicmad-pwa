<?php

// ============================================================
//  MusicMad – Cerrar sesión
//  logout.php
// ============================================================
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método no permitido.');
}

exigirCsrf();
cerrarSesion();

header('Location: login.php?logout=1');
exit;
