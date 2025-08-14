<?php
/**
 * partido.php (Fase 4)
 * ------------------------------------------------------------
 * Pantalla de registro en vivo con:
 * - Marcador visual (nombres, escudos, goles)
 * - Cronómetro por parte con botones de control
 * - Botones de eventos (pase, banda, córner, tiro, gol) en layout espejo
 * - Endpoints AJAX para registrar eventos y controlar el reloj
 * Seguridad: require_login, CSRF, PDO, transacciones, control propietario.
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/includes/db.php'; // Carga la conexión PDO, sesión activa y helpers (CSRF, cifrado, etc.)
require_login();                           // Requiere que el usuario esté autenticado para continuar

// Obtener y sanitizar el ID del partido desde la URL
$match_id = (int)($_GET['id'] ?? 0); // Convierte a entero para prevenir inyecciones
if ($match_id <= 0) {                // Si no hay un ID válido
    header('Location: partidos_lista.php'); // Redirige a la lista de partidos
    exit; // Detiene la ejecución
}

// 1) Cargar datos del partido asegurando que pertenece al usuario logueado
$stmt = $pdo->prepare('SELECT * FROM matches WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([$match_id, $_SESSION['user_id']]);
$match = $stmt->fetch();
if (!$match) { // Si no existe o no pertenece al usuario
    header('Location: partidos_lista.php');
    exit;
}

// 2) Si el partido estaba programado, cambiar su estado a "en curso"
if ($match['status'] === 'scheduled') {
    $pdo->prepare('UPDATE matches SET status="ongoing" WHERE id=?')->execute([$match_id]);
    $match['status'] = 'ongoing'; // También lo actualizamos en la variable local
}

// 3) Verificar existencia de registros de reloj y estadísticas
//    match_stats: generado en la creación del partido
//    match_clock: si no existe, se crea con valores por defecto
$stmt = $pdo->prepare('SELECT current_half, is_running, seconds_in_half, last_started_at FROM match_clock WHERE match_id=?');
$stmt->execute([$match_id]);
$clock = $stmt->fetch();
if (!$clock) {
    // Inserta valores iniciales (parte 1, detenido, 0 segundos, sin inicio)
    $pdo->prepare('INSERT INTO match_clock (match_id) VALUES (?)')->execute([$match_id]);
    $clock = ['current_half'=>1,'is_running'=>0,'seconds_in_half'=>0,'last_started_at'=>null];
}

// 4) Cargar estadísticas agregadas de ambos equipos
$stmt = $pdo->prepare('SELECT team_side, passes, corners, throwins, shots_on_target, goals, max_pass_streak 
                       FROM match_stats WHERE match_id=?');
$stmt->execute([$match_id]);
$stats = ['us'=>null,'them'=>null];
foreach ($stmt as $row) {
    $stats[$row['team_side']] = $row;
}

// 5) Cargar datos de cabecera: equipo propio y rival (con nombres descifrados)
$ourTeam = $pdo->prepare('SELECT name_enc, crest_path FROM teams WHERE id=? LIMIT 1');
$ourTeam->execute([$match['our_team_id']]);
$our = $ourTeam->fetch();
$ourName = $our ? sf_decrypt($our['name_enc'],$ENC_KEY) : 'Nuestro equipo';
$oppName = sf_decrypt($match['opponent_name_enc'],$ENC_KEY);

// 6) Modo AJAX: manejar eventos y reloj en el mismo archivo
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json'); // Respuesta en formato JSON

    // 6.1) Validación de token CSRF
    if (!csrf_validate($_POST['csrf'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'CSRF']);
        exit;
    }

    // 6.2) Determinar modo de acción: evento o control de reloj
    $mode = $_POST['mode'] ?? 'event';

    // ---------------------------
    // A) Registrar eventos (pases, córners, etc.)
    // ---------------------------
    if ($mode === 'event') {
        $side = $_POST['side'] === 'them' ? 'them' : 'us'; // Lado validado
        $type = $_POST['type'] ?? '';
        $valid = ['pass','corner','throwin','shot_on_target','goal']; // Tipos permitidos
        if (!in_array($type, $valid, true)) {
            echo json_encode(['ok'=>false,'msg'=>'tipo']);
            exit;
        }

        try {
            $pdo->beginTransaction(); // Asegura consistencia de datos

            // Bloquear y leer rachas actuales
            $rt = $pdo->prepare('SELECT us_current_streak, them_current_streak 
                                 FROM match_runtime WHERE match_id=? FOR UPDATE');
            $rt->execute([$match_id]);
            $runtime = $rt->fetch();
            if (!$runtime) { throw new RuntimeException('runtime missing'); }

            $us_streak = (int)$runtime['us_current_streak'];
            $th_streak = (int)$runtime['them_current_streak'];

            // Lógica según tipo de evento
            if ($type === 'pass') {
                if ($side === 'us') { $us_streak++; $th_streak = 0; } else { $th_streak++; $us_streak = 0; }
                $pdo->prepare('UPDATE match_stats SET passes = passes + 1 WHERE match_id=? AND team_side=?')
                    ->execute([$match_id,$side]);
            } elseif ($type === 'corner') {
                if ($side === 'us') { $us_streak = 0; } else { $th_streak = 0; }
                $pdo->prepare('UPDATE match_stats SET corners = corners + 1 WHERE match_id=? AND team_side=?')
                    ->execute([$match_id,$side]);
            } elseif ($type === 'throwin') {
                if ($side === 'us') { $us_streak = 0; } else { $th_streak = 0; }
                $pdo->prepare('UPDATE match_stats SET throwins = throwins + 1 WHERE match_id=? AND team_side=?')
                    ->execute([$match_id,$side]);
            } elseif ($type === 'shot_on_target') {
                if ($side === 'us') { $us_streak = 0; } else { $th_streak = 0; }
                $pdo->prepare('UPDATE match_stats SET shots_on_target = shots_on_target + 1 WHERE match_id=? AND team_side=?')
                    ->execute([$match_id,$side]);
            } elseif ($type === 'goal') {
                $pdo->prepare('UPDATE match_stats SET goals = goals + 1 WHERE match_id=? AND team_side=?')
                    ->execute([$match_id,$side]);
                $passes_streak = ($side === 'us') ? $us_streak : $th_streak;
                $pdo->prepare('INSERT INTO goals (match_id, team_side, passes_streak) VALUES (?,?,?)')
                    ->execute([$match_id,$side,$passes_streak]);
                if ($side === 'us') { $th_streak = 0; } else { $us_streak = 0; }
            }

            // Guardar rachas actualizadas
            $pdo->prepare('UPDATE match_runtime 
                           SET us_current_streak=?, them_current_streak=?, last_event_at=NOW() 
                           WHERE match_id=?')
                ->execute([$us_streak, $th_streak, $match_id]);

            // Actualizar racha máxima
            if ($side === 'us') {
                $pdo->prepare('UPDATE match_stats 
                               SET max_pass_streak = GREATEST(max_pass_streak, ?) 
                               WHERE match_id=? AND team_side="us"')
                    ->execute([$us_streak, $match_id]);
            } else {
                $pdo->prepare('UPDATE match_stats 
                               SET max_pass_streak = GREATEST(max_pass_streak, ?) 
                               WHERE match_id=? AND team_side="them"')
                    ->execute([$th_streak, $match_id]);
            }

            // Registrar evento en el histórico
            $stAt = $side==='us' ? $us_streak : $th_streak;
            $pdo->prepare('INSERT INTO match_events (match_id, team_side, event_type, streak_at_event) 
                           VALUES (?,?,?,?)')
                ->execute([$match_id, $side, $type, $stAt]);

            $pdo->commit();
            echo json_encode(['ok'=>true,'us_streak'=>$us_streak,'them_streak'=>$th_streak]);
        } catch (Throwable $t) {
            $pdo->rollBack();
            error_log('[STATSFUT][EV] '.$t->getMessage());
            echo json_encode(['ok'=>false]);
        }
        exit;
    }

    // ---------------------------
    // B) Control del reloj (cronómetro por parte)
    // ---------------------------
    if ($mode === 'clock') {
        $action = $_POST['action'] ?? 'status'; // Acción solicitada
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT current_half, is_running, seconds_in_half, last_started_at 
                                FROM match_clock WHERE match_id=? FOR UPDATE');
            $q->execute([$match_id]);
            $c = $q->fetch();
            if (!$c) { throw new RuntimeException('clock missing'); }

            $now = new DateTimeImmutable('now');
            $acc = (int)$c['seconds_in_half'];
            if ((int)$c['is_running'] === 1 && $c['last_started_at']) {
                $ls = new DateTimeImmutable($c['last_started_at']);
                $acc += max(0, $now->getTimestamp() - $ls->getTimestamp());
            }

            // Ejecutar acción de control
            if ($action === 'start') {
                if ((int)$c['is_running'] === 0) {
                    $pdo->prepare('UPDATE match_clock SET is_running=1, last_started_at=NOW() WHERE match_id=?')
                        ->execute([$match_id]);
                    $c['is_running'] = 1;
                    $c['last_started_at'] = $now->format('Y-m-d H:i:s');
                }
            } elseif ($action === 'pause') {
                if ((int)$c['is_running'] === 1) {
                    $pdo->prepare('UPDATE match_clock SET is_running=0, seconds_in_half=?, last_started_at=NULL WHERE match_id=?')
                        ->execute([$acc, $match_id]);
                    $c['is_running'] = 0;
                    $c['seconds_in_half'] = $acc;
                    $c['last_started_at'] = null;
                }
            } elseif ($action === 'reset_half') {
                $pdo->prepare('UPDATE match_clock SET is_running=0, seconds_in_half=0, last_started_at=NULL WHERE match_id=?')
                    ->execute([$match_id]);
                $c['is_running'] = 0;
                $c['seconds_in_half'] = 0;
                $c['last_started_at'] = null;
            } elseif ($action === 'next_half') {
                $newHalf = min((int)$match['halves'], (int)$c['current_half'] + 1);
                $pdo->prepare('UPDATE match_clock SET current_half=?, is_running=0, seconds_in_half=0, last_started_at=NULL WHERE match_id=?')
                    ->execute([$newHalf, $match_id]);
                $c['current_half'] = $newHalf;
                $c['is_running'] = 0;
                $c['seconds_in_half'] = 0;
                $c['last_started_at'] = null;
            } elseif ($action === 'prev_half') {
                $newHalf = max(1, (int)$c['current_half'] - 1);
                $pdo->prepare('UPDATE match_clock SET current_half=?, is_running=0, seconds_in_half=0, last_started_at=NULL WHERE match_id=?')
                    ->execute([$newHalf, $match_id]);
                $c['current_half'] = $newHalf;
                $c['is_running'] = 0;
                $c['seconds_in_half'] = 0;
                $c['last_started_at'] = null;
            }

            $pdo->commit();

            // Recalcular tiempo actual tras commit
            $r = $pdo->prepare('SELECT current_half, is_running, seconds_in_half, last_started_at FROM match_clock WHERE match_id=?');
            $r->execute([$match_id]);
            $rc = $r->fetch();
            $isRunning = (int)$rc['is_running'];
            $respSeconds = (int)$rc['seconds_in_half'];
            if ($isRunning === 1 && $rc['last_started_at']) {
                $ls = new DateTimeImmutable($rc['last_started_at']);
                $respSeconds += max(0, (new DateTimeImmutable('now'))->getTimestamp() - $ls->getTimestamp());
            }
            $c['current_half'] = (int)$rc['current_half'];

            echo json_encode([
                'ok' => true,
                'current_half' => (int)$c['current_half'],
                'is_running' => $isRunning,
                'seconds' => $respSeconds,
                'minutes_per_half' => (int)$match['minutes_per_half'],
                'halves' => (int)$match['halves']
            ]);
        } catch (Throwable $t) {
            $pdo->rollBack();
            error_log('[STATSFUT][CLOCK] '.$t->getMessage());
            echo json_encode(['ok'=>false]);
        }
        exit;
    }

    // Acción no reconocida
    echo json_encode(['ok'=>false,'msg'=>'mode']);
    exit;
}

// 7) Cargar la cabecera HTML común
include __DIR__ . '/includes/header.php';
?>


<div class="container" style="max-width:1100px;">
  <!-- Barra superior: Marcador + Reloj + Acciones generales -->
  <div class="card p-3 mb-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <!-- Marcador visual: escudo + nombre + goles -->
      <div class="d-flex align-items-center gap-3">
        <?php if (!empty($our['crest_path'])): ?>
          <img src="<?php echo e($our['crest_path']); ?>" alt="Escudo" style="height:48px;border-radius:6px;"> <!-- Escudo local -->
        <?php endif; ?>
        <div class="d-flex flex-column">
          <div class="fw-bold"><?php echo e($ourName); ?> <?php echo $match['our_team_is_home'] ? '(Local)' : '(Visitante)'; ?></div> <!-- Nombre y condición -->
          <div class="small text-muted">vs <?php echo e($oppName); ?> · <?php echo e($match['match_datetime']); ?></div> <!-- Rival + fecha -->
        </div>
      </div>

      <!-- Resultado (goles) -->
      <div class="display-6 fw-bold text-center">
        <span id="us-goals-top"><?php echo (int)($stats['us']['goals'] ?? 0); ?></span>
        <span class="text-muted"> - </span>
        <span id="them-goals-top"><?php echo (int)($stats['them']['goals'] ?? 0); ?></span>
      </div>

      <!-- Cronómetro y controles -->
      <div class="d-flex flex-column align-items-end gap-2">
        <div class="h4 mb-0" id="clock-display">--:--</div> <!-- Aquí se pinta mm:ss -->
        <div class="small text-muted" id="clock-meta">Parte 1 / <?php echo (int)$match['halves']; ?> · <?php echo (int)$match['minutes_per_half']; ?>' por parte</div> <!-- Metadatos -->
        <div class="btn-group" role="group" aria-label="Controles de reloj">
          <button class="btn btn-success btn-sm" id="btn-start">Iniciar</button>
          <button class="btn btn-warning btn-sm" id="btn-pause">Pausa</button>
          <button class="btn btn-outline-secondary btn-sm" id="btn-reset">Reiniciar parte</button>
          <button class="btn btn-outline-primary btn-sm" id="btn-prev">Parte ◀</button>
          <button class="btn btn-outline-primary btn-sm" id="btn-next">Parte ▶</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Layout espejo de eventos (idéntico a Fase 2, se mantiene) -->
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

    <!-- Ellos (invertido) -->
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

  <!-- Acciones secundarias -->
  <div class="d-flex gap-2 justify-content-end mt-3">
    <a class="btn btn-outline-info" href="partido_estadisticas.php?id=<?php echo (int)$match_id; ?>">Estadísticas</a>
    <a class="btn btn-outline-success" href="partidos_lista.php">Partidos</a>
    <a class="btn btn-danger" href="partido_finalizar.php?id=<?php echo (int)$match_id; ?>&csrf=<?php echo e(csrf_token()); ?>" onclick="return confirm('¿Marcar como finalizado?');">Finalizar</a>
  </div>
</div>

<script>
// ------------------------------------------------------------
// Control de eventos y reloj del partido (cliente, Fase 4)
// - Sincroniza marcador y reloj con el servidor vía AJAX seguro
// - Actualiza contadores y rachas en la interfaz en tiempo real
// ------------------------------------------------------------
(function(){
  // --- 1) Configuración base ---
  const csrf = '<?= e(csrf_token()) ?>';                         // Token CSRF para peticiones seguras
  const url  = 'partido.php?id=<?=$match_id?>&ajax=1';            // URL común para AJAX

  // --- 2) Elementos de UI ---
  const btns = document.querySelectorAll('button.ev');           // Botones de eventos
  const goalTopUs   = document.getElementById('us-goals-top');   // Marcador superior (nosotros)
  const goalTopThem = document.getElementById('them-goals-top'); // Marcador superior (ellos)

  // Reloj
  const clockDisplay = document.getElementById('clock-display');
  const clockMeta    = document.getElementById('clock-meta');
  const btnStart     = document.getElementById('btn-start');
  const btnPause     = document.getElementById('btn-pause');
  const btnReset     = document.getElementById('btn-reset');
  const btnPrev      = document.getElementById('btn-prev');
  const btnNext      = document.getElementById('btn-next');

  // Estado del reloj
  let clock = {
    seconds: 0,
    is_running: 0,
    current_half: 1,
    minutes_per_half: <?= (int)$match['minutes_per_half'] ?>,
    halves: <?= (int)$match['halves'] ?>
  };
  let tickTimer = null; // ID del setInterval

  // --- 3) Utilidades ---
  function fmt(sec) {
    const m = Math.floor(sec / 60).toString().padStart(2, '0');
    const s = Math.floor(sec % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
  }

  function paintClock() {
    const maxSec = clock.minutes_per_half * 60;
    const sec = Math.min(clock.seconds, maxSec);
    clockDisplay.textContent = `${fmt(sec)} / ${fmt(maxSec)}`;
    clockMeta.textContent = `Parte ${clock.current_half} / ${clock.halves} · ${clock.minutes_per_half}' por parte`;
  }

  function startTicker() {
    if (tickTimer) return;
    tickTimer = setInterval(() => {
      if (clock.is_running) {
        clock.seconds++;
        paintClock();
      }
    }, 1000);
  }

  function stopTicker() {
    if (tickTimer) {
      clearInterval(tickTimer);
      tickTimer = null;
    }
  }

  // --- 4) Sincronización con el servidor ---
  async function syncClock(action = 'status') {
    try {
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('mode', 'clock');
      fd.append('action', action);

      const res = await fetch(url, { method: 'POST', body: fd });
      const j = await res.json();

      if (j.ok) {
        Object.assign(clock, {
          seconds: j.seconds,
          is_running: j.is_running,
          current_half: j.current_half,
          minutes_per_half: j.minutes_per_half,
          halves: j.halves
        });
        paintClock();
        clock.is_running ? startTicker() : stopTicker();
      }
    } catch (e) {
      console.error('Error de sincronización:', e);
    }
  }

  // --- 5) Enlaces de control del reloj ---
  btnStart.addEventListener('click', () => syncClock('start'));
  btnPause.addEventListener('click', () => syncClock('pause'));
  btnReset.addEventListener('click', () => syncClock('reset_half'));
  btnPrev .addEventListener('click', () => syncClock('prev_half'));
  btnNext .addEventListener('click', () => syncClock('next_half'));

  // --- 6) Sincronización inicial y periódica ---
  syncClock('status');
  setInterval(() => syncClock('status'), 15000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) syncClock('status');
  });

  // --- 7) Actualización de contadores y marcador ---
  function bump(side, type, streaks) {
    const map = {
      pass: side + '-passes',
      corner: side + '-corners',
      throwin: side + '-throwins',
      shot_on_target: side + '-shots',
      goal: side + '-goals'
    };

    if (map[type]) {
      const el = document.getElementById(map[type]);
      if (el) el.textContent = parseInt(el.textContent || '0', 10) + 1;
      if (type === 'goal') {
        (side === 'us' ? goalTopUs : goalTopThem).textContent = el.textContent;
      }
    }

    if (streaks) {
      document.getElementById('us-streak').textContent = streaks.us;
      document.getElementById('them-streak').textContent = streaks.them;

      // Actualizar racha máxima si se supera
      if (type === 'pass') {
        const cur = parseInt(document.getElementById(`${side}-streak`).textContent, 10);
        const maxEl = document.getElementById(`${side}-max`);
        if (cur > parseInt(maxEl.textContent || '0', 10)) maxEl.textContent = cur;
      }
    }
  }

  // --- 8) Registro de eventos ---
  btns.forEach(b => {
    b.addEventListener('click', async e => {
      e.preventDefault();
      const side = b.dataset.side;
      const type = b.dataset.type;
      b.disabled = true;

      try {
        const fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('mode', 'event');
        fd.append('side', side);
        fd.append('type', type);

        const res = await fetch(url, { method: 'POST', body: fd });
        const j = await res.json();

        if (j.ok) {
          bump(side, type, { us: j.us_streak, them: j.them_streak });
        } else {
          alert('No se pudo registrar el evento');
        }
      } catch {
        alert('Error de red');
      } finally {
        b.disabled = false;
      }
    });
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
