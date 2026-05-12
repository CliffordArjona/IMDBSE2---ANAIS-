<?php
require_once __DIR__ . '/includes/auth.php';
$loggedIn = isLoggedIn();
$role = $_SESSION['role'] ?? '';
$homeLink = $loggedIn ? ($role === 'Supplier' ? '/anais/supplier_portal.php' : '/anais/dashboard.php') : '/anais/login.php?type=staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANAIS – Autobox & Autophoria Inventory System</title>
<link rel="stylesheet" href="/anais/assets/css/style.css">
<link rel="stylesheet" href="/anais/assets/css/dark-mode.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
  :root {
    --gold: #f5c542;
    --gold-dark: #b88718;
    --glass: rgba(8, 10, 14, .72);
    --glass-strong: rgba(5, 7, 10, .86);
  }

  * { box-sizing: border-box; }

  body {
    min-height: 100vh;
    margin: 0;
    font-family: 'Inter', sans-serif;
    color: #ffffff;
    background: #050505;
  }

  .landing-page {
    min-height: 100vh;
    position: relative;
    overflow: hidden;
    background-image:
      linear-gradient(90deg, rgba(0,0,0,.92) 0%, rgba(0,0,0,.70) 38%, rgba(0,0,0,.40) 72%, rgba(0,0,0,.74) 100%),
      linear-gradient(180deg, rgba(0,0,0,.52), rgba(0,0,0,.82)),
      url('/anais/assets/img/landing-bg.jpg');
    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
  }

  .landing-page::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
      radial-gradient(circle at 78% 22%, rgba(245,197,66,.24), transparent 32%),
      radial-gradient(circle at 14% 12%, rgba(255,255,255,.08), transparent 28%),
      radial-gradient(circle at 50% 100%, rgba(245,197,66,.10), transparent 34%);
    pointer-events: none;
  }

  .landing-page::after {
    content: "";
    position: absolute;
    inset: 0;
    background-image:
      linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
      linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size: 54px 54px;
    mask-image: linear-gradient(90deg, rgba(0,0,0,.55), transparent 65%);
    pointer-events: none;
  }

  .landing-wrap {
    position: relative;
    z-index: 1;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 42px 20px;
  }

  .landing-shell {
    width: min(1180px, 100%);
    display: grid;
    grid-template-columns: minmax(0, 1.08fr) minmax(340px, .78fr);
    gap: 26px;
    align-items: center;
  }

  .hero-panel {
    padding: 44px;
    border-radius: 28px;
    background: linear-gradient(135deg, rgba(0,0,0,.68), rgba(0,0,0,.34));
    border: 1px solid rgba(255,255,255,.16);
    box-shadow: 0 30px 90px rgba(0,0,0,.45);
    backdrop-filter: blur(5px);
  }

  .brand-row {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 34px;
  }

  .brand-row img {
    width: 70px;
    height: 70px;
    object-fit: contain;
    border-radius: 20px;
    background: rgba(255,255,255,.88);
    padding: 8px;
    box-shadow: 0 12px 30px rgba(0,0,0,.28);
  }

  .brand-kicker {
    color: var(--gold);
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .24em;
    margin-bottom: 4px;
  }

  .brand-title {
    font-size: 34px;
    font-weight: 900;
    line-height: 1;
    letter-spacing: -.04em;
  }

  .brand-subtitle {
    color: rgba(255,255,255,.76);
    font-size: 13px;
    margin-top: 7px;
  }

  .hero-panel h1 {
    font-size: clamp(36px, 5.2vw, 66px);
    line-height: .98;
    letter-spacing: -2.4px;
    margin: 0 0 18px;
    max-width: 760px;
    text-shadow: 0 8px 28px rgba(0,0,0,.45);
  }

  .hero-panel h1 span { color: var(--gold); }

  .hero-panel p {
    max-width: 660px;
    color: rgba(255,255,255,.82);
    font-size: 16px;
    line-height: 1.75;
    margin: 0 0 30px;
  }

  .feature-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    max-width: 650px;
  }

  .feature-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 15px;
    border-radius: 17px;
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.12);
    color: rgba(255,255,255,.94);
    font-size: 13px;
    font-weight: 700;
  }

  .feature-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--gold);
    box-shadow: 0 0 0 5px rgba(245,197,66,.13);
    flex: 0 0 auto;
  }

  .action-panel {
    border-radius: 28px;
    padding: 28px;
    background: linear-gradient(180deg, rgba(6,8,12,.88), rgba(0,0,0,.78));
    color: #ffffff;
    border: 1px solid rgba(245,197,66,.28);
    box-shadow: 0 30px 90px rgba(0,0,0,.58);
    backdrop-filter: blur(8px);
  }

  .action-panel h2 {
    margin: 0 0 8px;
    font-size: 25px;
    letter-spacing: -.03em;
  }

  .muted {
    color: rgba(255,255,255,.76);
    font-size: 13px;
    line-height: 1.55;
    margin-bottom: 20px;
  }

  .login-options {
    display: grid;
    gap: 12px;
  }

  .landing-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 15px 16px;
    border-radius: 17px;
    border: 1px solid rgba(255,255,255,.18);
    color: #ffffff !important;
    text-decoration: none;
    background: rgba(255,255,255,.10);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
  }

  .landing-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 18px 35px rgba(0,0,0,.30);
    border-color: rgba(245,197,66,.58);
    background: rgba(245,197,66,.14);
  }

  .landing-btn.primary {
    background: linear-gradient(135deg, #f5c542, #b88718);
    color: #000000 !important;
    border-color: rgba(245,197,66,.88);
  }

  .landing-btn.primary .arrow {
    color: #000000;
  }

  .landing-btn.primary small { color: rgba(0,0,0,.72); }
  .landing-btn small { color: rgba(255,255,255,.72); }

  .landing-btn strong {
    display: block;
    font-size: 14px;
  }

  .landing-btn small {
    display: block;
    font-size: 12px;
    opacity: .72;
    margin-top: 2px;
  }

  .arrow {
    font-size: 22px;
    opacity: .9;
  }

  .divider {
    height: 1px;
    background: rgba(255,255,255,.16);
    margin: 22px 0;
  }

  .register-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
  }

  .mini-link {
    display: block;
    text-align: center;
    padding: 12px 10px;
    border: 1px solid rgba(245,197,66,.45);
    border-radius: 14px;
    color: #000000;
    background: linear-gradient(135deg, #fff3c4, #f5c542);
    font-weight: 800;
    font-size: 12px;
    text-decoration: none;
  }

  .mini-link:hover {
    border-color: #ffffff;
    background: #ffe08a;
  }

  .footer-note {
    margin-top: 18px;
    color: rgba(255,255,255,.62);
    font-size: 12px;
    text-align: center;
  }

  @media (max-width: 920px) {
    .landing-shell { grid-template-columns: 1fr; }
    .hero-panel { padding: 30px; }
    .action-panel { max-width: 560px; width: 100%; justify-self: center; }
  }

  @media (max-width: 600px) {
    .landing-wrap { padding: 22px 14px; }
    .hero-panel, .action-panel { border-radius: 22px; padding: 22px; }
    .brand-row img { width: 58px; height: 58px; }
    .brand-title { font-size: 26px; }
    .feature-grid, .register-row { grid-template-columns: 1fr; }
    .hero-panel h1 { letter-spacing: -1.4px; }
  }
</style>
</head>
<body>
<div class="landing-page">
  <div class="landing-wrap">
    <div class="landing-shell">
      <section class="hero-panel">
        <div class="brand-row">
          <img src="/anais/assets/img/anais-logo.png" alt="ANAIS Logo">
          <div>
            <div class="brand-kicker">Inventory System</div>
            <div class="brand-title">ANAIS</div>
            <div class="brand-subtitle">Autobox &amp; Autophoria Inventory System</div>
          </div>
        </div>

        <h1>Manage your auto shop <span>inventory</span> in one powerful system.</h1>
        <p>
          Monitor stock levels, record sales, manage suppliers, review purchase orders,
          track deliveries, handle returns, and generate branch reports for Autobox and Autophoria.
        </p>

        <div class="feature-grid">
          <div class="feature-item"><span class="feature-dot"></span>Inventory Monitoring</div>
          <div class="feature-item"><span class="feature-dot"></span>Sales Transactions</div>
          <div class="feature-item"><span class="feature-dot"></span>Supplier Deliveries</div>
          <div class="feature-item"><span class="feature-dot"></span>Reports &amp; Low-Stock Alerts</div>
        </div>
      </section>

      <aside class="action-panel">
        <h2>Welcome to ANAIS</h2>
        <div class="muted">Choose where you want to go. This landing page now uses your Autophoria-style background instead of the plain blue screen.</div>

        <div class="login-options">
          <?php if ($loggedIn): ?>
            <a class="landing-btn primary" href="<?= htmlspecialchars($homeLink) ?>">
              <span><strong>Continue to your account</strong><small>Open your current ANAIS workspace</small></span>
              <span class="arrow">→</span>
            </a>
            <a class="landing-btn" href="/anais/logout.php">
              <span><strong>Logout</strong><small>Sign out from the current session</small></span>
              <span class="arrow">↗</span>
            </a>
          <?php else: ?>
            <a class="landing-btn primary" href="/anais/login.php?type=staff">
              <span><strong>Employee / Staff Login</strong><small>Owner, OIC, and employee access</small></span>
              <span class="arrow">→</span>
            </a>
            <a class="landing-btn" href="/anais/login.php?type=supplier">
              <span><strong>Supplier Login</strong><small>View POs, pricing, returns, and redelivery</small></span>
              <span class="arrow">→</span>
            </a>
          <?php endif; ?>
        </div>

        <?php if (!$loggedIn): ?>
        <div class="divider"></div>
        <div class="muted" style="margin-bottom:10px">Need an account?</div>
        <div class="register-row">
          <a class="mini-link" href="/anais/register_account.php">Register Employee Account</a>
          <a class="mini-link" href="/anais/register_supplier.php">Register Supplier Account</a>
        </div>
        <?php endif; ?>

        <div class="footer-note">&copy; <?= date('Y') ?> ANAIS &mdash; For authorized users only</div>
      </aside>
    </div>
  </div>
</div>
</body>
</html>
