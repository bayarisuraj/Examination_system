<?php
session_start();
date_default_timezone_set('Africa/Accra');

// Auth check — lecturer only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../../auth/login.php");
    exit();
}

if (!(int)($_SESSION['user_id'] ?? 0)) {
    session_destroy();
    header("Location: ../../auth/login.php");
    exit();
}

$lecturer_name = htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'Lecturer');

// Reliable LAN IP detection for Windows/XAMPP
// function getServerIP() {
//     $hostname = gethostname();
//     if ($hostname) {
//         $ip = gethostbyname($hostname);
//         if ($ip && $ip !== $hostname && $ip !== '127.0.0.1') return $ip;
//     }
//     if (!empty($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '127.0.0.1') {
//         return $_SERVER['SERVER_ADDR'];
//     }
//     $host = $_SERVER['HTTP_HOST'] ?? '';
//     $ip   = explode(':', $host)[0];
//     if ($ip && $ip !== 'localhost' && $ip !== '127.0.0.1') return $ip;
//     try {
//         $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
//         socket_connect($sock, '8.8.8.8', 80);
//         socket_getsockname($sock, $ip);
//         socket_close($sock);
//         if ($ip && $ip !== '127.0.0.1') return $ip;
//     } catch (Exception $e) {}
//     return '127.0.0.1';
// }
function getServerIP() {
    foreach (gethostbynamel(gethostname()) as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP) && strpos($ip, '127.') !== 0) {
            return $ip;
        }
    }
    return $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
}

$server_ip   = getServerIP();
$server_port = $_SERVER['SERVER_PORT'] ?? '80';

// ✅ Path only — NOT a full URL
// $login_path  = 'http://192.168.100.8/Exam_online/auth/login.php';

$base_path = dirname($_SERVER['SCRIPT_NAME'], 2);
$login_path = '/Exam_online/auth/login.php';?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Student QR Access | OES</title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
   <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg: #0a0f1e;
      --surface: #111827;
      --border: #1e2d45;
      --accent: #00c6ff;
      --accent2: #7c3aed;
      --text: #e8eaf0;
      --muted: #6b7a99;
      --success: #10b981;
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'DM Sans', sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-start;
      padding: 0;
      position: relative;
      overflow-x: hidden;
    }

    body::before {
      content: '';
      position: fixed;
      top: -40%; left: -20%;
      width: 80vw; height: 80vw;
      background: radial-gradient(ellipse, rgba(0,198,255,0.07) 0%, transparent 70%);
      pointer-events: none; z-index: 0;
    }

    body::after {
      content: '';
      position: fixed;
      bottom: -40%; right: -20%;
      width: 70vw; height: 70vw;
      background: radial-gradient(ellipse, rgba(124,58,237,0.08) 0%, transparent 70%);
      pointer-events: none; z-index: 0;
    }

    /* ===== HEADER ===== */
    .site-header {
      width: 100%;
      background: linear-gradient(135deg, #0d1b2a, #1a3c6e);
      border-bottom: 1px solid rgba(0,198,255,0.15);
      padding: 14px 28px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: relative;
      z-index: 20;
      box-shadow: 0 2px 20px rgba(0,0,0,0.4);
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .header-logo {
      width: 40px;
      height: 40px;
      background: linear-gradient(135deg, #00c6ff, #7c3aed);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .header-logo svg { width: 22px; height: 22px; fill: #fff; }

    .header-brand {
      display: flex;
      flex-direction: column;
    }

    .header-brand .name {
      font-family: 'Syne', sans-serif;
      font-size: 0.95rem;
      font-weight: 800;
      color: #fff;
      line-height: 1.2;
    }

    .header-brand .sub {
      font-size: 0.7rem;
      color: rgba(255,255,255,0.5);
      letter-spacing: 0.05em;
    }

    .header-right {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .header-user {
      display: flex;
      align-items: center;
      gap: 8px;
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 10px;
      padding: 7px 14px;
      font-size: 0.82rem;
      color: rgba(255,255,255,0.8);
    }

    .header-user .avatar {
      width: 28px;
      height: 28px;
      background: linear-gradient(135deg, #00c6ff, #7c3aed);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.75rem;
      font-weight: 700;
      color: #fff;
    }

    .header-nav a {
      color: rgba(255,255,255,0.6);
      text-decoration: none;
      font-size: 0.82rem;
      padding: 7px 14px;
      border-radius: 8px;
      border: 1px solid rgba(255,255,255,0.1);
      background: rgba(255,255,255,0.04);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }

    .header-nav a:hover {
      color: var(--accent);
      border-color: var(--accent);
      background: rgba(0,198,255,0.08);
    }

    /* ===== TOPBAR ===== */
    .topbar {
      width: 100%;
      background: rgba(17,24,39,0.95);
      border-bottom: 1px solid var(--border);
      padding: 12px 28px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: relative;
      z-index: 10;
      backdrop-filter: blur(10px);
    }

    .topbar-left { display: flex; align-items: center; gap: 8px; }

    .breadcrumb {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.8rem;
      color: var(--muted);
    }

    .breadcrumb a {
      color: var(--muted);
      text-decoration: none;
      transition: color 0.2s;
    }

    .breadcrumb a:hover { color: var(--accent); }
    .breadcrumb .sep { opacity: 0.4; }
    .breadcrumb .current { color: var(--text); font-weight: 500; }

    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--muted);
      text-decoration: none;
      font-size: 0.82rem;
      font-weight: 500;
      padding: 6px 12px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.03);
      transition: all 0.2s;
    }

    .back-btn:hover {
      color: var(--accent);
      border-color: var(--accent);
      background: rgba(0,198,255,0.06);
    }

    /* ===== MAIN ===== */
    .main {
      position: relative;
      z-index: 1;
      width: 100%;
      display: flex;
      justify-content: center;
      padding: 2.5rem 1rem;
      flex: 1;
    }

    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 24px;
      padding: 2.5rem 2rem;
      width: 100%;
      max-width: 480px;
      box-shadow: 0 0 60px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.03) inset;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(0,198,255,0.08);
      border: 1px solid rgba(0,198,255,0.2);
      color: var(--accent);
      font-size: 0.72rem;
      font-weight: 500;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      padding: 5px 12px;
      border-radius: 100px;
      margin-bottom: 1.2rem;
    }

    .badge span {
      width: 6px; height: 6px;
      border-radius: 50%;
      background: var(--accent);
      animation: pulse 1.8s infinite;
      display: block;
    }

    @keyframes pulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.4; transform: scale(0.8); }
    }

    h1 {
      font-family: 'Syne', sans-serif;
      font-size: 2rem;
      font-weight: 800;
      line-height: 1.1;
      margin-bottom: 0.4rem;
      background: linear-gradient(135deg, #fff 30%, var(--accent));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .subtitle { color: var(--muted); font-size: 0.9rem; margin-bottom: 2rem; }

    .field-group { margin-bottom: 1.2rem; }

    label {
      display: block;
      font-size: 0.8rem;
      font-weight: 500;
      color: var(--muted);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      margin-bottom: 8px;
    }

    .input-row { display: flex; gap: 8px; }

    .prefix {
      background: rgba(0,198,255,0.06);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 0 14px;
      font-size: 0.85rem;
      color: var(--accent);
      display: flex;
      align-items: center;
      white-space: nowrap;
      font-weight: 500;
    }

    input[type="text"], input[type="number"], select {
      width: 100%;
      background: rgba(255,255,255,0.03);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 12px 14px;
      color: var(--text);
      font-family: 'DM Sans', sans-serif;
      font-size: 0.95rem;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    input[type="text"]:focus, input[type="number"]:focus, select:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(0,198,255,0.1);
    }

    select { cursor: pointer; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

    .ip-warning {
      background: rgba(251,191,36,0.08);
      border: 1px solid rgba(251,191,36,0.25);
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 0.8rem;
      color: #fcd34d;
      margin-top: 8px;
      display: none;
    }

    .preview-box {
      margin: 1.8rem 0 1.2rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 1rem;
    }

    .qr-frame {
      background: #fff;
      border-radius: 20px;
      padding: 18px;
      box-shadow: 0 0 40px rgba(0,198,255,0.15), 0 8px 32px rgba(0,0,0,0.4);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.3s;
      position: relative;
    }

    .qr-frame:hover { transform: scale(1.02); }

    .qr-frame .corner {
      position: absolute;
      width: 18px; height: 18px;
      border-color: var(--accent);
      border-style: solid;
    }

    .qr-frame .corner.tl { top: -2px; left: -2px; border-width: 3px 0 0 3px; border-radius: 4px 0 0 0; }
    .qr-frame .corner.tr { top: -2px; right: -2px; border-width: 3px 3px 0 0; border-radius: 0 4px 0 0; }
    .qr-frame .corner.bl { bottom: -2px; left: -2px; border-width: 0 0 3px 3px; border-radius: 0 0 0 4px; }
    .qr-frame .corner.br { bottom: -2px; right: -2px; border-width: 0 3px 3px 0; border-radius: 0 0 4px 0; }

    #qrcode canvas, #qrcode img { display: block; }

    .url-display {
      font-size: 0.78rem;
      color: var(--accent);
      background: rgba(0,198,255,0.06);
      border: 1px solid rgba(0,198,255,0.15);
      border-radius: 8px;
      padding: 8px 14px;
      word-break: break-all;
      text-align: center;
      width: 100%;
      max-width: 340px;
      letter-spacing: 0.02em;
    }

    .empty-state {
      width: 220px; height: 220px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 10px;
      color: var(--muted);
      font-size: 0.85rem;
    }

    .empty-state svg { opacity: 0.3; }

    .btn-row { display: flex; gap: 10px; }

    button {
      flex: 1;
      padding: 13px;
      border: none;
      border-radius: 12px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.92rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s;
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }

    .btn-generate {
      background: linear-gradient(135deg, var(--accent), var(--accent2));
      color: #fff;
      box-shadow: 0 4px 20px rgba(0,198,255,0.25);
    }

    .btn-generate:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(0,198,255,0.35); }
    .btn-generate:active { transform: translateY(0); }

    .btn-download {
      background: rgba(255,255,255,0.05);
      color: var(--text);
      border: 1px solid var(--border);
    }

    .btn-download:hover { background: rgba(255,255,255,0.09); border-color: var(--accent); color: var(--accent); }
    .btn-download:disabled { opacity: 0.35; cursor: not-allowed; transform: none; }

    .hint {
      margin-top: 1.4rem;
      background: rgba(16,185,129,0.06);
      border: 1px solid rgba(16,185,129,0.18);
      border-radius: 12px;
      padding: 12px 16px;
      font-size: 0.82rem;
      color: #6ee7b7;
      line-height: 1.6;
    }

    .hint strong { color: var(--success); }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .animate-in { animation: fadeIn 0.4s ease forwards; }

    /* ===== FOOTER ===== */
    .site-footer {
      width: 100%;
      background: #0d1117;
      border-top: 1px solid var(--border);
      padding: 24px 28px;
      position: relative;
      z-index: 10;
    }

    .footer-inner {
      max-width: 1100px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 16px;
    }

    .footer-brand {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .footer-brand .logo {
      width: 32px;
      height: 32px;
      background: linear-gradient(135deg, #00c6ff, #7c3aed);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .footer-brand .logo svg { width: 16px; height: 16px; fill: #fff; }

    .footer-brand .text .name {
      font-family: 'Syne', sans-serif;
      font-size: 0.85rem;
      font-weight: 700;
      color: var(--text);
    }

    .footer-brand .text .tagline {
      font-size: 0.7rem;
      color: var(--muted);
    }

    .footer-links {
      display: flex;
      align-items: center;
      gap: 20px;
    }

    .footer-links a {
      color: var(--muted);
      text-decoration: none;
      font-size: 0.78rem;
      transition: color 0.2s;
    }

    .footer-links a:hover { color: var(--accent); }

    .footer-copy {
      font-size: 0.72rem;
      color: var(--muted);
      opacity: 0.6;
    }

    @media (max-width: 600px) {
      .footer-inner { flex-direction: column; align-items: flex-start; }
      .header-right { gap: 8px; }
      .header-user span { display: none; }
    }

    @media print {
      .site-header, .topbar, .btn-row, .field-group,
      .row, .hint, .ip-warning, .site-footer { display: none !important; }
      body { background: #fff; }
      .card { box-shadow: none; border: none; background: #fff; }
      .qr-frame { box-shadow: none; }
      h1, .subtitle { -webkit-text-fill-color: #000; color: #000; }
      .url-display { color: #000; background: #f5f5f5; border-color: #ccc; }
    }
  </style>
</head>


<body>

  <!-- ===== SITE HEADER ===== -->
  <header class="site-header">
    <div class="header-left">
      <div class="header-logo">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3zM5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82z"/>
        </svg>
      </div>
      <div class="header-brand">
        <span class="name">USTED Online Exam System</span>
        <span class="sub">University of skills, training &amp; enterpreneural Development</span>
      </div>
    </div>
    <div class="header-right">
      <div class="header-user">
        <div class="avatar"><?= strtoupper(substr($_SESSION['name'] ?? 'L', 0, 1)) ?></div>
        <span><?= $lecturer_name ?></span>
      </div>
      <nav class="header-nav">
        <a href="../dashboard.php">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
          Dashboard
        </a>
      </nav>
    </div>
  </header>

  <!-- ===== BREADCRUMB TOPBAR ===== -->
  <!-- <div class="topbar">
    <div class="topbar-left">
      <a href="../dashboard.php" class="back-btn">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </a>
      <div class="breadcrumb">
        <a href="../dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">Share Student Access</span>
      </div>
    </div>
  </div> -->

  <!-- ===== MAIN CONTENT ===== -->
  <div class="main">
    <div class="card">
      <div class="badge"><span></span> USTED Online Exam System</div>
      <h1>Student QR Login</h1>
      <p class="subtitle">Generate a scannable QR code for students to access the exam portal on their phones.</p>

      <div class="field-group">
        <label>Server IP Address</label>
        <div class="input-row">
          <div class="prefix">http://</div>
          <input type="text" id="ipInput" placeholder="e.g. 192.168.1.105" value="<?= htmlspecialchars($server_ip) ?>" oninput="checkIP()" />
        </div>
        <div class="ip-warning" id="ipWarning">
          ⚠️ IP looks like a local loopback. Students on other devices won't be able to connect. Run <strong>ipconfig</strong> in CMD and enter your Wi-Fi IPv4 address manually.
        </div>
      </div>

      <div class="row">
        <div class="field-group">
          <label>Port</label>
          <input type="number" id="portInput" placeholder="80" value="<?= htmlspecialchars($server_port) ?>" />
        </div>
        <div class="field-group">
          <label>Login Page Path</label>
          <input type="text" id="pathInput" value="<?= htmlspecialchars($login_path) ?>" />
        </div>
      </div>

      <div class="row">
        <div class="field-group">
          <label>QR Size (px)</label>
          <select id="sizeSelect">
            <option value="180">180 — Small</option>
            <option value="220" selected>220 — Medium</option>
            <option value="280">280 — Large</option>
            <option value="340">340 — XL</option>
          </select>
        </div>
        <div class="field-group">
          <label>Error Correction</label>
          <select id="ecSelect">
            <option value="M" selected>M — Medium</option>
            <option value="H">H — High</option>
            <option value="L">L — Low</option>
          </select>
        </div>
      </div>

      <div class="preview-box">
        <div class="qr-frame" id="qrFrame">
          <div class="corner tl"></div>
          <div class="corner tr"></div>
          <div class="corner bl"></div>
          <div class="corner br"></div>
          <div id="qrcode">
            <div class="empty-state">
              <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/>
                <rect x="14" y="14" width="3" height="3" rx="0.5"/>
                <rect x="18" y="14" width="3" height="3" rx="0.5"/>
                <rect x="14" y="18" width="3" height="3" rx="0.5"/>
                <rect x="18" y="18" width="3" height="3" rx="0.5"/>
              </svg>
              <span>Click Generate</span>
            </div>
          </div>
        </div>
        <div class="url-display" id="urlDisplay">Click Generate to preview the URL</div>
      </div>

      <div class="btn-row">
        <button class="btn-generate" onclick="generateQR()">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="5 12 12 5 19 12"/><line x1="12" y1="5" x2="12" y2="19"/></svg>
          Generate QR
        </button>
        <button class="btn-download" id="downloadBtn" onclick="downloadQR()" disabled>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Save PNG
        </button>
      </div>

      <div class="hint">
        <strong>📋 How to use:</strong><br>
        1. Make sure students' phones are on the <strong>same Wi-Fi network</strong> as this PC.<br>
        2. If the IP above is wrong, open CMD → run <strong>ipconfig</strong> → copy your Wi-Fi <strong>IPv4 Address</strong> and paste it above.<br>
        3. Click <strong>Generate QR</strong>, then show or project the code for students to scan.
      </div>
    </div>
  </div>

  <!-- ===== SITE FOOTER ===== -->
  <footer class="site-footer">
    <div class="footer-inner">
      <div class="footer-brand">
        <div class="logo">
          <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/>
          </svg>
        </div>
        <div class="text">
          <div class="name">Hands &amp; Brains</div>
          <div class="tagline">Powering USTED Online Exam System</div>
        </div>
      </div>
      <div class="footer-links">
        <a href="../dashboard.php">Dashboard</a>
        <a href="../exams/manage_exams.php">Exams</a>
        <a href="../courses/manage_courses.php">Courses</a>
        <a href="../questions/question_bank.php">Questions</a>
      </div>
      <div class="footer-copy">
        &copy; <?= date('Y') ?> USTED OES &bull; <?= date('d M Y') ?>
      </div>
    </div>
  </footer>

  <script>
    let qrInstance = null;

    function checkIP() {
      const ip = document.getElementById('ipInput').value.trim();
      const warning = document.getElementById('ipWarning');
      warning.style.display = (ip === '127.0.0.1' || ip === 'localhost' || ip === '') ? 'block' : 'none';
    }

    // function buildURL() {
    //   const ip   = document.getElementById('ipInput').value.trim();
    //   const port = document.getElementById('portInput').value.trim();
    //   const path = document.getElementById('pathInput').value.trim() || '/';
    //   const p    = (port === '80' || port === '') ? '' : `:${port}`;
    //   return `http://${ip}${p}${path}`;
    // }

    function buildURL() {
  const ip   = document.getElementById('ipInput').value.trim();
  const port = document.getElementById('portInput').value.trim();
  const path = document.getElementById('pathInput').value.trim() || '/';

  const p = (port === '80' || port === '') ? '' : `:${port}`;

  return `http://${ip}${p}${path}`;
}

    function generateQR() {
      checkIP();
      const url  = buildURL();
      const size = parseInt(document.getElementById('sizeSelect').value);
      const ec   = document.getElementById('ecSelect').value;

      document.getElementById('urlDisplay').textContent = url;

      const container = document.getElementById('qrcode');
      container.innerHTML = '';

      qrInstance = new QRCode(container, {
        text: url,
        width: size,
        height: size,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel[ec]
      });

      document.getElementById('downloadBtn').disabled = false;

      container.classList.remove('animate-in');
      void container.offsetWidth;
      container.classList.add('animate-in');
    }

    function downloadQR() {
      const container = document.getElementById('qrcode');
      const canvas    = container.querySelector('canvas');
      const img       = container.querySelector('img');

      if (canvas) {
        const link = document.createElement('a');
        link.download = 'oes-student-login-qr.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
      } else if (img) {
        const c = document.createElement('canvas');
        c.width = img.width; c.height = img.height;
        c.getContext('2d').drawImage(img, 0, 0);
        const link = document.createElement('a');
        link.download = 'oes-student-login-qr.png';
        link.href = c.toDataURL('image/png');
        link.click();
      }
    }

    window.addEventListener('load', () => {
      checkIP();
      setTimeout(generateQR, 300);
    });
  </script>

</body>
</html>