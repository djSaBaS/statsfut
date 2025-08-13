<?php
// Incluir la configuración y conexión a la base de datos
require_once __DIR__ . '/includes/db.php';

// Verificar que el usuario ha iniciado sesión
// Esta función debería redirigir o abortar si no hay sesión activa
require_login();

// Inicializar variable para almacenar el último partido del usuario
$ultimo = null;

try {
    // Preparar consulta SQL para obtener el último partido del usuario
    // Se ordena primero por fecha del partido (descendente) y luego por ID (descendente)
    // Se limita el resultado a 1 registro
    $stmt = $pdo->prepare("
        SELECT id, status, match_datetime 
        FROM matches 
        WHERE user_id = ? 
        ORDER BY match_datetime DESC, id DESC 
        LIMIT 1
    ");
    
    // Ejecutar la consulta con el ID del usuario actualmente logueado
    $stmt->execute([$_SESSION['user_id']]);

    // Obtener el resultado de la consulta
    $ultimo = $stmt->fetch();

} catch (Throwable $t) {
    // Registrar cualquier error ocurrido durante la consulta en el log del servidor
    // No se expone al usuario final por seguridad
    error_log('[STATSFUT][HOME] ' . $t->getMessage());
}
?>

<?php
// Incluir la plantilla de cabecera común del sitio
// Contiene elementos como <head>, navegación, scripts y estilos compartidos
include __DIR__ . '/includes/header.php';
?>

<div class="grid" style="display:grid;grid-template-columns:1fr;gap:1rem;">
  <div class="card">
    <h2 style="margin-top:0">Acciones rápidas</h2>
    <p>
      <a class="btn primary" href="partido_nuevo.php">➕ Crear nuevo partido</a>
      <a class="btn" href="partidos_lista.php">📄 Ver partidos</a>
      <a class="btn" href="configuracion.php">⚙️ Configuración</a>
    </p>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Último partido</h2>
    <?php if ($ultimo): ?>
      <?php
      // Si existe un último partido registrado para el usuario, mostrar sus datos
      ?>
      <p>ID: <strong>#<?php echo e($ultimo['id']); ?></strong></p>
      <p>Fecha y hora: <strong><?php echo e($ultimo['match_datetime']); ?></strong></p>
      <p>Estado: <strong><?php echo e($ultimo['status']); ?></strong></p>
        <?php
        // Botones de acción para el último partido
        // - "Abrir partido": redirige a la página de detalle del partido
        // - "Ver estadísticas": redirige a la página de estadísticas del partido
        // Se usa (int) para asegurar que el ID sea un entero y prevenir inyecciones
        ?>
      <p>
        <a class="btn primary" href="partido.php?id=<?php echo (int)$ultimo['id']; ?>">Abrir partido</a>
        <a class="btn" href="partido_estadisticas.php?id=<?php echo (int)$ultimo['id']; ?>">Ver estadísticas</a>
      </p>
    <?php else: ?>
      <p>Aún no hay partidos. ¡Crea el primero!</p>
    <?php endif; ?>
  </div>
</div>

<?php
// Incluir la plantilla de pie de página común del sitio
// Contiene elementos compartidos como cierre de etiquetas HTML, scripts finales y posibles enlaces o información de copyright
include __DIR__ . '/includes/footer.php';
?>
