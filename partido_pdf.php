<?php
/**
 * partido_pdf.php — Informe imprimible con pie de página y QR (Fase 4+)
 * ----------------------------------------------------------------------------------
 * Genera una vista HTML lista para "Imprimir / Guardar como PDF" que incluye:
 *  - Cabecera con escudo, nombres y marcador.
 *  - Tabla de KPIs (pases, córners, banda, tiros, goles, racha máx.).
 *  - Tabla cronológica de eventos.
 *  - Pie de página con firma/escudo y un **QR** que apunta a la página del partido.
 *
 * Seguridad:
 *  - Requiere sesión iniciada y controla que el partido pertenezca al usuario.
 *  - No expone datos sensibles (emails se almacenan cifrados; aquí solo se muestra lo mínimo).
 *
 * Dependencias:
 *  - Bootstrap 5 desde jsDelivr (ya permitido por la CSP global; aquí no incluimos header.php a propósito).
 *  - El QR se genera usando el endpoint de Google Chart (simple y sin librerías). Si tu CSP restringe
 *    imágenes externas, puedes cambiar a un QR local más adelante (p. ej. librería phpqrcode).
 * ----------------------------------------------------------------------------------
 */
require_once __DIR__ . '/includes/db.php';   // Carga PDO, sesión y helpers
require_login();                             // Exige usuario autenticado

// 1) Validación y obtención del ID de partido
$match_id = (int)($_GET['id'] ?? 0);         // Convierte a entero de forma segura
if ($match_id <= 0) {                        // Si no hay ID válido, termina
  die('ID inválido');
}

// 2) Cargar el partido y garantizar propiedad del usuario
$stmt = $pdo->prepare('SELECT * FROM matches WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([$match_id, $_SESSION['user_id']]);
$match = $stmt->fetch();
if (!$match) {                               // Si no es tuyo o no existe
  die('Partido no encontrado');
}

// 3) Cargar datos de nuestro equipo y rival (descifrados para mostrar)
$ourQ = $pdo->prepare('SELECT name_enc, crest_path FROM teams WHERE id=? LIMIT 1');
$ourQ->execute([$match['our_team_id']]);
$our = $ourQ->fetch();
$ourName = $our ? sf_decrypt($our['name_enc'], $ENC_KEY) : 'Nuestro equipo';
$oppName = sf_decrypt($match['opponent_name_enc'], $ENC_KEY);

// 4) Cargar totales agregados por equipo (KPIs)
$st = $pdo->prepare('SELECT team_side, passes, corners, throwins, shots_on_target, goals, max_pass_streak FROM match_stats WHERE match_id=?');
$st->execute([$match_id]);
$stats = [
  'us'   => ['passes'=>0,'corners'=>0,'throwins'=>0,'shots_on_target'=>0,'goals'=>0,'max_pass_streak'=>0],
  'them' => ['passes'=>0,'corners'=>0,'throwins'=>0,'shots_on_target'=>0,'goals'=>0,'max_pass_streak'=>0],
];
foreach ($st as $row) {
  $stats[$row['team_side']] = [
    'passes'          => (int)$row['passes'],
    'corners'         => (int)$row['corners'],
    'throwins'        => (int)$row['throwins'],
    'shots_on_target' => (int)$row['shots_on_target'],
    'goals'           => (int)$row['goals'],
    'max_pass_streak' => (int)$row['max_pass_streak'],
  ];
}

// 5) Cargar eventos cronológicos
$ev = $pdo->prepare('SELECT team_side, event_type, streak_at_event, created_at FROM match_events WHERE match_id=? ORDER BY created_at ASC');
$ev->execute([$match_id]);
$events = $ev->fetchAll();

// 6) Construir URL absoluta al detalle del partido para el QR (p.ej. partido_estadisticas.php)
//    - Usamos el host y esquema actuales para funcionar en InfinityFree sin configuración extra.
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUri = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
$target  = $scheme . '://' . $host . $baseUri . '/partido_estadisticas.php?id=' . $match_id;  // URL destino del QR

// 7) Generar URL del QR con Google Chart API (300x300, margen 0). Alternativa local: phpqrcode.
$qrUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chld=L|0&chl=' . rawurlencode($target);

// 8) Datos para el pie de página (firma)
$footerTitle = 'STATSFUT – Informe oficial';                 // Título del pie
$footerNote  = 'Generado el ' . date('Y-m-d H:i') . ' · ' .   // Nota con fecha y nombre de equipo
               $ourName . ' vs ' . $oppName;

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>STATSFUT – Informe partido #<?php echo (int)$match_id; ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* Ajustes visuales generales para impresión */
    body { background: #fff; }
    .kpi-table td, .kpi-table th { font-size: .95rem; }

    /* Pie de página fijo para impresión */
    @media print {
      .no-print { display: none !important; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .print-footer {
        position: fixed; bottom: 0; left: 0; right: 0;
        border-top: 1px solid #ddd; padding: .5rem 0; background: #fff;
      }
      .content-with-footer { padding-bottom: 120px; } /* deja espacio para el footer */
    }
    /* En pantalla también dejamos el pie visible al final */
    .print-footer { margin-top: 2rem; }
    .qr-box { width: 140px; }
    .crest-mini { height: 40px; border-radius: 6px; }
  </style>
</head>
<body>
  <div class="container py-4 content-with-footer">
    <!-- Botón para imprimir (no aparece en PDF) -->
    <div class="no-print mb-3 text-end">
      <button class="btn btn-primary" onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>

    <!-- Cabecera con logos/nombres y marcador -->
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div class="d-flex align-items-center gap-3">
        <?php if (!empty($our['crest_path'])): ?>
          <img src="<?php echo e($our['crest_path']); ?>" alt="Escudo" style="height:64px;border-radius:6px;">
        <?php endif; ?>
        <div>
          <h1 class="h4 m-0">STATSFUT – Informe del partido</h1>
          <div class="text-muted small">#<?php echo (int)$match_id; ?> · <?php echo e($match['match_datetime']); ?> · <?php echo e($match['halves']); ?> partes × <?php echo e($match['minutes_per_half']); ?>'</div>
          <div class="text-muted small"><?php echo e($ourName); ?> <?php echo $match['our_team_is_home'] ? '(Local)' : '(Visitante)'; ?> vs <?php echo e($oppName); ?></div>
        </div>
      </div>
      <div class="display-5 fw-bold">
        <?php echo (int)$stats['us']['goals']; ?> <span class="text-muted">-</span> <?php echo (int)$stats['them']['goals']; ?>
      </div>
    </div>

    <!-- KPIs tabla -->
    <div class="card p-3 mb-4">
      <h2 class="h5">Totales</h2>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle kpi-table">
          <thead class="table-light">
            <tr>
              <th>Estadística</th>
              <th>Nosotros</th>
              <th>Rival</th>
            </tr>
          </thead>
          <tbody>
            <tr><td>Pases</td><td><?php echo (int)$stats['us']['passes']; ?></td><td><?php echo (int)$stats['them']['passes']; ?></td></tr>
            <tr><td>Córners</td><td><?php echo (int)$stats['us']['corners']; ?></td><td><?php echo (int)$stats['them']['corners']; ?></td></tr>
            <tr><td>Banda</td><td><?php echo (int)$stats['us']['throwins']; ?></td><td><?php echo (int)$stats['them']['throwins']; ?></td></tr>
            <tr><td>Tiros a puerta</td><td><?php echo (int)$stats['us']['shots_on_target']; ?></td><td><?php echo (int)$stats['them']['shots_on_target']; ?></td></tr>
            <tr><td>Goles</td><td><?php echo (int)$stats['us']['goals']; ?></td><td><?php echo (int)$stats['them']['goals']; ?></td></tr>
            <tr><td>Racha máxima de pases</td><td><?php echo (int)$stats['us']['max_pass_streak']; ?></td><td><?php echo (int)$stats['them']['max_pass_streak']; ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Eventos -->
    <div class="card p-3">
      <h2 class="h5">Eventos</h2>
      <?php if (!$events): ?>
        <div class="text-muted">No hay eventos registrados.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Equipo</th>
                <th>Evento</th>
                <th>Racha en evento</th>
                <th>Fecha / hora</th>
              </tr>
            </thead>
            <tbody>
              <?php $i=1; foreach ($events as $e): ?>
                <tr>
                  <td><?php echo $i++; ?></td>
                  <td><?php echo e($e['team_side'] === 'us' ? 'Nosotros' : 'Rival'); ?></td>
                  <td><?php echo e($e['event_type']); ?></td>
                  <td><?php echo (int)$e['streak_at_event']; ?></td>
                  <td><?php echo e($e['created_at']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Pie de página con firma/escudo y QR -->
  <div class="print-footer">
    <div class="container">
      <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
          <?php if (!empty($our['crest_path'])): ?>
            <img src="<?php echo e($our['crest_path']); ?>" alt="Escudo" class="crest-mini">
          <?php endif; ?>
          <div>
            <div class="fw-semibold"><?php echo e($footerTitle); ?></div>
            <div class="text-muted small"><?php echo e($footerNote); ?></div>
          </div>
        </div>
        <div class="text-end">
          <div class="small text-muted">Escanea para ver detalles</div>
          <div class="qr-box">
            <img src="<?php echo $qrUrl; ?>" alt="QR del partido" class="img-fluid" style="border:1px solid #eee;">
          </div>
          <div class="small mt-1">
            <a href="<?php echo e($target); ?>" target="_blank" class="text-decoration-none">Abrir enlace</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
