<?php
// index.php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: home.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StatsFut - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: url('assets/img/campo_futbol.jpg') no-repeat center center fixed;
            background-size: cover;
        }
        .login-box {
            background-color: rgba(255, 255, 255, 0.9);
            padding: 2rem;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0px 4px 15px rgba(0, 0, 0, 0.3);
        }
        .logo-circle {
            width: 100px;
            height: 100px;
            background-color: white;
            border-radius: 50%;
            margin: -70px auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.3);
        }
        .logo-circle img {
            width: 80%;
        }
        /* Animación botón */
        .btn-football {
            transition: all 0.5s ease;
        }
        .btn-football.animate {
            background-image: url('assets/img/ball.png');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            color: transparent;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            transform: translateX(400px) rotate(720deg);
        }
    </style>
</head>
<body>
    <div class="container vh-100 d-flex justify-content-center align-items-center">
        <div class="login-box position-relative">
            <div class="logo-circle">
                <img src="assets/img/logo_statsfut.png" alt="Logo StatsFut">
            </div>
            <h3 class="mb-4">Iniciar Sesión</h3>
            <form method="POST" action="login.php" id="loginForm">
                <div class="mb-3">
                    <input type="text" name="username" class="form-control" placeholder="Usuario" required>
                </div>
                <div class="mb-3">
                    <input type="password" name="password" class="form-control" placeholder="Contraseña" required>
                </div>
                <button type="submit" class="btn btn-danger w-100 btn-football">Entrar</button>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            let btn = document.querySelector('.btn-football');
            btn.classList.add('animate');
            setTimeout(() => {
                this.submit();
            }, 800);
        });
    </script>
</body>
</html>
