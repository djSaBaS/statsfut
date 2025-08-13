<?php
/**
 * partido_editar.php
 * ------------------------------------------------------------
 * Página para editar manualmente los totales agregados de un partido.
 * - Solo modifica la tabla `match_stats`.
 * - No toca `match_events` para evitar inconsistencias.
 * - Uso recomendado: partidos FINALIZADOS.
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/includes/db.php';  // Conexión a la base de datos
require_login();  // Verifica que el usuario esté logueado

// --- Validar ID del partido recibido por GET ---
$match_id = (int)($_GET['id'] ?? 0);
if ($match_id <= 0) { 
    header('Location: partidos_lista.php'); 
    exit; 
}

// --- Comprobar que el partido pertenece al usuario actual ---
$stmt = $pdo->prepare('SELECT id, status FROM matches WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([$match_id, $_SESSION['user_id']]);
$match = $stmt->fetch();
if (!$match) { 
    header('Location: partidos_lista.php'); 
    exit; 
}

// Inicializar variable de alertas para feedback en UI
$alert = null;

// --- Cargar totales actuales del partido ---
$st = $pdo->prepare('SELECT team_side, passes, corners, throwins, shots_on_target, goals, max_pass_streak FROM match_stats WHERE match_id=?');
$st->execute([$match_id]);
$stats = ['us'=>null,'them'=>null];  // Estructura para ambos equipos
foreach ($st as $row) { 
    $stats[$row['team_side']] = $row; 
}

// --- Procesar envío de formulario POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validación CSRF
    if (!csrf_validate($_POST['csrf'] ?? '')) {
        $alert = ['type'=>'danger','msg'=>'CSRF inválido'];
    } else {
        // Campos permitidos a editar
        $fields = ['passes','corners','throwins','shots_on_target','goals','max_pass_streak'];
        $input = ['us'=>[], 'them'=>[]];

        // Sanitizar entrada: convertir a enteros no negativos
        foreach (['us','them'] as $side) {
            foreach ($fields as $f) {
                $val = (int)max(0, (int)($_POST[$side.'_'.$f] ?? 0));
                $input[$side][$f] = $val;
            }
        }

        try {
            $pdo->beginTransaction(); // Iniciar transacción

            // Actualizar totales por equipo
            foreach (['us','them'] as $side) {
                $pdo->prepare('
                    UPDATE match_stats 
                    SET passes=?, corners=?, throwins=?, shots_on_target=?, goals=?, max_pass_streak=? 
                    WHERE match_id=? AND team_side=?
                ')->execute([
                    $input[$side]['passes'],
                    $input[$side]['corners'],
                    $input[$side]['throwins'],
                    $input[$side]['shots_on_target'],
                    $input[$side]['goals'],
                    $input[$side]['max_pass_streak'],
                    $match_id,
                    $side
                ]);
            }

            $pdo->commit(); // Confirmar cambios

            $alert = ['type'=>'success','msg'=>'Totales actualizados correctamente'];

            // Recargar stats actualizados para mostrar en el formulario
            $st = $pdo->prepare('SELECT team_side, passes, corners, throwins, shots_on_target, goals, max_pass_streak FROM match_stats WHERE match_id=?');
            $st->execute([$match_id]);
            $stats = ['us'=>null,'them'=>null];
            foreach ($st as $row) { 
                $stats[$row['team_side']] = $row; 
            }

        } catch (Throwable $t) {
            $pdo->rollBack(); // Revertir cambios en caso de error
            error_log('[STATSFUT][EDIT_TOTALS] '.$t->getMessage());
            $alert = ['type'=>'danger','msg'=>'No se pudieron guardar los cambios'];
        }
    }
}

// Incluir header común de la aplicación
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:900px;">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 m-0">Editar totales del partido #<?php echo (int)$match_id; ?></h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="partido_estadisticas.php?id=<?php echo (int)$match_id; ?>">Volver</a>
    </div>
  </div>

  <?php if ($match['status'] !== 'finished'): ?>
    <div class="alert alert-warning">Recomendación: edita totales con el partido <strong>finalizado</strong> para evitar inconsistencias con los eventos en vivo.</div>
  <?php endif; ?>

  <?php if ($alert): ?><div class="alert alert-<?=e($alert['type'])?>"><?php echo e($alert['msg']); ?></div><?php endif; ?>

  <form method="post" class="card p-3">
    <?php echo csrf_field(); ?>

    <div class="row g-4">
      <?php foreach (['us'=>'Nosotros','them'=>'Rival'] as $side => $label): $s=$stats[$side]; ?>
      <div class="col-md-6">
        <div class="card p-3 h-100">
          <h2 class="h5 mb-3"><?php echo e($label); ?></h2>
          <div class="row g-3">
            <?php
             /**
             * Definición de campos editables del formulario de totales del partido.
             * - $fields: array asociativo donde:
             *     key  => nombre de la columna en la tabla `match_stats`
             *     text => etiqueta legible que se mostrará en el formulario
             * - Se itera sobre cada campo para generar inputs dinámicamente para ambos equipos.
             */
              $fields = [
                'passes' => 'Pases',
                'corners' => 'Córners',
                'throwins' => 'Banda',
                'shots_on_target' => 'Tiros a puerta',
                'goals' => 'Goles',
                'max_pass_streak' => 'Racha máxima',
              ];
              // Iterar sobre cada campo para generar los inputs del formulario
              foreach ($fields as $key=>$text):
            ?>
              <div class="col-12">
                <label class="form-label"><?php echo e($text); ?></label>
                <input type="number" min="0" class="form-control" name="<?php echo $side.'_'.$key; ?>" value="<?php echo (int)$s[$key]; ?>">
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="d-flex gap-2 mt-4">
      <button class="btn btn-success" type="submit">Guardar cambios</button>
      <a class="btn btn-outline-secondary" href="partido_estadisticas.php?id=<?php echo (int)$match_id; ?>">Cancelar</a>
    </div>
  </form>
</div>
