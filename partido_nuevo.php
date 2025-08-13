<?php
require_once __DIR__ . '/includes/db.php';
require_login(); // Asegurar que el usuario esté autenticado

// ==============================
// Cargar equipo propio (requerido para crear partidos)
// ==============================
$stmt = $pdo->prepare('SELECT id, name_enc FROM teams WHERE user_id=? AND is_own=1 LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$own = $stmt->fetch();

// Redirigir a configuración si no tiene equipo propio
if (!$own) {
    header('Location: configuracion.php');
    exit;
}

// ==============================
// Cargar configuraciones por defecto del usuario
// ==============================
$stmt = $pdo->prepare('SELECT halves_default, minutes_per_half FROM user_settings WHERE user_id=?');
$stmt->execute([$_SESSION['user_id']]);
$settings = $stmt->fetch() ?: ['halves_default'=>2,'minutes_per_half'=>25]; // Defaults: 2 mitades, 25 min

$alert = null;

// ==============================
// Manejo de POST: crear un nuevo partido
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validación CSRF
    if (!csrf_validate($_POST['csrf'] ?? '')) {
        $alert = ['type'=>'danger','msg'=>'CSRF inválido'];
    } else {
        // Normalización y validación de campos
        $our_is_home = (int)($_POST['our_is_home'] ?? 1) === 1 ? 1 : 0; // Nuestro equipo en casa
        $opponent    = trim((string)($_POST['opponent'] ?? ''));        // Nombre del rival
        $dt          = trim((string)($_POST['match_datetime'] ?? ''));  // Fecha y hora del partido
        $halves      = max(1, (int)($_POST['halves'] ?? $settings['halves_default'])); // Mitades mínimas 1
        $minutes     = max(1, (int)($_POST['minutes_per_half'] ?? $settings['minutes_per_half'])); // Minutos mínimos 1

        // Validación obligatoria de campos
        if ($opponent === '' || $dt === '') {
            $alert = ['type'=>'danger','msg'=>'Rellena rival y fecha/hora'];
        } else {
            try {
                // ==============================
                // Transacción para creación de partido
                // ==============================
                $pdo->beginTransaction();

                // Insertar partido en tabla matches
                $stmt = $pdo->prepare('INSERT INTO matches (user_id, our_team_id, our_team_is_home, opponent_name_enc, match_datetime, halves, minutes_per_half, status) VALUES (?,?,?,?,?,?,?,"scheduled")');
                $stmt->execute([
                    $_SESSION['user_id'],
                    $own['id'],
                    $our_is_home,
                    sf_encrypt($opponent,$ENC_KEY), // Encriptar nombre del rival
                    $dt,
                    $halves,
                    $minutes
                ]);
                $match_id = (int)$pdo->lastInsertId();

                // Crear estadísticas iniciales para ambos equipos
                $pdo->prepare('INSERT INTO match_stats (match_id, team_side) VALUES (?,"us"),(?,"them")')->execute([$match_id,$match_id]);

                // Crear registro runtime para rachas/estadísticas dinámicas
                $pdo->prepare('INSERT INTO match_runtime (match_id) VALUES (?)')->execute([$match_id]);

                $pdo->commit();

                // Redirigir a la página del partido recién creado
                header('Location: partido.php?id=' . $match_id);
                exit;

            } catch (Throwable $t) {
                // Rollback en caso de error y log interno
                $pdo->rollBack();
                error_log('[STATSFUT][MATCH_CREATE] '.$t->getMessage());
                $alert = ['type'=>'danger','msg'=>'No se pudo crear el partido'];
            }
        }
    }
}

// ==============================
// Incluir cabecera del layout
// ==============================
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:820px;">
  <div class="card p-4">
    <h1 class="h3">Nuevo partido</h1>
    <?php if ($alert): ?><div class="alert alert-<?=e($alert['type'])?> mt-3"><?php echo e($alert['msg']); ?></div><?php endif; ?>

    <form method="post">
      <?php echo csrf_field(); ?>

      <div class="row g-3 mt-1">
        <div class="col-md-6">
          <label class="form-label">Nuestro equipo</label>
          <input class="form-control" value="<?php echo e(sf_decrypt($own['name_enc'],$ENC_KEY)); ?>" disabled>
        </div>
        <div class="col-md-6">
          <label class="form-label">Local / Visitante</label>
          <select name="our_is_home" class="form-select">
            <option value="1">Local</option>
            <option value="0">Visitante</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Rival</label>
          <input type="text" name="opponent" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Fecha y hora</label>
          <input type="datetime-local" name="match_datetime" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Número de partes</label>
          <input type="number" min="1" name="halves" class="form-control" value="<?php echo (int)$settings['halves_default']; ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Minutos por parte</label>
          <input type="number" min="1" name="minutes_per_half" class="form-control" value="<?php echo (int)$settings['minutes_per_half']; ?>">
        </div>
      </div>

      <div class="d-flex gap-2 mt-4">
        <button class="btn btn-success" type="submit">Crear y abrir</button>
        <a class="btn btn-outline-secondary" href="home.php">Cancelar</a>
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
