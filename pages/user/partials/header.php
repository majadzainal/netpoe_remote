<?php
/**
 * Shared Header Partial — NetPoe Remote
 *
 * Variables expected from including page:
 *   $pageTitle  (string) — <title> tag content, e.g. "Dashboard"
 *   $activePage (string) — active nav key: 'dashboard' | 'pppoe' | 'olt_monitor' | 'olt_setting' | 'router'
 */

if (!isset($pageTitle))  $pageTitle  = 'NetPoe Remote';
if (!isset($activePage)) $activePage = '';

$currentUser = htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8');

$navItems = [
    ['key' => 'dashboard',    'label' => '🏠 Dashboard',    'href' => 'dashboard.php'],
    ['key' => 'pppoe',        'label' => '📡 PPPoE',         'href' => 'pppoe.php'],
    ['key' => 'olt_monitor',  'label' => '📊 Monitoring OLT','href' => 'olt_monitor.php'],
    ['key' => 'olt_setting',  'label' => '⚙️ Pengaturan OLT','href' => 'olt_setting.php'],
    ['key' => 'router',       'label' => '🔧 Router',        'href' => 'router_setting.php'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — NetPoe Remote</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ===== RESET & BASE ===== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --clr-bg:       #0f1117;
            --clr-surface:  #1a1d2e;
            --clr-surface2: #212438;
            --clr-border:   rgba(255,255,255,0.08);
            --clr-text:     #e2e8f0;
            --clr-muted:    #8892a4;
            --clr-accent:   #6366f1;
            --clr-accent2:  #8b5cf6;
            --nav-h:        64px;
            --radius:       14px;
            --shadow:       0 4px 24px rgba(0,0,0,0.4);
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', Arial, sans-serif;
            background: var(--clr-bg);
            color: var(--clr-text);
            min-height: 100vh;
        }

        /* ===== TOPBAR / NAV ===== */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            height: var(--nav-h);
            background: rgba(26,29,46,0.92);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--clr-border);
            display: flex;
            align-items: center;
            gap: 0;
        }

        .topbar-inner {
            width: min(100% - 32px, 1200px);
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            flex-shrink: 0;
        }

        .brand-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--clr-accent), var(--clr-accent2));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            box-shadow: 0 2px 12px rgba(99,102,241,0.4);
        }

        .brand-name {
            font-size: 16px;
            font-weight: 800;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.3px;
        }

        /* Desktop nav */
        .nav-links {
            display: flex;
            align-items: center;
            gap: 2px;
            list-style: none;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 7px 13px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--clr-muted);
            text-decoration: none;
            white-space: nowrap;
            transition: color .18s, background .18s;
        }

        .nav-links a:hover {
            color: var(--clr-text);
            background: rgba(255,255,255,0.07);
        }

        .nav-links a.active {
            color: #a5b4fc;
            background: rgba(99,102,241,0.18);
            font-weight: 600;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 20px;
            background: var(--clr-surface2);
            border: 1px solid var(--clr-border);
            font-size: 13px;
            font-weight: 500;
            color: var(--clr-text);
        }

        .user-chip .avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--clr-accent), var(--clr-accent2));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: #fff;
        }

        .btn-logout {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 14px;
            border-radius: 8px;
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.25);
            color: #f87171;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: background .18s, border-color .18s;
        }

        .btn-logout:hover {
            background: rgba(239,68,68,0.22);
            border-color: rgba(239,68,68,0.4);
        }

        /* ===== HAMBURGER MOBILE ===== */
        .hamburger {
            display: none;
            flex-direction: column;
            gap: 5px;
            cursor: pointer;
            padding: 8px;
            border: none;
            background: none;
        }

        .hamburger span {
            display: block;
            width: 22px;
            height: 2px;
            background: var(--clr-text);
            border-radius: 2px;
            transition: transform .25s, opacity .25s;
        }

        .hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .hamburger.open span:nth-child(2) { opacity: 0; }
        .hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        /* Mobile drawer */
        .mobile-nav {
            display: none;
            flex-direction: column;
            background: var(--clr-surface);
            border-bottom: 1px solid var(--clr-border);
            padding: 10px 16px 16px;
            gap: 4px;
        }

        .mobile-nav.open { display: flex; }

        .mobile-nav a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            color: var(--clr-muted);
            text-decoration: none;
            transition: color .15s, background .15s;
        }

        .mobile-nav a:hover,
        .mobile-nav a.active {
            color: #a5b4fc;
            background: rgba(99,102,241,0.15);
        }

        /* ===== PAGE WRAPPER ===== */
        .page-wrap {
            width: min(100% - 32px, 1200px);
            margin: 0 auto;
            padding: 32px 0 60px;
        }

        /* ===== SHARED UTILITIES ===== */
        .page-heading {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
            background: linear-gradient(135deg, #e2e8f0, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-sub {
            font-size: 14px;
            color: var(--clr-muted);
            margin-bottom: 28px;
        }

        .card {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .card h2 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 18px;
            color: var(--clr-text);
        }

        /* Forms */
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 13px;
            color: var(--clr-muted);
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        input, select, textarea {
            width: 100%;
            padding: 10px 14px;
            margin-bottom: 16px;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            font-size: 14px;
            background: rgba(255,255,255,0.05);
            color: var(--clr-text);
            transition: border-color .18s, box-shadow .18s;
            font-family: inherit;
        }

        textarea { min-height: 90px; resize: vertical; }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--clr-accent);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.2);
        }

        input[readonly], textarea[readonly] {
            background: rgba(255,255,255,0.03);
            color: var(--clr-muted);
        }

        input::placeholder { color: rgba(136,146,164,0.6); }

        select option { background: #1a1d2e; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: transform .15s, box-shadow .15s, opacity .15s;
            font-family: inherit;
        }

        .btn:hover { transform: translateY(-1px); opacity: .92; }
        .btn:active { transform: translateY(0); }

        .btn-primary {
            background: linear-gradient(135deg, var(--clr-accent), var(--clr-accent2));
            color: #fff;
            box-shadow: 0 4px 14px rgba(99,102,241,0.35);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.08);
            color: var(--clr-text);
            border: 1px solid var(--clr-border);
        }

        .btn-danger {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: rgba(34,197,94,0.12);
            border: 1px solid rgba(34,197,94,0.3);
            color: #4ade80;
        }

        .alert-error {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.3);
            color: #f87171;
        }

        /* Table */
        table { width: 100%; border-collapse: collapse; }

        th, td {
            padding: 11px 13px;
            text-align: left;
            font-size: 13.5px;
            border-bottom: 1px solid var(--clr-border);
        }

        th {
            background: rgba(255,255,255,0.04);
            color: var(--clr-muted);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,0.03); }

        pre {
            overflow: auto;
            max-height: 300px;
            padding: 16px;
            border-radius: 8px;
            background: #0d0f1a;
            color: #a5b4fc;
            font-size: 12px;
            border: 1px solid var(--clr-border);
            line-height: 1.6;
        }

        code {
            font-size: 12px;
            background: rgba(255,255,255,0.08);
            padding: 2px 7px;
            border-radius: 4px;
            color: #c4b5fd;
            font-family: 'Courier New', monospace;
        }

        .hint {
            font-size: 12px;
            color: var(--clr-muted);
            margin-top: -10px;
            margin-bottom: 16px;
            line-height: 1.5;
        }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }

        .actions { display: flex; gap: 10px; flex-wrap: wrap; }

        @media (max-width: 768px) {
            .nav-links, .user-chip { display: none; }
            .hamburger { display: flex; }
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<nav class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="dashboard.php">
            <div class="brand-icon">⚡</div>
            <span class="brand-name">NetPoe Remote</span>
        </a>

        <ul class="nav-links">
            <?php foreach ($navItems as $item): ?>
            <li>
                <a href="<?= $item['href'] ?>" class="<?= $activePage === $item['key'] ? 'active' : '' ?>">
                    <?= $item['label'] ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="nav-right">
            <div class="user-chip">
                <div class="avatar"><?= strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)) ?></div>
                <?= $currentUser ?>
            </div>
            <a class="btn-logout" href="../logout.php">⏻ Logout</a>
            <button class="hamburger" id="hamburger" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</nav>

<div class="mobile-nav" id="mobileNav">
    <?php foreach ($navItems as $item): ?>
    <a href="<?= $item['href'] ?>" class="<?= $activePage === $item['key'] ? 'active' : '' ?>">
        <?= $item['label'] ?>
    </a>
    <?php endforeach; ?>
    <a href="../logout.php" style="color:#f87171">⏻ Logout</a>
</div>

<script>
    const hamburger = document.getElementById('hamburger');
    const mobileNav = document.getElementById('mobileNav');
    hamburger.addEventListener('click', () => {
        hamburger.classList.toggle('open');
        mobileNav.classList.toggle('open');
    });
</script>
