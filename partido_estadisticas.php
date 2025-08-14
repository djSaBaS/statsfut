<?php
/**
 * partido_estadisticas.php
 * ------------------------------------------------------------
 * Visualización de estadísticas de un partido específico:
 * - KPIs por equipo (pases, córners, saques de banda, tiros a puerta, goles)
 * - Rachas (máxima de pases)
 * - Cronología (timeline) de eventos por minuto
 * - Gráficas con Chart.js (barras comparativas y línea de eventos)
 * - Exportación CSV (totales y eventos)
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/includes/db.php'; // Conexión a DB y funciones comunes
require_login(); // Asegura que el usuario esté logueado

// Obtener y validar el ID del partido desde GET
$match_id = (int)($_GET['id'] ?? 0);
if ($match_id <= 0) { header('Location: partidos_lista.php'); exit; }

// Cargar datos del partido y validar que pertenece al usuario
$stmt = $pdo->prepare('SELECT * FROM matches WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([$match_id, $_SESSION['user_id']]);
$match = $stmt->fetch();
if (!$match) { header('Location: partidos_lista.php'); exit; }

// --- Exportaciones CSV ---
// Si se solicita exportar datos, generar CSV en función del tipo: "totals" o "events"
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $type = $_GET['type'] ?? 'totals';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=statsfut_'.$match_id.'_'.$type.'.csv');
    $out = fopen('php://output', 'w');

    if ($type === 'events') {
        // Exportar eventos cronológicos
        fputcsv($out, ['id','match_id','equipo','evento','racha_en_evento','fecha_hora']);
        $ev = $pdo->prepare('SELECT id, match_id, team_side, event_type, streak_at_event, created_at FROM match_events WHERE match_id=? ORDER BY created_at ASC');
        $ev->execute([$match_id]);
        foreach ($ev as $row) {
            fputcsv($out, [$row['id'],$row['match_id'],$row['team_side'],$row['event_type'],$row['streak_at_event'],$row['created_at']]);
        }
    } else {
        // Exportar totales agregados por equipo
        fputcsv($out, ['match_id','equipo','pases','córners','banda','tiros','goles','racha_max']);
        $st = $pdo->prepare('SELECT team_side, passes, corners, throwins, shots_on_target, goals, max_pass_streak FROM match_stats WHERE match_id=? ORDER BY FIELD(team_side,"us","them")');
        $st->execute([$match_id]);
        foreach ($st as $row) {
            fputcsv($out, [$match_id, $row['team_side'], $row['passes'],$row['corners'],$row['throwins'],$row['shots_on_target'],$row['goals'],$row['max_pass_streak']]);
        }
    }
    fclose($out);
    exit; // Finaliza la ejecución tras la exportación
}

// --- Cargar datos para la interfaz de usuario (UI) ---
// Datos del equipo propio: nombre desencriptado y escudo
$ourTeam = $pdo->prepare('SELECT name_enc, crest_path FROM teams WHERE id=? LIMIT 1');
$ourTeam->execute([$match['our_team_id']]);
$our = $ourTeam->fetch();
$ourName = $our ? sf_decrypt($our['name_enc'],$ENC_KEY) : 'Nuestro equipo';
$oppName = sf_decrypt($match['opponent_name_enc'],$ENC_KEY);

// Cargar totales de estadísticas por equipo
$st = $pdo->prepare('SELECT team_side, passes, corners, throwins, shots_on_target, goals, max_pass_streak FROM match_stats WHERE match_id=?');
$st->execute([$match_id]);
$stats = [
    'us'=>['passes'=>0,'corners'=>0,'throwins'=>0,'shots_on_target'=>0,'goals'=>0,'max_pass_streak'=>0], 
    'them'=>['passes'=>0,'corners'=>0,'throwins'=>0,'shots_on_target'=>0,'goals'=>0,'max_pass_streak'=>0]
];
foreach ($st as $row) {
    $stats[$row['team_side']] = [
        'passes' => (int)$row['passes'],
        'corners' => (int)$row['corners'],
        'throwins' => (int)$row['throwins'],
        'shots_on_target' => (int)$row['shots_on_target'],
        'goals' => (int)$row['goals'],
        'max_pass_streak' => (int)$row['max_pass_streak'],
    ];
}

// --- Cargar eventos para timeline por minuto ---
// Obtener todos los eventos del partido ordenados cronológicamente
$ev = $pdo->prepare('SELECT team_side, event_type, created_at FROM match_events WHERE match_id=? ORDER BY created_at ASC');
$ev->execute([$match_id]);
$events = $ev->fetchAll();

// Construir timeline simple: conteo de eventos por minuto relativo al primer evento
$labels = [];       // Minutos
$seriesUs = [];     // Eventos del equipo "us"
$seriesThem = [];   // Eventos del equipo "them"
if ($events) {
    $start = strtotime($events[0]['created_at']); // Timestamp del primer evento
    $bucketUs = [];   // Conteo por minuto para "us"
    $bucketThem = []; // Conteo por minuto para "them"
    foreach ($events as $e) {
        $min = (int)floor((strtotime($e['created_at']) - $start)/60); // minuto relativo
        if ($e['team_side']==='us') {
            $bucketUs[$min] = ($bucketUs[$min] ?? 0) + 1;
        } else {
            $bucketThem[$min] = ($bucketThem[$min] ?? 0) + 1;
        }
    }
    // Determinar el máximo minuto para rellenar todas las series
    $maxMin = max(array_keys($bucketUs ?: [0=>0]) + array_keys($bucketThem ?: [0=>0]));
    for ($i=0; $i<=$maxMin; $i++) {
        $labels[] = $i; // minuto i
        $seriesUs[] = (int)($bucketUs[$i] ?? 0);
        $seriesThem[] = (int)($bucketThem[$i] ?? 0);
    }
}

// Incluir cabecera HTML (Bootstrap, menú, etc.)
include __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:1100px;">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-2">
      <?php if (!empty($our['crest_path'])): ?><img src="<?php echo e($our['crest_path']); ?>" alt="Escudo" style="height:48px;border-radius:6px;"><?php endif; ?>
      <div>
        <div class="fw-bold"><?php echo e($ourName); ?> <?php echo $match['our_team_is_home'] ? '(Local)' : '(Visitante)'; ?></div>
        <small class="text-muted">vs <?php echo e($oppName); ?> · <?php echo e($match['match_datetime']); ?> · Estado: <span class="badge bg-<?php echo $match['status']==='finished'?'success':($match['status']==='ongoing'?'warning text-dark':'secondary'); ?>"><?php echo e($match['status']); ?></span></small>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-outline-primary" href="partido.php?id=<?php echo (int)$match_id; ?>">Registrar</a>
      <a class="btn btn-outline-secondary" href="partidos_lista.php">Partidos</a>
      <a class="btn btn-success" href="?id=<?php echo (int)$match_id; ?>&export=csv&type=totals">Exportar CSV (totales)</a>
      <a class="btn btn-info text-white" href="?id=<?php echo (int)$match_id; ?>&export=csv&type=events">Exportar CSV (eventos)</a>
      <a class="btn btn-warning" href="partido_editar.php?id=<?php echo (int)$match_id; ?>">Editar totales</a>
      <a class="btn btn-primary" href="partido_pdf.php?id=<?php echo (int)$match_id; ?>" target="_blank">Imprimir / PDF</a>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <?php
    /**
     * Generación de gráficos y KPIs del partido usando Chart.js
     * ------------------------------------------------------------
     * - Barras comparativas para totales de cada KPI (nosotros vs rival)
     * - Línea temporal (timeline) de eventos por minuto
     * - Datos embebidos desde PHP, convertidos a enteros para seguridad
     */
    $kpis = [
        ['label'=>'Pases','key'=>'passes'],
        ['label'=>'Córners','key'=>'corners'],
        ['label'=>'Banda','key'=>'throwins'],
        ['label'=>'Tiros a puerta','key'=>'shots_on_target'],
        ['label'=>'Goles','key'=>'goals'],
        ['label'=>'Racha máx.','key'=>'max_pass_streak'],
    ];
    foreach ($kpis as $k):
    ?>
      <div class="col-md-4">
        <div class="card p-3">
          <div class="d-flex justify-content-between">
            <div><div class="text-muted small"><?php echo e($k['label']); ?></div>
              <div class="h4 mb-0"><?php echo (int)$stats['us'][$k['key']]; ?> <span class="text-muted">/</span> <?php echo (int)$stats['them'][$k['key']]; ?></div>
            </div>
            <div class="text-end small text-muted">US / THEM</div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Gráfica de barras comparativa -->
  <div class="card p-3 mb-3">
    <h2 class="h5">Comparativa de estadísticas</h2>
    <canvas id="barTotals" height="120"></canvas>
  </div>

  <!-- Timeline de eventos por minuto -->
  <div class="card p-3 mb-3">
    <h2 class="h5">Eventos por minuto</h2>
    <canvas id="lineTimeline" height="120"></canvas>
    <?php if (!$events): ?><div class="text-muted small mt-2">No hay eventos registrados aún.</div><?php endif; ?>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
    // --- Datos totales embebidos desde PHP ---
    // Convertidos a enteros para seguridad y consistencia
    const totalsUs   = [
        <?php echo (int)$stats['us']['passes']; ?>,
        <?php echo (int)$stats['us']['corners']; ?>,
        <?php echo (int)$stats['us']['throwins']; ?>,
        <?php echo (int)$stats['us']['shots_on_target']; ?>,
        <?php echo (int)$stats['us']['goals']; ?>,
        <?php echo (int)$stats['us']['max_pass_streak']; ?>
    ];
    const totalsThem = [
        <?php echo (int)$stats['them']['passes']; ?>,
        <?php echo (int)$stats['them']['corners']; ?>,
        <?php echo (int)$stats['them']['throwins']; ?>,
        <?php echo (int)$stats['them']['shots_on_target']; ?>,
        <?php echo (int)$stats['them']['goals']; ?>,
        <?php echo (int)$stats['them']['max_pass_streak']; ?>
    ];

    // Etiquetas de los KPIs
    const labels = ['Pases','Córners','Banda','Tiros','Goles','Racha máx.'];

    // --- Gráfica de barras comparativa ---
    // Muestra los totales de cada KPI para "Nosotros" vs "Rival"
    const barCtx = document.getElementById('barTotals').getContext('2d');
    new Chart(barCtx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: 'Nosotros', data: totalsUs },
                { label: 'Rival', data: totalsThem }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true, precision: 0 } } // Escala Y sin decimales
        }
    });

    // --- Timeline de eventos por minuto ---
    // Datos embebidos desde PHP: arrays con cantidad de eventos por minuto
    const tlLabels = [<?php echo $labels ? implode(',', array_map('intval',$labels)) : ''; ?>];
    const tlUs     = [<?php echo $seriesUs ? implode(',', array_map('intval',$seriesUs)) : ''; ?>];
    const tlThem   = [<?php echo $seriesThem ? implode(',', array_map('intval',$seriesThem)) : ''; ?>];

    // Gráfico de línea para mostrar la progresión de eventos a lo largo del partido
    const lineCtx = document.getElementById('lineTimeline').getContext('2d');
    new Chart(lineCtx, {
        type: 'line',
        data: {
            labels: tlLabels,
            datasets: [
                { label: 'Nosotros', data: tlUs },
                { label: 'Rival', data: tlThem }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top' } },
            scales: { 
                y: { beginAtZero: true, precision: 0 }, // Escala Y sin decimales
                x: { title: { display: true, text: 'Minuto (relativo)' } } // Escala X: minutos del partido
            }
        }
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; // Footer y cierre de HTML ?>
