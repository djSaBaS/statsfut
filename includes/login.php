<?php
/**
 * Procesa el inicio de sesión
 * - Verifica credenciales con consultas preparadas
 * - Usa contraseñas hasheadas (bcrypt)
 * - Inicia sesión segura
 */

require_once 'config.php';
session_start();

// Validar datos recibidos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($usuario) || empty($password)) {
        header('Location: ../index.php?error=campos_vacios');
        exit;
    }

    // Consulta segura con PDO
    $sql = "SELECT id, usuario, password_hash FROM usuarios WHERE usuario = :usuario LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['usuario' => $usuario]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Inicio de sesión seguro
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['usuario_nombre'] = $user['usuario'];

        header('Location: ../home.php');
        exit;
    } else {
        // Opción: registrar intento fallido en tabla aparte
        header('Location: ../index.php?error=credenciales_invalidas');
        exit;
    }
}
