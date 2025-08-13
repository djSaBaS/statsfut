<?php
// Configuración PDO segura
$dsn = "mysql:host=localhost;dbname=tu_bd;charset=utf8mb4";
$pdo = new PDO($dsn, "usuario_bd", "password_bd", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
