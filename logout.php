<?php
// Incluir la configuración y conexión a la base de datos
require_once __DIR__ . '/includes/db.php';

// Limpiar la sesión actual: eliminar todas las variables de sesión
$_SESSION = [];

// Comprobar si la sesión utiliza cookies
if (ini_get('session.use_cookies')) {
    // Obtener los parámetros de la cookie de sesión actual
    $params = session_get_cookie_params();
    
    // Invalidar la cookie de sesión en el navegador
    // Se establece una fecha de expiración pasada para eliminarla
    setcookie(
        session_name(),    // Nombre de la cookie de sesión
        '',                // Valor vacío para eliminar
        time() - 42000,    // Expiración en el pasado
        $params['path'],   // Ruta de la cookie
        $params['domain'], // Dominio de la cookie
        $params['secure'], // Solo HTTPS si aplica
        $params['httponly'] // Evita acceso desde JavaScript
    );
}

// Destruir la sesión en el servidor
session_destroy();

// Redirigir al usuario a la página de inicio (index.php)
// y detener la ejecución del script para evitar cualquier salida adicional
header('Location: index.php');
exit;
