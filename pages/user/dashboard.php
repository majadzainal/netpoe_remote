<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';

checkUser();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

require_once __DIR__ . '/partials/header.php';

$username = htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$initial  = strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1));
?>
<style>
/* ===== DASHBOARD SPECIFIC ===== */
.welcome-section {
    display: flex;
    align-items: center;
    gap: 20px;
    background: linear-gradient(135deg, rgba(99,102,241,0.18), rgba(139,92,246,0.12));
    border: 1px solid rgba(99,102,241,0.25);
    border-radius: 18px;
    padding: 28px 32px;
    margin-bottom: 32px;
    position: relative;
    overflow: hidden;
}

.welcome-section::before {
    content: '';
    position: absolute;
    right: -40px;
    top: -40px;
    width: 200px;
    height: 200px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(99,102,241,0.2), transparent 70%);
    pointer-events: none;
}

.welcome-avatar {
    width: 60px;
    height: 60px;
    border-radius: 16px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    font-weight: 800;
    color: #fff;
    flex-shrink: 0;
    box-shadow: 0 6px 20px rgba(99,102,241,0.4);
}

.welcome-text h1 {
    font-size: 22px;
    font-weight: 800;
    color: #e2e8f0;
    margin-bottom: 4px;
}

.welcome-text p {
    font-size: 14px;
    color: #94a3b8;
}

.welcome-time {
    margin-left: auto;
    text-align: right;
    flex-shrink: 0;
}

.welcome-time .time {
    font-size: 28px;
    font-weight: 800;
    color: #a5b4fc;
    font-variant-numeric: tabular-nums;
    letter-spacing: -1px;
}

.welcome-time .date {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
}

/* ===== MENU CARDS ===== */
.menu-section-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: #475569;
    margin-bottom: 14px;
}

.card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.menu-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
    padding: 24px;
    border-radius: 16px;
    border: 1px solid transparent;
    text-decoration: none;
    overflow: hidden;
    transition: transform .22s, box-shadow .22s;
    cursor: pointer;
}

.menu-card::after {
    content: '→';
    position: absolute;
    right: 20px;
    bottom: 20px;
    font-size: 18px;
    opacity: 0;
    transform: translateX(-6px);
    transition: opacity .2s, transform .2s;
    color: rgba(255,255,255,0.6);
}

.menu-card:hover { transform: translateY(-4px); }
.menu-card:hover::after { opacity: 1; transform: translateX(0); }

/* Card colors */
.card-indigo {
    background: linear-gradient(135deg, #312e81 0%, #1e1b4b 100%);
    border-color: rgba(99,102,241,0.35);
    box-shadow: 0 4px 24px rgba(99,102,241,0.2);
}
.card-indigo:hover { box-shadow: 0 8px 32px rgba(99,102,241,0.35); }

.card-violet {
    background: linear-gradient(135deg, #4c1d95 0%, #2e1065 100%);
    border-color: rgba(139,92,246,0.35);
    box-shadow: 0 4px 24px rgba(139,92,246,0.2);
}
.card-violet:hover { box-shadow: 0 8px 32px rgba(139,92,246,0.35); }

.card-emerald {
    background: linear-gradient(135deg, #064e3b 0%, #022c22 100%);
    border-color: rgba(16,185,129,0.35);
    box-shadow: 0 4px 24px rgba(16,185,129,0.2);
}
.card-emerald:hover { box-shadow: 0 8px 32px rgba(16,185,129,0.35); }

.card-sky {
    background: linear-gradient(135deg, #0c4a6e 0%, #082f49 100%);
    border-color: rgba(14,165,233,0.35);
    box-shadow: 0 4px 24px rgba(14,165,233,0.2);
}
.card-sky:hover { box-shadow: 0 8px 32px rgba(14,165,233,0.35); }

.card-amber {
    background: linear-gradient(135deg, #78350f 0%, #451a03 100%);
    border-color: rgba(245,158,11,0.35);
    box-shadow: 0 4px 24px rgba(245,158,11,0.2);
}
.card-amber:hover { box-shadow: 0 8px 32px rgba(245,158,11,0.35); }

.card-rose {
    background: linear-gradient(135deg, #881337 0%, #4c0519 100%);
    border-color: rgba(244,63,94,0.35);
    box-shadow: 0 4px 24px rgba(244,63,94,0.2);
}
.card-rose:hover { box-shadow: 0 8px 32px rgba(244,63,94,0.35); }

.card-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(255,255,255,0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    backdrop-filter: blur(4px);
}

.card-title {
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    margin-bottom: 2px;
}

.card-desc {
    font-size: 12.5px;
    color: rgba(255,255,255,0.55);
    line-height: 1.5;
}

/* ===== INFO STRIP ===== */
.info-strip {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 0;
}

.info-box {
    background: var(--clr-surface);
    border: 1px solid var(--clr-border);
    border-radius: 12px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
}

.info-box-icon {
    font-size: 22px;
    flex-shrink: 0;
}

.info-box-label {
    font-size: 11px;
    color: var(--clr-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 2px;
}

.info-box-value {
    font-size: 15px;
    font-weight: 700;
    color: var(--clr-text);
}

@media (max-width: 600px) {
    .welcome-section { flex-wrap: wrap; }
    .welcome-time { margin-left: 0; text-align: left; }
    .card-grid { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 420px) {
    .card-grid { grid-template-columns: 1fr; }
}
</style>

<div class="page-wrap">

    <!-- Welcome Banner -->
    <div class="welcome-section">
        <div class="welcome-avatar"><?= $initial ?></div>
        <div class="welcome-text">
            <h1>Selamat datang, <?= $username ?>! 👋</h1>
            <p>Kelola jaringan Anda dari satu panel terpusat.</p>
        </div>
        <div class="welcome-time">
            <div class="time" id="live-time">--:--:--</div>
            <div class="date" id="live-date"></div>
        </div>
    </div>

    <!-- Menu Cards -->
    <p class="menu-section-title">Menu Utama</p>
    <div class="card-grid">

        <a class="menu-card card-indigo" href="pppoe.php">
            <div class="card-icon">📡</div>
            <div>
                <div class="card-title">PPPoE Active</div>
                <div class="card-desc">Lihat daftar klien PPPoE yang sedang aktif di Router</div>
            </div>
        </a>

        <a class="menu-card card-violet" href="olt_monitor.php">
            <div class="card-icon">📊</div>
            <div>
                <div class="card-title">Monitoring OLT</div>
                <div class="card-desc">Monitor optical power TX/RX dan status ONU secara real-time</div>
            </div>
        </a>

        <a class="menu-card card-emerald" href="olt_setting.php">
            <div class="card-icon">⚙️</div>
            <div>
                <div class="card-title">Pengaturan OLT</div>
                <div class="card-desc">Konfigurasi koneksi telnet, profil OLT, dan command</div>
            </div>
        </a>

        <a class="menu-card card-sky" href="router_setting.php">
            <div class="card-icon">🔧</div>
            <div>
                <div class="card-title">Pengaturan Router</div>
                <div class="card-desc">Konfigurasi koneksi API MikroTik untuk manajemen router</div>
            </div>
        </a>

    </div>

    <!-- Info Strip -->
    <p class="menu-section-title">Informasi Sesi</p>
    <div class="info-strip">
        <div class="info-box">
            <div class="info-box-icon">👤</div>
            <div>
                <div class="info-box-label">User</div>
                <div class="info-box-value"><?= $username ?></div>
            </div>
        </div>
        <div class="info-box">
            <div class="info-box-icon">🌐</div>
            <div>
                <div class="info-box-label">IP Anda</div>
                <div class="info-box-value"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
        <div class="info-box">
            <div class="info-box-icon">🕐</div>
            <div>
                <div class="info-box-label">Login Sesi</div>
                <div class="info-box-value" id="session-time">Aktif</div>
            </div>
        </div>
        <div class="info-box">
            <div class="info-box-icon">🛡️</div>
            <div>
                <div class="info-box-label">Status</div>
                <div class="info-box-value" style="color:#4ade80">● Online</div>
            </div>
        </div>
    </div>

</div>

<script>
function pad(n) { return String(n).padStart(2, '0'); }

function updateClock() {
    const now = new Date();
    document.getElementById('live-time').textContent =
        pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
    const days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    document.getElementById('live-date').textContent =
        days[now.getDay()] + ', ' + now.getDate() + ' ' + months[now.getMonth()] + ' ' + now.getFullYear();
}

updateClock();
setInterval(updateClock, 1000);

// Session timer
let secs = 0;
setInterval(() => {
    secs++;
    const h = Math.floor(secs / 3600);
    const m = Math.floor((secs % 3600) / 60);
    const s = secs % 60;
    document.getElementById('session-time').textContent =
        (h ? pad(h) + 'j ' : '') + pad(m) + 'm ' + pad(s) + 'd';
}, 1000);
</script>
</body>
</html>
