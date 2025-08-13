<?php
/**
 * Clave secreta para cifrado de datos en reposo.
 * Uso: AES-256-GCM para proteger información sensible en la base de datos.
 * 
 * Requisitos:
 * - Debe tener exactamente 32 bytes para AES-256.
 * - Se puede generar con: `php -r "echo bin2hex(random_bytes(32));"`
 * - El valor se guarda en HEX (64 caracteres) y luego se convierte a binario.
 */

// EJEMPLO: sustituir por tu propia cadena HEX de 64 caracteres (32 bytes reales)
$__STATSFUT_ENC_KEY_HEX = 'cadenaHEXde64';

// Convertimos el HEX a binario para usarlo con OpenSSL
if (!function_exists('statsfut_get_enc_key')) {
    /**
     * Obtener la clave de cifrado en binario para operaciones criptográficas
     * 
     * @return string Clave de 32 bytes para AES-256
     * @throws error crítico si la clave no es válida
     */
    function statsfut_get_enc_key(): string {
        global $__STATSFUT_ENC_KEY_HEX;

        // Convertir HEX a binario real
        $key = @pack('H*', $__STATSFUT_ENC_KEY_HEX);

        // Validación de seguridad: asegurarse de que la clave tenga 32 bytes
        if ($key === false || strlen($key) !== 32) {
            // Error crítico: detener ejecución para evitar vulnerabilidades
            http_response_code(500);
            die('Error crítico de configuración: clave de cifrado inválida.');
        }

        return $key;
    }
}
