<?php
// Incluir configuración y conexión a la base de datos
require_once __DIR__ . '/includes/db.php';

// Si el usuario ya está logado, redirigir directamente al home
if (!empty($_SESSION['user_id'])) {
    header('Location: home.php');
    exit;
}

// Variable para mensajes de alerta al usuario (errores o información)
$alert = null;

// Procesar el formulario solo si es una solicitud POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? ''; // Puede ser 'login' o 'register'
    $csrf   = $_POST['csrf'] ?? '';   // Token CSRF para seguridad

    // Validación CSRF para prevenir ataques de falsificación de solicitudes
    if (!csrf_validate($csrf)) {
        $alert = ['type' => 'error', 'msg' => 'Token CSRF inválido. Recarga la página.'];
    } else {
        // Normalizar y sanitizar entradas del usuario
        $email = trim((string)($_POST['email'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');

        // Validaciones básicas de formato
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $alert = ['type' => 'error', 'msg' => 'Introduce un email válido.'];
        } elseif (strlen($pass) < 8) {
            $alert = ['type' => 'error', 'msg' => 'La contraseña debe tener al menos 8 caracteres.'];
        } else {
            // Hash del email para búsqueda segura en la base de datos
            $email_h = email_hash($email);

            try {
                if ($action === 'register') {
                    // Registro de usuario
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE email_hash = ? LIMIT 1');
                    $stmt->execute([$email_h]);

                    if ($stmt->fetch()) {
                        // Si el email ya existe, mostrar error
                        $alert = ['type' => 'error', 'msg' => 'Ya existe una cuenta con ese email.'];
                    } else {
                        // Encriptar email y generar hash de la contraseña
                        $email_enc = sf_encrypt($email, $ENC_KEY);
                        $pwd_hash  = password_hash($pass, PASSWORD_DEFAULT);

                        // Insertar nuevo usuario en la base de datos
                        $stmt = $pdo->prepare('INSERT INTO users (email_hash, email_enc, password_hash, created_at) VALUES (?,?,?,NOW())');
                        $stmt->execute([$email_h, $email_enc, $pwd_hash]);

                        // Autologin tras registro y regeneración de sesión para seguridad
                        $_SESSION['user_id'] = (int)$pdo->lastInsertId();
                        session_regenerate_id(true);

                        header('Location: home.php');
                        exit;
                    }

                } elseif ($action === 'login') {
                    // Inicio de sesión
                    $stmt = $pdo->prepare('SELECT id, email_enc, password_hash FROM users WHERE email_hash = ? LIMIT 1');
                    $stmt->execute([$email_h]);
                    $user = $stmt->fetch();

                    // Mitigación de enumeración de usuarios usando un hash falso
                    $fake_hash = '$2y$10$abcdefghijklmnopqrstuv12345678901234567890123456789012';
                    $hash_to_verify = $user['password_hash'] ?? $fake_hash;

                    if (password_verify($pass, $hash_to_verify) && $user) {
                        // Credenciales correctas: iniciar sesión y regenerar ID de sesión
                        $_SESSION['user_id'] = (int)$user['id'];
                        session_regenerate_id(true);

                        header('Location: home.php');
                        exit;
                    } else {
                        // Retardo pequeño para dificultar ataques de fuerza bruta
                        usleep(300000); // 300ms
                        $alert = ['type' => 'error', 'msg' => 'Credenciales no válidas.'];
                    }
                }
            } catch (Throwable $t) {
                // Captura y log de errores internos, sin exponer detalles al usuario
                error_log('[STATSFUT][AUTH] ' . $t->getMessage());
                $alert = ['type' => 'error', 'msg' => 'Ha ocurrido un error inesperado.'];
            }
        }
    }
}
?>

<?php include __DIR__ . '/includes/header.php'; ?>
<!-- Plantilla de cabecera con estilos y navegación común -->

<body class="login-page d-flex align-items-center min-vh-100">
  <div class="login-card text-center mx-auto">
    <div class="logo-circle"></div>
    <h1 class="mt-5">Bienvenido a STATSFUT</h1>
    <p class="text-muted">Accede o crea tu cuenta para empezar a registrar partidos y estadísticas.</p>

    <?php if ($alert): ?>
      <div class="alert alert-<?php echo $alert['type']==='error'?'danger':'success'; ?> mt-3"><?php echo e($alert['msg']); ?></div>
    <?php endif; ?>

    <form id="auth-form" method="post" class="mt-3" novalidate>
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" id="action" value="login">

      <div class="mb-3 text-start">
        <label class="form-label">Email</label>
        <input type="email" class="form-control" name="email" required autocomplete="email">
      </div>
      <div class="mb-2 text-start">
        <label class="form-label">Contraseña</label>
        <input type="password" class="form-control" name="password" required minlength="8" autocomplete="current-password">
      </div>

      <div class="d-grid gap-2 mt-3">
        <button class="btn btn-success" type="submit" id="login-btn">Entrar</button>
        <button class="btn btn-link" id="toggle-mode" type="button">Crear cuenta</button>
      </div>
    </form>
  </div>
  <script>
    /**
     * Script de interacción del formulario de login/registro
     * - Permite alternar entre modo login y registro
     * - Añade animación de balón al enviar el formulario
     */
    (function() {
      const form = document.getElementById('auth-form');
      if (!form) return; // Salir si no existe el formulario
  
      const action = document.getElementById('action');       // Input oculto que indica la acción: login o register
      const toggle = document.getElementById('toggle-mode'); // Botón para alternar entre login y registro
      const submitBtn = document.getElementById('login-btn'); // Botón principal de envío
   
      // ==============================
      // Alternar entre login y registro
      // ==============================
      toggle?.addEventListener('click', () => {
        const isLogin = action.value === 'login';
        action.value = isLogin ? 'register' : 'login'; // Cambia la acción
        toggle.textContent = isLogin ? 'Ya tengo cuenta' : 'Crear cuenta'; // Cambia texto del toggle
        submitBtn.textContent = isLogin ? 'Crear cuenta' : 'Entrar';       // Cambia texto del botón de envío
      });
   
      // ==============================
      // Animación al enviar formulario
      // ==============================
      form.addEventListener('submit', (e) => {
        if (!form.checkValidity()) return; // Dejar que el navegador valide primero
    
        e.preventDefault(); // Evitar envío inmediato para mostrar animación
    
        // Activar animación de balón en el botón
        submitBtn.classList.add('ball-anim');
    
        // Enviar formulario tras ~0.9s para que se vea la animación completa
        setTimeout(() => form.submit(), 900);
      });
    })();
 </script>

<?php include __DIR__ . '/includes/footer.php'; ?>
<!-- Plantilla de pie de página común con scripts y cierre de HTML -->
