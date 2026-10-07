<?php
// Plantilla visual del login de cPanel. Se instala en /home/qlccl/icontador/auth/login.php.
// Conserva la autenticación, CSRF y sesión definidos por el proyecto del servidor.
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/auth.php';

$volver = auth_destino($_POST['volver'] ?? $_GET['volver'] ?? null);
if (auth_actual()) {
    header('Location: ' . $volver, true, 302);
    exit;
}

$error = '';
$email = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = (string) ($_POST['email'] ?? '');
    if (!auth_csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página expiró. Vuelve a intentarlo.';
    } else {
        try {
            auth_entrar(auth_verificar(auth_db(), $email, (string) ($_POST['clave'] ?? '')));
            header('Location: ' . $volver, true, 303);
            exit;
        } catch (ErrorAuth $e) {
            $error = $e->getMessage();
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; font-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$aviso = '';
if (isset($_GET['registrado'])) {
    $aviso = '<div class="login-flash login-info" role="status">Tu cuenta fue creada. Podrás entrar cuando el administrador la apruebe.</div>';
} elseif (isset($_GET['salio'])) {
    $aviso = '<div class="login-flash login-info" role="status">Cerraste sesión.</div>';
}
$errorHtml = $error !== ''
    ? '<div id="resultadoLogin" class="alert login-error" role="alert">' . auth_h($error) . '</div>'
    : '<div id="resultadoLogin" class="alert login-error" role="status" aria-live="polite" hidden></div>';

echo '<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Iniciar sesión · Cifrax</title>
  <link rel="stylesheet" href="/assets/21263fddaaf430bd-original.css">
  <link rel="stylesheet" href="/assets/acceso.css">
<link rel="icon" type="image/png" href="/assets/cifrax-favicon.png"><link rel="apple-touch-icon" href="/assets/cifrax-apple-touch-icon.png"></head>
<body class="acceso-local">
  <header class="acceso-nav">
    <a class="acceso-logo" href="/" aria-label="Cifrax, inicio">
      <img src="/assets/cifrax-logo-blanco.png" alt="Cifrax">
    </a>
    <nav aria-label="Navegación">
      <a href="/">INICIO</a>
      <a href="/auth/registro.php">REGÍSTRATE</a>
    </nav>
    <div class="acceso-contacto">CONTABILIDAD Y REMUNERACIONES EN LÍNEA</div>
  </header>

  <main class="icontador-split-container">
    <section class="panel-hero" aria-labelledby="hero-title">
      <div class="hero-content">
        <h1 class="hero-main-title" id="hero-title">La Plataforma Contable y de Remuneraciones 100% Online</h1>
        <div class="hero-highlight-badge"><a class="hero-badge-inner" href="/auth/registro.php">Regístrate ahora y obtén 15 días gratis</a></div>
        <p class="hero-subtitle-text">Diseñada para contadores, pymes y asesores tributarios en Chile. Organiza tu contabilidad, administra remuneraciones y trabaja con múltiples empresas desde un solo lugar.</p>
        <div class="hero-features-list" aria-label="Características">
          <div class="feature-item-pill"><i class="fa fa-check-circle" aria-hidden="true"></i><span>Gestión contable</span></div>
          <div class="feature-item-pill"><i class="fa fa-check-circle" aria-hidden="true"></i><span>Remuneraciones</span></div>
          <div class="feature-item-pill"><i class="fa fa-check-circle" aria-hidden="true"></i><span>Multiempresa</span></div>
          <div class="feature-item-pill"><i class="fa fa-check-circle" aria-hidden="true"></i><span>Acceso desde la nube</span></div>
        </div>
      </div>
    </section>

    <section class="panel-login" aria-labelledby="login-heading">
      <div class="login-wrapper">
        <div class="login-brand">
          <a class="login-brand-link" href="/" aria-label="Cifrax, inicio">
            <img src="/assets/cifrax-isotipo.png" class="img-responsive login-brand-isotipo" alt="">
          </a>
          <div class="login-brand-name">Cifrax</div>
          <div class="login-brand-slogan">PLATAFORMA CONTABLE</div>
        </div>

        <div class="login-divider"></div>
        <h2 class="login-subtitle" id="login-heading">Ingresa con tus datos de acceso</h2>
        ' . $aviso . '

        <form id="login_form" method="post" action="/auth/login.php" autocomplete="on">
          <input type="hidden" name="csrf" value="' . auth_h(auth_csrf()) . '">
          <input type="hidden" name="volver" value="' . auth_h($volver) . '">
          <label class="sr-only" for="email">Correo electrónico</label>
          <div class="input-group login-input-group">
            <span class="input-group-addon fondo-azul" aria-hidden="true"><i class="color-letra-blanca fa fa-user fa-fw"></i></span>
            <input type="email" placeholder="CORREO ELECTRÓNICO" tabindex="1" class="form-control letra-14px input-sm chat-input padding-20px" id="email" name="email" autocomplete="username" required autofocus value="' . auth_h($email) . '">
          </div>

          <div class="check_cambio login-password-row">
            <label class="sr-only" for="clave">Clave</label>
            <div class="input-group login-input-group">
              <span class="input-group-addon fondo-azul" aria-hidden="true"><i class="color-letra-blanca fa fa-key fa-fw"></i></span>
              <input type="password" placeholder="CLAVE" tabindex="2" class="form-control letra-14px input-sm chat-input padding-20px" id="clave" name="clave" autocomplete="current-password" required>
              <span class="input-group-addon fondo-azul color-letra-blanca login-show-password">
                <input class="puntero" id="showPassw" type="checkbox" aria-label="Mostrar clave">
                <label class="puntero" for="showPassw">Ver</label>
              </span>
            </div>
          </div>

          <div class="login-actions">
            <a id="rcvrpass" class="link-recuperar" href="#recuperar"><i class="fa fa-unlock-alt" aria-hidden="true"></i> Recuperar Clave</a>
          </div>
          ' . $errorHtml . '
          <button class="btn-login-main" type="submit" tabindex="3" id="vU">Ingresar</button>
        </form>

        <div class="login-brand-footer">
          <div class="login-benefits">
            <div class="login-benefit"><div class="login-benefit-icon"><i class="fa fa-cloud" aria-hidden="true"></i></div><div class="login-benefit-title">100% ONLINE</div></div>
            <div class="login-benefit"><div class="login-benefit-icon"><i class="fa fa-shield" aria-hidden="true"></i></div><div class="login-benefit-title">SEGURO</div></div>
            <div class="login-benefit"><div class="login-benefit-icon"><i class="fa fa-building" aria-hidden="true"></i></div><div class="login-benefit-title">MULTIEMPRESA</div></div>
          </div>
          <div class="footer-text">HECHO EN CHILE<br><span>PARA TU EMPRESA</span></div>
          <div class="footer-chile-line" aria-hidden="true"><span></span><span></span></div>
        </div>
      </div>
    </section>
  </main>
  <script src="/assets/acceso-cpanel.js" defer></script>
</body>
</html>';
