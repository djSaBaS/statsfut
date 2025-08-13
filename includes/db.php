<?php
/**
 * includes/db.php
 * --------------------------------------------
 * - Conexión a MySQL vía PDO (modo seguro)
 * - Endurecimiento de sesión
 * - Cifrado simétrico de datos en reposo (AES-256-GCM)
 * - Funciones CSRF
 * - Utilidades comunes (escape de salida, login requerido)
 * --------------------------------------------
 */

// ⚠️ Asegúrate de tener activado HTTPS en producción para marcar las cookies como "secure".

// --- Configuración de BD ---
$DB_HOST = 'sqlXXX.infinityfree.com'; // ← sustituir por host real de InfinityFree
$DB_NAME = 'if0_XXXXXXXX_statsfut';   // ← sustituir por tu nombre de BD
$DB_USER = 'if0_XXXXXXXX';            // ← sustituir por usuario
$DB_PASS = '***************';         // ← sustituir por contraseña
$DB_DSN  = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";

// --- Sesión endurecida ---
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $https ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
session_name('statsfut_sid');
session_start();

// Rotación periódica de ID de sesión para mitigar fijación de sesión
if (!isset($_SESSION['__rotated'])) {
    session_regenerate_id(true);
    $_SESSION['__rotated'] = time();
}

// --- Conexión PDO ---
try {
    $pdo = new PDO($DB_DSN, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die('No se pudo conectar a la base de datos.');
}

// --- Carga de clave de cifrado ---
require_once __DIR__ . '/secret_key.php';
$ENC_KEY = statsfut_get_enc_key();

// --- Helpers de cifrado AES-256-GCM ---
if (!function_exists('sf_encrypt')) {
    /**
     * Cifra una cadena usando AES-256-GCM.
     * Devuelve: iv_base64:tag_base64:cipher_base64
     */
    function sf_encrypt(string $plaintext, string $key): string {
        $iv = random_bytes(12); // Recomendado para GCM
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Fallo al cifrar.');
        }
        return base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
    }
}

if (!function_exists('sf_decrypt')) {
    /**
     * Descifra el formato iv:tag:cipher en base64.
     */
    function sf_decrypt(?string $packed, string $key): ?string {
        if ($packed === null || $packed === '') return null;
        $parts = explode(':', $packed);
        if (count($parts) !== 3) return null;
        [$iv_b64, $tag_b64, $cipher_b64] = $parts;
        $iv     = base64_decode($iv_b64, true);
        $tag    = base64_decode($tag_b64, true);
        $cipher = base64_decode($cipher_b64, true);
        if ($iv === false || $tag === false || $cipher === false) return null;
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }
}

// --- CSRF ---
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_validate')) {
    function csrf_validate(?string $token): bool {
        return isset($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
    }
}

// --- Escapado seguro ---
if (!function_exists('e')) {
    function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// --- Autenticación ---
if (!function_exists('require_login')) {
    function require_login(): void {
        if (empty($_SESSION['user_id'])) {
            header('Location: index.php');
            exit;
        }
    }
}

if (!function_exists('email_hash')) {
    /**
     * Normaliza (lowercase+trim) y hashea el email para búsquedas sin exponer el valor real cifrado.
     */
    function email_hash(string $email): string {
        $norm = mb_strtolower(trim($email));
        return hash('sha256', $norm);
    }
}
