<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/helpers.php';

// Override the JSON content type set by bootstrap.php
if (!isset($_GET['api'])) {
    header('Content-Type: text/html; charset=utf-8');
}

// Basic admin auth simulation. In a real app, use a strong password hash or admin roles in DB.
$admin_pass = 'admin123'; 

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_auth']);
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $admin_pass) {
        $_SESSION['admin_auth'] = true;
    }
    header('Location: admin.php');
    exit;
}

if (empty($_SESSION['admin_auth'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8"><title>Mobifooty Admin</title>
        <style>
            :root { --bg: #050505; --surface: #111; --border: #2a2a2a; --text: #f0f0f0; --primary: #ff2a2a; }
            body { font-family: -apple-system, sans-serif; background: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .box { background: var(--surface); padding: 40px; border-radius: 12px; border: 1px solid var(--border); text-align: center; width: 100%; max-width: 360px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
            h2 { margin-top: 0; margin-bottom: 24px; font-weight: 800; color: var(--text); }
            input { padding: 14px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg); color: var(--text); margin-bottom: 16px; display: block; width: 100%; box-sizing: border-box; font-size: 14px; }
            input:focus { outline: none; border-color: var(--primary); }
            button { padding: 14px; background: var(--primary); color: #fff; border: none; border-radius: 8px; cursor: pointer; width: 100%; font-weight: bold; font-size: 15px; transition: background 0.2s; }
            button:hover { background: #e01f1f; }
            .back-link { display: block; margin-top: 20px; color: #888; text-decoration: none; font-size: 13px; }
            .back-link:hover { color: var(--text); }
        </style>
    </head>
    <body>
        <div class="box">
            <h2>⚡ Admin Login</h2>
            <form method="POST">
                <input type="password" name="password" placeholder="Enter Admin Password" required>
                <button type="submit">Access Dashboard</button>
            </form>
            <a href="index.html" class="back-link">&larr; Back to Site</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* ---------------- API ENDPOINTS ---------------- */
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $db = Database::getInstance()->getConnection();
    if ($_GET['api'] === 'stats') {
        $stats = [
            'revenue' => (float)$db->query("SELECT SUM(amount_ghs) FROM transactions WHERE status='success'")->fetchColumn() ?: 0,
            'users' => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'analyses' => (int)$db->query("SELECT COUNT(*) FROM analyses")->fetchColumn(),
            'engagements' => (int)$db->query("SELECT COUNT(*) FROM bets")->fetchColumn()
        ];
        echo json_encode($stats);
        exit;
    }
    if ($_GET['api'] === 'users') {
        $stmt = $db->query("SELECT * FROM users ORDER BY id DESC");
        echo json_encode(['users' => $stmt->fetchAll()]);
        exit;
    }
    if ($_GET['api'] === 'transactions') {
        $stmt = $db->query("SELECT * FROM transactions ORDER BY id DESC");
        echo json_encode(['transactions' => $stmt->fetchAll()]);
        exit;
    }
    if ($_GET['api'] === 'update_plan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $tier = $data['tier'] ?: null;
        $expires = $data['expires'] ?: null;
        $stmt = $db->prepare("UPDATE users SET current_tier = ?, tier_expires_at = ? WHERE id = ?");
        $stmt->execute([$tier, $expires, $data['id']]);
        echo json_encode(['success' => true]);
        exit;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal - Mobifooty</title>
    <style>
        :root { --bg: #050505; --surface: #111; --border: #2a2a2a; --text: #f0f0f0; --primary: #ff2a2a; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, sans-serif; background: var(--bg); color: var(--text); display: flex; height: 100vh; overflow: hidden; }
        .sidebar { width: 250px; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; padding: 20px; }
        .sidebar h2 { margin-bottom: 30px; font-size: 20px; color: var(--primary); }
        .sidebar .nav { display: flex; flex-direction: column; gap: 10px; flex-grow: 1; }
        .sidebar .nav button { background: transparent; color: var(--text-secondary); border: none; padding: 12px; border-radius: 6px; cursor: pointer; text-align: left; font-size: 14px; font-weight: bold; transition: all 0.2s; }
        .sidebar .nav button:hover { background: var(--border); color: var(--text); }
        .sidebar .nav button.active { background: var(--primary); color: #fff; }
        .sidebar .bottom-links { display: flex; flex-direction: column; gap: 10px; border-top: 1px solid var(--border); padding-top: 20px; }
        .sidebar .bottom-links a { color: #888; text-decoration: none; font-size: 13px; padding: 10px; display: block; border-radius: 6px; }
        .sidebar .bottom-links a:hover { background: var(--border); color: var(--text); }
        .main-content { flex-grow: 1; padding: 30px; overflow-y: auto; }
        
        .panel { display: none; }
        .panel.active { display: block; }
        
        table { width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; background: var(--surface); border-radius: 8px; overflow: hidden; }
        th, td { padding: 15px; border-bottom: 1px solid var(--border); }
        th { color: #888; text-transform: uppercase; font-size: 12px; background: #151515; }
        select, input[type="date"] { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 6px; border-radius: 4px; }
        button.save-btn { background: var(--primary); border: none; color: white; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-top: 20px; }
        .stat-card { background: var(--surface); padding: 25px; border-radius: 12px; border: 1px solid var(--border); }
        .stat-card h4 { color: #888; font-size: 13px; text-transform: uppercase; margin-bottom: 10px; }
        .stat-card .val { font-size: 32px; font-weight: bold; color: var(--primary); }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>⚡ Admin</h2>
    <div class="nav">
        <button class="active" onclick="showTab('dashboard', this)">Dashboard</button>
        <button onclick="showTab('users', this)">Users & Plans</button>
        <button onclick="showTab('transactions', this)">Payments</button>
    </div>
    <div class="bottom-links">
        <a href="index.html">&larr; Back to Site</a>
        <a href="?logout=1">Logout</a>
    </div>
</div>

<div class="main-content">
    <div id="dashboard" class="panel active">
        <h3 style="font-size: 24px; margin-bottom: 10px;">Overview</h3>
        <div class="stats-grid">
            <div class="stat-card"><h4>Revenue</h4><div class="val" id="statRev">-</div></div>
            <div class="stat-card"><h4>Users</h4><div class="val" id="statUsers">-</div></div>
            <div class="stat-card"><h4>Analyses Done</h4><div class="val" id="statAn">-</div></div>
            <div class="stat-card"><h4>Engagements (Bets)</h4><div class="val" id="statEng">-</div></div>
        </div>
    </div>

    <div id="users" class="panel">
        <h3 style="font-size: 24px; margin-bottom: 20px;">Users & Plan Management</h3>
        <table>
            <thead><tr><th>ID</th><th>Phone</th><th>Joined</th><th>Plan</th><th>Expires</th><th>Action</th></tr></thead>
            <tbody id="usersList"><tr><td colspan="6">Loading...</td></tr></tbody>
        </table>
    </div>

    <div id="transactions" class="panel">
        <h3 style="font-size: 24px; margin-bottom: 20px;">Payments Ledger</h3>
        <table>
            <thead><tr><th>Ref</th><th>User ID</th><th>Amount</th><th>Tier</th><th>Status</th><th>Date</th></tr></thead>
            <tbody id="txList"><tr><td colspan="6">Loading...</td></tr></tbody>
        </table>
    </div>
</div>

<script>
    function showTab(tab, btn) {
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.nav button').forEach(b => b.classList.remove('active'));
        document.getElementById(tab).classList.add('active');
        btn.classList.add('active');
    }

    async function loadUsers() {
        const res = await fetch('?api=users');
        const data = await res.json();
        const tbody = document.getElementById('usersList');
        tbody.innerHTML = '';
        data.users.forEach(u => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${u.id}</td>
                <td>${u.phone}</td>
                <td>${new Date(u.created_at).toLocaleDateString()}</td>
                <td>
                    <select id="tier_${u.id}">
                        <option value="" ${!u.current_tier ? 'selected' : ''}>None</option>
                        <option value="starter" ${u.current_tier=='starter'?'selected':''}>Starter</option>
                        <option value="basic" ${u.current_tier=='basic'?'selected':''}>Basic</option>
                        <option value="pro" ${u.current_tier=='pro'?'selected':''}>Pro</option>
                        <option value="elite" ${u.current_tier=='elite'?'selected':''}>Elite</option>
                    </select>
                </td>
                <td>
                    <input type="date" id="exp_${u.id}" value="${u.tier_expires_at ? u.tier_expires_at.split(' ')[0] : ''}">
                </td>
                <td><button class="save-btn" onclick="savePlan(${u.id})">Save</button></td>
            `;
            tbody.appendChild(tr);
        });
    }

    async function savePlan(id) {
        const tier = document.getElementById(`tier_${id}`).value;
        const exp = document.getElementById(`exp_${id}`).value;
        const expires = exp ? exp + ' 23:59:59' : '';
        await fetch('?api=update_plan', {
            method: 'POST',
            body: JSON.stringify({ id, tier, expires })
        });
        alert('Plan updated!');
    }

    async function loadTx() {
        const res = await fetch('?api=transactions');
        const data = await res.json();
        const tbody = document.getElementById('txList');
        tbody.innerHTML = '';
        data.transactions.forEach(t => {
            tbody.innerHTML += `
                <tr>
                    <td>${t.reference}</td>
                    <td>${t.user_id}</td>
                    <td>GHS ${t.amount_ghs}</td>
                    <td>${t.tier}</td>
                    <td><span style="color:${t.status=='success'?'#4caf50':(t.status=='pending'?'#ff9800':'#f44336')}">${t.status}</span></td>
                    <td>${new Date(t.created_at).toLocaleString()}</td>
                </tr>
            `;
        });
    }

    async function loadStats() {
        const res = await fetch('?api=stats');
        const data = await res.json();
        document.getElementById('statRev').textContent = 'GHS ' + (data.revenue || 0).toFixed(2);
        document.getElementById('statUsers').textContent = data.users || 0;
        document.getElementById('statAn').textContent = data.analyses || 0;
        document.getElementById('statEng').textContent = data.engagements || 0;
    }

    loadStats();
    loadUsers();
    loadTx();
</script>
</body>
</html>
