<?php
require_once __DIR__ . '/includes/db.php';
require_login(); // Asegurar que el usuario esté autenticado

// ==============================
// Cargar todos los partidos del usuario
// Ordenados por fecha descendente y luego por ID descendente
// ==============================
$stmt = $pdo->prepare('SELECT id, match_datetime, status, halves, minutes_per_half FROM matches WHERE user_id=? ORDER BY match_datetime DESC, id DESC');
$stmt->execute([$_SESSION['user_id']]);
$rows = $stmt->fetchAll(); // Almacenar todos los partidos en un array

// ==============================
// Incluir cabecera del layout
// ==============================
include __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 m-0">Mis partidos</h1>
    <a class="btn btn-success" href="partido_nuevo.php">Nuevo partido</a>
  </div>
  <div class="table-responsive card p-0">
    <table class="table table-dark table-hover m-0 align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Fecha y hora</th>
          <th>Estado</th>
          <th>Partes × Min</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>#<?php echo (int)$r['id']; ?></td>
            <td><?php echo e($r['match_datetime']); ?></td>
            <td><span class="badge bg-<?php echo $r['status']==='finished'?'success':($r['status']==='ongoing'?'warning text-dark':'secondary'); ?>"><?php echo e($r['status']); ?></span></td>
            <td><?php echo (int)$r['halves']; ?> × <?php echo (int)$r['minutes_per_half']; ?>'</td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="partido.php?id=<?php echo (int)$r['id']; ?>">Abrir</a>
              <a class="btn btn-sm btn-outline-info" href="partido_estadisticas.php?id=<?php echo (int)$r['id']; ?>">Estadísticas</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
// ==============================
// Incluir footer del layout
// ==============================
include __DIR__ . '/includes/footer.php';
?>
