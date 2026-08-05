<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/RouterosAPI.php';

checkUser();

$userId = (int) $_SESSION['user_id'];
$error = '';
$activeClients = [];
$search = trim($_GET['search'] ?? '');

$stmt = $pdo->prepare(
    'SELECT id, ip_address, api_user, api_pass, api_port FROM routers WHERE user_id = :user_id ORDER BY id ASC LIMIT 1'
);
$stmt->execute(['user_id' => $userId]);
$router = $stmt->fetch();

if (!$router) {
    $error = 'Pengaturan router belum tersedia. Silakan isi pengaturan router terlebih dahulu.';
} else {
    $api = new RouterosAPI();
    $api->timeout = 5;

    try {
        if ($api->connect($router['ip_address'], $router['api_user'], $router['api_pass'], (int) $router['api_port'])) {
            $response = $api->comm('/ppp/active/print');

            foreach ($response as $item) {
                if (isset($item['!re'])) {
                    $activeClients[] = $item;
                }
            }

            $api->disconnect();
        } else {
            $error = 'Gagal terhubung ke API MikroTik: ' . ($api->error ?? 'Periksa pengaturan router.');
        }
    } catch (Throwable $exception) {
        $api->disconnect();
        $error = 'Gagal mengambil data PPPoE: ' . $exception->getMessage();
    }
}

if ($search !== '') {
    $keyword = strtolower($search);
    $activeClients = array_values(array_filter($activeClients, static function (array $client) use ($keyword): bool {
        $haystack = implode(' ', [
            $client['name'] ?? '',
            $client['service'] ?? '',
            $client['caller-id'] ?? '',
            $client['address'] ?? '',
            $client['uptime'] ?? '',
        ]);

        return str_contains(strtolower($haystack), $keyword);
    }));
}

$pageTitle  = 'PPPoE Active';
$activePage = 'pppoe';
require_once __DIR__ . '/partials/header.php';
?>
<style>
/* ── Panel & layout ── */
.panel { padding: 24px; background: var(--clr-surface); border: 1px solid var(--clr-border); border-radius: var(--radius); box-shadow: var(--shadow); }
.panel h2 { font-size:16px; margin-bottom:16px; }
.search-box { display: flex; gap: 10px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
.search-box input { flex: 1; min-width: 200px; margin-bottom: 0; }
.result-count { color: var(--clr-muted); font-size: 14px; }
.search-button { flex-shrink: 0; }
.table-wrap { overflow-x: auto; }
.remote-link {
    display:inline-flex; align-items:center; padding:6px 12px; border-radius:7px;
    background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-weight:700;
    text-decoration:none; font-size:12px; white-space:nowrap;
}
.btn-signal {
    display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border-radius:7px;
    background: rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.35);
    color:#4ade80; font-weight:700; font-size:12px; cursor:pointer;
    font-family:inherit; white-space:nowrap; transition: background .15s, transform .12s;
}
.btn-signal:hover { background: rgba(16,185,129,0.25); transform:translateY(-1px); }
.empty { color: var(--clr-muted); text-align: center; }
.aksi-cell { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

/* ── Modal overlay ── */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.65);
    backdrop-filter: blur(6px);
    z-index: 999;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active { display: flex; }

.modal-box {
    background: #1a1d2e;
    border: 1px solid rgba(99,102,241,0.3);
    border-radius: 20px;
    padding: 32px 28px;
    width: min(100% - 32px, 480px);
    box-shadow: 0 20px 60px rgba(0,0,0,0.6);
    position: relative;
    animation: modalIn .22s ease;
}

@keyframes modalIn {
    from { transform: scale(.92) translateY(20px); opacity: 0; }
    to   { transform: scale(1) translateY(0); opacity: 1; }
}

.modal-close {
    position: absolute; top: 16px; right: 18px;
    background: none; border: none;
    color: #64748b; font-size: 22px; cursor: pointer; line-height: 1;
    transition: color .15s;
}
.modal-close:hover { color: #e2e8f0; }

.modal-header { margin-bottom: 24px; }
.modal-header h2 { font-size: 18px; font-weight: 800; margin-bottom: 4px; color: #e2e8f0; }
.modal-header .modal-sub { font-size: 13px; color: #64748b; }

.modal-meta {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 10px; margin-bottom: 22px;
}
.meta-box {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px; padding: 12px 14px;
}
.meta-box .lbl { font-size: 11px; color: #475569; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 3px; }
.meta-box .val { font-size: 14px; color: #cbd5e1; font-weight: 600; }

/* ── Signal meters ── */
.signal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 22px; }

.signal-card {
    border-radius: 14px;
    padding: 20px 16px;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.signal-card::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(circle at 50% 0%, var(--glow,rgba(99,102,241,.25)), transparent 70%);
}
.signal-card .sig-type { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; opacity: .7; margin-bottom: 8px; }
.signal-card .sig-val  { font-size: 32px; font-weight: 900; letter-spacing: -1px; margin-bottom: 6px; }
.signal-card .sig-unit { font-size: 13px; opacity: .6; margin-bottom: 10px; }
.signal-card .sig-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;
    background: rgba(255,255,255,0.1);
}

.sig-tx { background: linear-gradient(145deg, rgba(99,102,241,0.2), rgba(99,102,241,0.08)); border: 1px solid rgba(99,102,241,0.3); --glow: rgba(99,102,241,0.3); }
.sig-rx { background: linear-gradient(145deg, rgba(16,185,129,0.2), rgba(16,185,129,0.08)); border: 1px solid rgba(16,185,129,0.3); --glow: rgba(16,185,129,0.3); }

/* ── Loading state ── */
.modal-loading {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 40px 20px; gap: 16px;
}
.spinner {
    width: 44px; height: 44px;
    border: 3px solid rgba(99,102,241,0.2);
    border-top-color: #6366f1;
    border-radius: 50%;
    animation: spin .75s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.spinner-text { font-size: 14px; color: #64748b; }

/* ── Error state ── */
.modal-error {
    text-align: center; padding: 30px 10px;
    display: none;
}
.modal-error .err-icon { font-size: 42px; margin-bottom: 14px; }
.modal-error p { font-size: 14px; color: #f87171; line-height: 1.6; }

/* ── Command info ── */
.cmd-info {
    background: rgba(0,0,0,0.2);
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 12px;
    color: #475569;
}
.cmd-info code { color: #a5b4fc; font-size: 11px; }
</style>

<div class="page-wrap">
<p class="page-heading">PPPoE Client Active</p>
<p class="page-sub">Daftar klien PPPoE yang sedang aktif di Router MikroTik.</p>
    <section class="panel">

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form class="toolbar" method="get" action="">
                <div class="search-box">
                    <label for="pppoe-search">Search PPPoE</label>
                    <input
                        type="search"
                        id="pppoe-search"
                        name="search"
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Cari name, caller ID, address....."
                        autocomplete="off"
                    >
                </div>
                <button class="search-button btn btn-primary" type="submit">🔍 Cari</button>
                <div class="result-count" id="pppoe-result-count">
                    Total: <?= count($activeClients) ?> client
                </div>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Service</th>
                            <th>Caller ID</th>
                            <th>Address</th>
                            <th>Uptime</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="pppoe-table-body">
                        <?php if ($activeClients === []): ?>
                            <tr class="pppoe-row">
                                <td class="empty" colspan="6">Tidak ada PPPoE client active.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($activeClients as $client): ?>
                            <?php $clientIp   = $client['address'] ?? ''; ?>
                            <?php $clientName = $client['name'] ?? ''; ?>
                            <tr class="pppoe-row">
                                <td><?= htmlspecialchars($clientName ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($client['service'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($client['caller-id'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($clientIp !== '' ? $clientIp : '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($client['uptime'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="aksi-cell">
                                        <?php if ($clientIp !== ''): ?>
                                            <a
                                                class="remote-link"
                                                href="remote_action.php?ip=<?= urlencode($clientIp) ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >🔗 Remote</a>
                                        <?php endif; ?>
                                        <?php if ($clientName !== ''): ?>
                                            <button
                                                class="btn-signal"
                                                onclick="openSignalModal(<?= htmlspecialchars(json_encode($clientName), ENT_QUOTES, 'UTF-8') ?>)"
                                                title="Cek sinyal optik OLT"
                                            >📶 Cek Signal</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="search-empty-row" style="display: none;">
                            <td class="empty" colspan="6">Data tidak ditemukan.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
</div>

<!-- ══════════════════════════════════════════════
     SIGNAL MODAL
══════════════════════════════════════════════ -->
<div class="modal-overlay" id="signalModal" onclick="closeModalOnBg(event)">
    <div class="modal-box">
        <button class="modal-close" onclick="closeSignalModal()">✕</button>

        <!-- Loading state -->
        <div class="modal-loading" id="modalLoading">
            <div class="spinner"></div>
            <span class="spinner-text">Menghubungi OLT…</span>
        </div>

        <!-- Error state -->
        <div class="modal-error" id="modalError">
            <div class="err-icon">⚠️</div>
            <p id="modalErrorText"></p>
        </div>

        <!-- Result state -->
        <div id="modalResult" style="display:none">
            <div class="modal-header">
                <h2 id="modalTitle">Kualitas Sinyal OLT</h2>
                <div class="modal-sub" id="modalSub"></div>
            </div>

            <div class="modal-meta">
                <div class="meta-box">
                    <div class="lbl">PPPoE Name</div>
                    <div class="val" id="rPppoeName">—</div>
                </div>
                <div class="meta-box">
                    <div class="lbl">PON/ONU</div>
                    <div class="val" id="rPonOnu">—</div>
                </div>
                <div class="meta-box">
                    <div class="lbl">OLT</div>
                    <div class="val" id="rOltName">—</div>
                </div>
                <div class="meta-box">
                    <div class="lbl">Pelanggan</div>
                    <div class="val" id="rCustomer">—</div>
                </div>
            </div>

            <div class="signal-grid">
                <!-- TX Card -->
                <div class="signal-card sig-tx">
                    <div class="sig-type">TX Power</div>
                    <div class="sig-val" id="rTxVal">—</div>
                    <div class="sig-unit">dBm</div>
                    <div class="sig-badge" id="rTxBadge">—</div>
                </div>
                <!-- RX Card -->
                <div class="signal-card sig-rx">
                    <div class="sig-type">RX Power</div>
                    <div class="sig-val" id="rRxVal">—</div>
                    <div class="sig-unit">dBm</div>
                    <div class="sig-badge" id="rRxBadge">—</div>
                </div>
            </div>

            <div class="cmd-info">
                Command: <code id="rCmd">—</code>
            </div>
        </div>
    </div>
</div>

<script>
    /* ─── Live search ─────────────────────────────────────── */
    const searchInput = document.getElementById('pppoe-search');
    const rows = Array.from(document.querySelectorAll('.pppoe-row'));
    const emptyRow = document.getElementById('search-empty-row');
    const resultCount = document.getElementById('pppoe-result-count');

    function filterPppoeRows() {
        const keyword = searchInput.value.trim().toLowerCase();
        let visibleCount = 0;

        rows.forEach((row) => {
            const isMatch = row.textContent.toLowerCase().includes(keyword);
            row.style.display = isMatch ? '' : 'none';
            if (isMatch) visibleCount += 1;
        });

        if (emptyRow) {
            emptyRow.style.display = rows.length > 0 && visibleCount === 0 ? '' : 'none';
        }
        resultCount.textContent = `Total: ${visibleCount} client`;
    }

    searchInput.addEventListener('input', filterPppoeRows);

    /* ─── Signal Modal ────────────────────────────────────── */
    const modal      = document.getElementById('signalModal');
    const elLoading  = document.getElementById('modalLoading');
    const elError    = document.getElementById('modalError');
    const elErrorTxt = document.getElementById('modalErrorText');
    const elResult   = document.getElementById('modalResult');

    function showLoading() {
        elLoading.style.display = 'flex';
        elError.style.display   = 'none';
        elResult.style.display  = 'none';
    }

    function showError(msg) {
        elLoading.style.display = 'none';
        elError.style.display   = 'block';
        elResult.style.display  = 'none';
        elErrorTxt.textContent  = msg;
    }

    function showResult(d) {
        elLoading.style.display = 'none';
        elError.style.display   = 'none';
        elResult.style.display  = 'block';

        document.getElementById('rPppoeName').textContent = d.pppoe_name || '—';
        document.getElementById('rPonOnu').textContent    = d.pon_onu    || '—';
        document.getElementById('rOltName').textContent   = d.olt_name   || '—';
        document.getElementById('rCustomer').textContent  = d.customer_name || '-';
        document.getElementById('rCmd').textContent       = d.command_used || '—';
        document.getElementById('modalSub').textContent   = d.brand + ' ' + d.model;

        // TX
        const txVal = d.tx !== null ? d.tx.toFixed(2) : '—';
        document.getElementById('rTxVal').textContent    = txVal;
        document.getElementById('rTxBadge').textContent  = (d.tx_cat.emoji + ' ' + d.tx_cat.label);
        document.getElementById('rTxBadge').style.color  = d.tx_cat.color;
        document.getElementById('rTxVal').style.color    = d.tx_cat.color;

        // RX
        const rxVal = d.rx !== null ? d.rx.toFixed(2) : '—';
        document.getElementById('rRxVal').textContent    = rxVal;
        document.getElementById('rRxBadge').textContent  = (d.rx_cat.emoji + ' ' + d.rx_cat.label);
        document.getElementById('rRxBadge').style.color  = d.rx_cat.color;
        document.getElementById('rRxVal').style.color    = d.rx_cat.color;
    }

    async function openSignalModal(pppoeName) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        showLoading();

        // AbortController: batalkan request jika > 20 detik (hindari nginx timeout)
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), 20000);

        try {
            const url = `signal_check.php?pppoe=${encodeURIComponent(pppoeName)}`;
            const res = await fetch(url, { signal: controller.signal });
            clearTimeout(timeoutId);
            const body = await res.text();
            let data;

            try {
                data = JSON.parse(body);
            } catch (parseErr) {
                const detail = body.trim().replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 180);
                showError(detail || `Server mengembalikan response tidak valid (HTTP ${res.status}).`);
                return;
            }

            if (!res.ok || !data.ok) {
                showError(data.error || `Request gagal (HTTP ${res.status}).`);
            } else {
                showResult(data);
            }
        } catch (err) {
            clearTimeout(timeoutId);
            if (err.name === 'AbortError') {
                showError('⏱️ Koneksi ke OLT timeout (>20 detik). OLT tidak merespons atau jaringan lambat. Silakan coba lagi.');
            } else {
                showError('Gagal menghubungi server: ' + err.message);
            }
        }
    }

    function closeSignalModal() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function closeModalOnBg(e) {
        if (e.target === modal) closeSignalModal();
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeSignalModal();
    });
</script>
</body>
</html>
