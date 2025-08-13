<?php
// Conexión a la base de datos y funciones de sesión/usuario
require_once __DIR__ . '/includes/db.php';
require_login(); // Asegura que solo usuarios logueados accedan

// Obtener ID del partido desde GET y validarlo
$match_id = (int)($_GET['id'] ?? 0);
if ($match_id <= 0) { header('Location: partidos_lista.php'); exit; }

// Cargar datos del partido y asegurarse de que pertenece al usuario
$stmt = $pdo->prepare('SELECT * FROM matches WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([$match_id, $_SESSION['user_id']]);
$match = $stmt->fetch();
if (!$match) { header('Location: partidos_lista.php'); exit; }

// Si el partido estaba programado, forzar a estado "ongoing" al iniciar
if ($match['status'] === 'scheduled') {
    $pdo->prepare('UPDATE matches SET status="ongoing" WHERE id=?')->execute([$match_id]);
}

// Cargar estadísticas actuales del partido (pases, córners, goles, etc.)
$stmt = $pdo->prepare('SELECT team_side, passes, corners, throwins, shots_on_target, goals, max_pass_streak FROM match_stats WHERE match_id=?');
$stmt->execute([$match_id]);
$stats = ['us'=>null,'them'=>null]; // Inicializa array para ambos equipos
foreach ($stmt as $row) { $stats[$row['team_side']] = $row; }

// Datos del equipo propio: nombre desencriptado y escudo
$ourTeam = $pdo->prepare('SELECT name_enc, crest_path FROM teams WHERE id=? LIMIT 1');
$ourTeam->execute([$match['our_team_id']]);
$our = $ourTeam->fetch();
$ourName = $our ? sf_decrypt($our['name_enc'],$ENC_KEY) : 'Nuestro equipo';
$oppName = sf_decrypt($match['opponent_name_enc'],$ENC_KEY);

// --- Endpoint AJAX inline para registrar eventos del partido ---
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');

    // Validar token CSRF
    if (!csrf_validate($_POST['csrf'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'CSRF']); exit;
    }

    // Determinar lado del equipo y tipo de evento
    $side = $_POST['side'] === 'them' ? 'them' : 'us';
    $type = $_POST['type'] ?? '';
    $valid = ['pass','corner','throwin','shot_on_target','goal'];
    if (!in_array($type, $valid, true)) { echo json_encode(['ok'=>false,'msg'=>'tipo']); exit; }

    try {
        $pdo->beginTransaction(); // Iniciar transacción para consistencia

        // Bloquear fila de runtime para evitar condiciones de carrera
        $rt = $pdo->prepare('SELECT us_current_streak, them_current_streak FROM match_runtime WHERE match_id=? FOR UPDATE');
        $rt->execute([$match_id]);
        $runtime = $rt->fetch();
        if (!$runtime) { throw new RuntimeException('runtime missing'); }

        // Rachas actuales
        $us_streak = (int)$runtime['us_current_streak'];
        $th_streak = (int)$runtime['them_current_streak'];

        // Actualización según tipo de evento
        if ($type === 'pass') {
            if ($side === 'us') { $us_streak++; $th_streak = 0; } else { $th_streak++; $us_streak = 0; }
            $pdo->prepare('UPDATE match_stats SET passes = passes + 1 WHERE match_id=? AND team_side=?')->execute([$match_id,$side]);
        } elseif ($type === 'corner') {
            if ($side === 'us') { $us_streak = 0; } else { $th_streak = 0; }
            $pdo->prepare('UPDATE match_stats SET corners = corners + 1 WHERE match_id=? AND team_side=?')->execute([$match_id,$side]);
        } elseif ($type === 'throwin') {
            if ($side === 'us') { $us_streak = 0; } else { $th_streak = 0; }
            $pdo->prepare('UPDATE match_stats SET throwins = throwins + 1 WHERE match_id=? AND team_side=?')->execute([$match_id,$side]);
        } elseif ($type === 'shot_on_target') {
            if ($side === 'us') { $us_streak = 0; } else { $th_streak = 0; }
            $pdo->prepare('UPDATE match_stats SET shots_on_target = shots_on_target + 1 WHERE match_id=? AND team_side=?')->execute([$match_id,$side]);
        } elseif ($type === 'goal') {
            // Incrementar goles del lado correspondiente
            if ($side === 'us') { $pdo->prepare('UPDATE match_stats SET goals = goals + 1 WHERE match_id=? AND team_side="us"')->execute([$match_id]); }
            else { $pdo->prepare('UPDATE match_stats SET goals = goals + 1 WHERE match_id=? AND team_side="them"')->execute([$match_id]); }

            // Guardar racha al momento del gol y resetar racha del rival
            $passes_streak = ($side === 'us') ? $us_streak : $th_streak;
            $pdo->prepare('INSERT INTO goals (match_id, team_side, passes_streak) VALUES (?,?,?)')->execute([$match_id,$side,$passes_streak]);
            if ($side === 'us') { $th_streak = 0; } else { $us_streak = 0; }
        }

        // Actualizar rachas actuales en tabla runtime
        $pdo->prepare('UPDATE match_runtime SET us_current_streak=?, them_current_streak=?, last_event_at=NOW() WHERE match_id=?')
            ->execute([$us_streak, $th_streak, $match_id]);

        // Actualizar máximo de racha si aplica
        if ($side === 'us') {
            $pdo->prepare('UPDATE match_stats SET max_pass_streak = GREATEST(max_pass_streak, ?) WHERE match_id=? AND team_side="us"')->execute([$us_streak, $match_id]);
        } else {
            $pdo->prepare('UPDATE match_stats SET max_pass_streak = GREATEST(max_pass_streak, ?) WHERE match_id=? AND team_side="them"')->execute([$th_streak, $match_id]);
        }

        // Guardar evento en auditoría
        $stAt = $side==='us' ? $us_streak : $th_streak;
        $pdo->prepare('INSERT INTO match_events (match_id, team_side, event_type, streak_at_event) VALUES (?,?,?,?)')
            ->execute([$match_id, $side, $type, $stAt]);

        $pdo->commit(); // Confirmar transacción
        echo json_encode(['ok'=>true,'us_streak'=>$us_streak,'them_streak'=>$th_streak]);
    } catch (Throwable $t) {
        $pdo->rollBack(); // Revertir cambios si hay error
        error_log('[STATSFUT][EV] '.$t->getMessage());
        echo json_encode(['ok'=>false]);
    }
    exit; // Finaliza ejecución para AJAX
}

// ==============================
// Incluir cabecera del layout
// ==============================
include __DIR__ . '/includes/header.php'; // Cabecera HTML
?>

<div class="container" style="max-width:1100px;">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-2">
      <?php if (!empty($our['crest_path'])): ?><img src="<?php echo e($our['crest_path']); ?>" alt="Escudo" style="height:48px;border-radius:6px;"><?php endif; ?>
      <div>
        <div class="fw-bold"><?php echo e($ourName); ?> <?php echo $match['our_team_is_home'] ? '(Local)' : '(Visitante)'; ?></div>
        <small class="text-muted">vs <?php echo e($oppName); ?> · <?php echo e($match['match_datetime']); ?></small>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-info" href="partido_estadisticas.php?id=<?php echo (int)$match_id; ?>">Estadísticas</a>
      <a class="btn btn-outline-success" href="partidos_lista.php">Partidos</a>
      <a class="btn btn-danger" href="partido_finalizar.php?id=<?php echo (int)$match_id; ?>&csrf=<?php echo e(csrf_token()); ?>" onclick="return confirm('¿Marcar como finalizado?');">Finalizar</a>
    </div>
  </div>

  <!-- Layout espejo: columna izquierda (nosotros) con botón de pase en la 2ª fila; derecha (ellos) invertida -->
  <div class="row g-3">
    <!-- Nosotros -->
    <div class="col-md-6">
      <div class="card p-3">
        <div class="d-grid gap-2 mb-3">
          <button data-side="us" data-type="goal" class="btn btn-outline-success btn-lg ev">⚽ Gol</button>
        </div>
        <div class="d-grid gap-2 mb-3">
          <button data-side="us" data-type="pass" class="btn btn-primary btn-lg ev">Pase</button>
        </div>
        <div class="d-grid gap-2 mb-2">
          <div class="row g-2">
            <div class="col-4"><button data-side="us" data-type="throwin" class="btn btn-outline-light ev w-100">Saque banda</button></div>
            <div class="col-4"><button data-side="us" data-type="corner" class="btn btn-outline-warning ev w-100">Córner</button></div>
            <div class="col-4"><button data-side="us" data-type="shot_on_target" class="btn btn-outline-info ev w-100">Tiro a puerta</button></div>
          </div>
        </div>
        <div class="small text-muted">Racha actual: <span id="us-streak"><?php
          $rt = $pdo->prepare('SELECT us_current_streak FROM match_runtime WHERE match_id=?');
          $rt->execute([$match_id]); echo (int)($rt->fetch()['us_current_streak'] ?? 0);
        ?></span> · Máx racha: <span id="us-max"><?php echo (int)($stats['us']['max_pass_streak'] ?? 0); ?></span></div>
        <hr>
        <div class="row text-center">
          <div class="col"><div class="fw-bold h4 mb-0" id="us-passes"><?php echo (int)($stats['us']['passes'] ?? 0); ?></div><small>Pases</small></div>
          <div class="col"><div class="fw-bold h4 mb-0" id="us-throwins"><?php echo (int)($stats['us']['throwins'] ?? 0); ?></div><small>Banda</small></div>
          <div class="col"><div class="fw-bold h4 mb-0" id="us-corners"><?php echo (int)($stats['us']['corners'] ?? 0); ?></div><small>Córner</small></div>
          <div class="col"><div class="fw-bold h4 mb-0" id="us-shots"><?php echo (int)($stats['us']['shots_on_target'] ?? 0); ?></div><small>Tiros</small></div>
          <div class="col"><div class="fw-bold h4 mb-0" id="us-goals"><?php echo (int)($stats['us']['goals'] ?? 0); ?></div><small>Goles</small></div>
        </div>
      </div>
    </div>

    <!-- Ellos (invertido: pase abajo, gol arriba) -->
    <div class="col-md-6">
      <div class="card p-3">
        <div class="d-grid gap-2 mb-3">
          <button data-side="them" data-type="goal" class="btn btn-outline-danger btn-lg ev">⚽ Gol</button>
        </div>
        <div class="d-grid gap-2 mb-3">
          <div class="row g-2">
            <div class="col-4"><button data-side="them" data-type="throwin" class="btn btn-outline-light ev w-100">Saque banda</button></div>
            <div class="col-4"><button data-side="them" data-type="corner" class="btn btn-outline-warning ev w-100">Córner</button></div>
            <div class="col-4"><button data-side="them" data-type="shot_on_target" class="btn btn-outline-info ev w-100">Tiro a puerta</button></div>
          </div>
        </div>
        <div class="d-grid gap-2 mb-2">
          <button data-side="them" data-type="pass" class="btn btn-primary btn-lg ev">Pase</button>
        </div>
        <div class="small text-muted">Racha actual: <span id="them-streak"><?php
          $rt = $pdo->prepare('SELECT them_current_streak FROM match_runtime WHERE match_id=?');
          $rt->execute([$match_id]); echo (int)($rt->fetch()['them_current_streak'] ?? 0);
        ?></span> · Máx racha: <span id="them-max"><?php echo (int)($stats['them']['max_pass_streak'] ?? 0); ?></span></div>
        <hr>
        <div class="row text-center">
          <div class="col"><div class="fw-bold h4 mb-0" id="them-passes"><?php echo (int)($stats['them']['passes'] ?? 0); ?></div><small>Pases</small></div>
          <div class="col"><div class="fw-bold h4 mb-0" id="them-throwins"><?php echo (int)($stats['them']['throwins'] ?? 0); ?></div><small>Banda</small></div>
          <div class="col"><div class="fw-bold h4 mb-0" id="them-corners"><?php echo (int)($stats['them']['corners'] ?? 0); ?></div><small>Córner</small></div>
          <div class="col"><div class="fw-bold h4 mb-0" id="them-shots"><?php echo (int)($stats['them']['shots_on_target'] ?? 0); ?></div><small>Tiros</small></div>
          <div class="col"><div class="fw-bold h4 mb-0" id="them-goals"><?php echo (int)($stats['them']['goals'] ?? 0); ?></div><small>Goles</small></div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  // Token CSRF y URL del endpoint AJAX
  const csrf = '<?=e(csrf_token())?>';
  const url = 'partido.php?id=<?=$match_id?>&ajax=1';

  // Selección de todos los botones de evento
  const btns = document.querySelectorAll('button.ev');

  // Función para actualizar contadores en la UI sin recargar
  function bump(side, type, streaks){
    const map = {
      'pass': side+'-passes',
      'corner': side+'-corners',
      'throwin': side+'-throwins',
      'shot_on_target': side+'-shots',
      'goal': side+'-goals'
    };

    // Incrementa contador visible
    if (map[type]) {
      const el = document.getElementById(map[type]);
      if (el) el.textContent = parseInt(el.textContent||'0',10) + 1;
    }

    // Actualizar rachas actuales y máximas
    if (typeof streaks !== 'undefined'){
      document.getElementById('us-streak').textContent = streaks.us;
      document.getElementById('them-streak').textContent = streaks.them;

      // Actualizar máximos solo para pases
      if (side==='us' && type==='pass'){
        const cur = parseInt(document.getElementById('us-streak').textContent,10);
        const maxEl = document.getElementById('us-max');
        if (cur > parseInt(maxEl.textContent||'0',10)) maxEl.textContent = cur;
      }
      if (side==='them' && type==='pass'){
        const cur = parseInt(document.getElementById('them-streak').textContent,10);
        const maxEl = document.getElementById('them-max');
        if (cur > parseInt(maxEl.textContent||'0',10)) maxEl.textContent = cur;
      }
    }
  }

  // Asignar evento click a todos los botones
  btns.forEach(b=>{
    b.addEventListener('click', async (e)=>{
      e.preventDefault();
      const side = b.dataset.side;
      const type = b.dataset.type;
      b.disabled = true; // Evitar múltiples clics simultáneos
      try{
        const fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('side', side);
        fd.append('type', type);

        // Petición AJAX a servidor
        const res = await fetch(url, {method:'POST', body: fd});
        const j = await res.json();

        // Actualizar UI según respuesta
        if(j.ok){
          bump(side, type, {us:j.us_streak, them:j.them_streak});
        } else {
          alert('No se pudo registrar el evento');
        }
      }catch(err){
        alert('Error de red');
      } finally {
        b.disabled = false;
      }
    });
  });
})();
</script>
<?php 
// ==============================
// Incluir footer del layout
// ==============================
include __DIR__ . '/includes/footer.php';
?>
