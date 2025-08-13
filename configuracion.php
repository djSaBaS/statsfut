<?php
require_once __DIR__ . '/includes/db.php';
require_login(); // Verificar que el usuario esté autenticado

$alert = null; // Mensaje para mostrar feedback al usuario

// ==============================
// Cargar el equipo propio si existe
// ==============================
$team = null;
$stmt = $pdo->prepare('SELECT id, name_enc, crest_path, is_own FROM teams WHERE user_id=? AND is_own=1 LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$team = $stmt->fetch();

// ==============================
// Manejo de POST: crear o actualizar equipo
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validación CSRF
    if (!csrf_validate($_POST['csrf'] ?? '')) {
        $alert = ['type'=>'danger','msg'=>'CSRF token inválido'];
    } else {
        // Normalizar y validar nombre de equipo
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $alert = ['type'=>'danger','msg'=>'El nombre del equipo es obligatorio'];
        } else {
            // ==============================
            // Validación y subida opcional del escudo
            // ==============================
            $crestPath = $team['crest_path'] ?? null; // Ruta existente por defecto
            if (!empty($_FILES['crest']['name'])) {
                $f = $_FILES['crest'];
                
                if ($f['error'] === UPLOAD_ERR_OK) {
                    // Tipos permitidos y extensión correspondiente
                    $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
                    $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']);

                    if (!isset($allowed[$mime])) {
                        $alert = ['type'=>'danger','msg'=>'Formato de imagen no permitido (usa PNG, JPG o WEBP).'];
                    } elseif ($f['size'] > 2*1024*1024) { // Tamaño máximo 2MB
                        $alert = ['type'=>'danger','msg'=>'El archivo supera 2MB.'];
                    } else {
                        // Guardar con nombre único para evitar colisiones
                        $ext = $allowed[$mime];
                        $baseDir = __DIR__ . '/assets/img/crests';
                        if (!is_dir($baseDir)) @mkdir($baseDir, 0755, true); // Crear carpeta si no existe
                        $filename = 'crest_u' . (int)$_SESSION['user_id'] . '_' . time() . '.' . $ext;
                        $dest = $baseDir . '/' . $filename;

                        if (!move_uploaded_file($f['tmp_name'], $dest)) {
                            $alert = ['type'=>'danger','msg'=>'No se pudo guardar el archivo subido.'];
                        } else {
                            // Ruta relativa para servir en la web
                            $crestPath = 'assets/img/crests/' . $filename;
                        }
                    }
                } elseif ($f['error'] !== UPLOAD_ERR_NO_FILE) {
                    $alert = ['type'=>'danger','msg'=>'Error al subir el archivo.'];
                }
            }

            // ==============================
            // Guardar datos en la base de datos
            // ==============================
            if (!$alert) {
                $name_enc = sf_encrypt($name, $ENC_KEY); // Encriptar nombre del equipo

                if ($team) {
                    // Actualizar equipo existente
                    $stmt = $pdo->prepare('UPDATE teams SET name_enc=?, crest_path=? WHERE id=? AND user_id=?');
                    $stmt->execute([$name_enc, $crestPath, $team['id'], $_SESSION['user_id']]);
                    $alert = ['type'=>'success','msg'=>'Equipo actualizado correctamente'];
                } else {
                    // Crear nuevo equipo
                    $stmt = $pdo->prepare('INSERT INTO teams (user_id, name_enc, crest_path, is_own) VALUES (?,?,?,1)');
                    $stmt->execute([$_SESSION['user_id'], $name_enc, $crestPath]);
                    $alert = ['type'=>'success','msg'=>'Equipo creado'];
                }
            }
        }
    }

    // ==============================
    // Recargar datos para mostrar cambios
    // ==============================
    $stmt = $pdo->prepare('SELECT id, name_enc, crest_path, is_own FROM teams WHERE user_id=? AND is_own=1 LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $team = $stmt->fetch();
}

// ==============================
// Incluir cabecera del layout
// ==============================
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:720px;">
  <div class="card p-4">
    <h1 class="h3">Configuración de mi equipo</h1>
    <?php if ($alert): ?><div class="alert alert-<?=e($alert['type'])?> mt-3"><?php echo e($alert['msg']); ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="mt-3">
      <?php echo csrf_field(); ?>
      <div class="mb-3">
        <label class="form-label">Nombre del equipo</label>
        <input type="text" name="name" class="form-control" value="<?php echo e($team ? sf_decrypt($team['name_enc'],$ENC_KEY) : ''); ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Escudo (PNG/JPG/WEBP, máx 2MB)</label>
        <input type="file" name="crest" class="form-control" accept="image/png,image/jpeg,image/webp">
      </div>
      <?php if (!empty($team['crest_path'])): ?>
        <div class="mb-3">
          <img src="<?php echo e($team['crest_path']); ?>" alt="Escudo" style="height:80px">
        </div>
      <?php endif; ?>
      <div class="d-flex gap-2">
        <button class="btn btn-success" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<?php 
// ==============================
// Incluir footer del layout
// ==============================
include __DIR__ . '/includes/footer.php';
?>
