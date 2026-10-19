<?php

// ============================================================
//  MusicMad – Clase Database (compatibilidad)
//  dataBase.php
//
//  Antes tenía sus propias credenciales; ahora reutiliza la
//  conexión de config/database.php para tener un solo lugar
//  de configuración.
// ============================================================
require_once __DIR__ . '/config/database.php';

class Database
{
    public ?PDO $conn = null;

    public function getConnection(): PDO
    {
        $this->conn = getDB();
        return $this->conn;
    }
}
