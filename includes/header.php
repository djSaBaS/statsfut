<?php
/**
 * includes/header.php
 */
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>STATSFUT</title>
  <!-- Política CSP básica: ajusta orígenes si añades CDNs -->
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self'; connect-src 'self';">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <header class="sf-header">
    <div class="sf-container">
      <div class="brand">⚽ STATSFUT</div>
      <?php if (!empty($_SESSION['user_id'])): ?>
        <nav>
          <a href="home.php">Home</a>
          <a href="partido_nuevo.php">Nuevo partido</a>
          <a href="partidos_lista.php">Partidos</a>
          <a href="configuracion.php">Configuración</a>
          <a class="logout" href="logout.php">Salir</a>
        </nav>
      <?php endif; ?>
    </div>
  </header>
  <main class="sf-main sf-container">
