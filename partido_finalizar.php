<?php
require_once __DIR__ . '/includes/db.php';
require_login(); // Asegurar que el usuario esté autenticado

// ==============================
// Obtener ID del partido y token CSRF
// ==============================
$id = (int)($_GET['id'] ?? 0);       // ID del partido, casteado a entero
$csrf = $_GET['csrf'] ?? '';         // Token CSRF para evitar CSRF

// ==============================
// Validaciones básicas
// ==============================
if ($id <= 0 || !csrf_validate($csrf)) {
    http_response_code(400);         // Código 400 Bad Request
    die('Solicitud inválida');       // Terminar ejecución en caso de fallo
}

// ==============================
// Marcar partido como finalizado
// Solo si pertenece al usuario autenticado
// ==============================
$stmt = $pdo->prepare('UPDATE matches SET status="finished" WHERE id=? AND user_id=?');
$stmt->execute([$id, $_SESSION['user_id']]);

// ==============================
// Redirigir a la página de estadísticas del partido
// ==============================
header('Location: partido_estadisticas.php?id=' . $id);
exit;
?>
