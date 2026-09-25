<?php
// index.php - GalaxyRAD WISP & Hotspot Master System (SQLite Version for Render)
session_start();

// ==========================================
// Database Configuration (SQLite for Render)
// ==========================================
$db_file = __DIR__ . '/database.sqlite';

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Robust Database Schema for SQLite
    $pdo->exec("CREATE TABLE IF NOT EXISTS routers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        ip_address TEXT NOT NULL,
        username TEXT NOT NULL,
        password TEXT,
        port INTEGER DEFAULT 8728,
        status TEXT DEFAULT 'Online',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS operators (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        status TEXT DEFAULT 'Active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS packages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        package_name TEXT NOT NULL,
        price REAL NOT NULL,
        validity TEXT NOT NULL,
        speed_limit TEXT DEFAULT '1M/1M',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vouchers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        package_id INTEGER,
        router_ids TEXT NOT NULL,
        batch_id TEXT,
        status TEXT DEFAULT 'Unused',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

} catch (Exception $e) {
    die("<div style='font-family:sans-serif; padding:20px; background:#fee2e2; color:#991b1b;'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// ==========================================
// MikroTik API Class Helper
// ==========================================
class RouterosAPI {
    var $debug = false;
    var $connected = false;
    var $port = 8728;
    var $ssl = false;
    var $timeout = 3;
    var $attempts = 1;
    var $delay = 3;
    private $socket = 0;
    private $error_no;
    private $error_str;

    public function connect($ip, $login, $password) {
        for ($i = 1; $i <= $this->attempts; $i++) {
            $this->connected = false;
            $protocol = $this->ssl ? 'ssl://' : '';
            $this->socket = @fsockopen($protocol . $ip, $this->port, $this->error_no, $this->error_str, $this->timeout);
            if ($this->socket) {
                socket_set_timeout($this->socket, $this->timeout);
                if ($this->login($login, $password)) {
                    $this->connected = true;
                    break;
                }
                fclose($this->socket);
            }
            sleep($this->delay);
        }
        return $this->connected;
    }

    public function login($username, $password) {
        $this->write('/login', false);
        $this->write('=name=' . $username, false);
        $this->write('=password=' . $password);
        $responses = $this->read();
        if (isset($responses[0]) && $responses[0] == '!done') {
            if (isset($responses[1]) && preg_match('/=ret=([a-fA-F0-9]{32})/', $responses[1], $match)) {
                $this->write('/login', false);
                $this->write('=name=' . $username, false);
                $this->write('=response=00' . md5(chr(0) . $password . pack('H*', $match[1])));
                $responses = $this->read();
                if (isset($responses[0]) && $responses[0] == '!done') return true;
            }
        }
        return false;
    }

    public function comm($com, $array = array()) {
        $this->write($com, false);
        foreach ($array as $key => $value) {
            $this->write("=$key=$value", false);
        }
        $this->write('');
        return $this->read();
    }

    private function write($commun, $space = true) {
        if ($this->debug) echo "Sending: $commun<br>";
        fwrite($this->socket, $this->encodeLength(strlen($commun)) . $commun);
        if ($space) fwrite($this->socket, chr(0));
    }

    private function encodeLength($length) {
        if ($length < 0x80) return chr($length);
        if ($length < 0x4000) return chr(($length >> 8) | 0x80) . chr($length & 0xFF);
        if ($length < 0x200000) return chr(($length >> 16) | 0xC0) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        if ($length < 0x10000000) return chr(($length >> 24) | 0xE0) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        return chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
    }

    private function read() {
        $reads = array();
        $this->parseResponse($this->read_response(), $reads);
        return $reads;
    }

    private function read_response() {
        $result = array();
        while (true) {
            $byte = ord(fread($this->socket, 1));
            $length = 0;
            if (($byte & 0x80) == 0x00) {
                $length = $byte;
            } elseif (($byte & 0xC0) == 0x80) {
                $length = (($byte & 0x3F) << 8) + ord(fread($this->socket, 1));
            } elseif (($byte & 0xE0) == 0xC0) {
                $length = (($byte & 0x1F) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            } elseif (($byte & 0xF0) == 0xE0) {
                $length = (($byte & 0x0F) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            } else {
                $length = (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            }
            if ($length > 0) {
                $result[] = fread($this->socket, $length);
            } else {
                break;
            }
        }
        return $result;
    }

    private function parseResponse($response, &$array) {
        $current = array();
        $i = 0;
        foreach ($response as $res) {
            if ($res == '!done') {
                $array[$i] = '!done';
                $i++;
            } else {
                $array[$i][$res[0]] = substr($res, 1);
            }
        }
    }
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Authentication Handling
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if ($username === 'admin' && $password === '123456') {
        $_SESSION['user'] = 'Super Admin';
        $_SESSION['role'] = 'admin';
        header("Location: index.php");
        exit();
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM operators WHERE username = ? AND status = 'Active'");
            $stmt->execute([$username]);
            $op = $stmt->fetch();

            if ($op && password_verify($password, $op['password'])) {
                $_SESSION['user'] = $op['name'];
                $_SESSION['role'] = 'operator';
                header("Location: index.php");
                exit();
            } else {
                $login_error = "Invalid Username or Password!";
            }
        } catch (Exception $e) {
            $login_error = "Login Error!";
        }
    }
}

if (!isset($_SESSION['user'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - GalaxyRAD</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 flex items-center justify-center min-h-screen">
    <div class="bg-slate-900 p-8 rounded-2xl shadow-2xl w-full max-w-md border border-slate-800">
        <div class="text-center mb-6">
            <h1 class="text-3xl font-black text-white">Galaxy<span class="text-orange-500">RAD</span></h1>
            <p class="text-xs text-slate-400 mt-1 uppercase">Live MikroTik API Management System</p>
        </div>
        <?php if($login_error): ?>
            <div class="bg-red-950 border border-red-500 text-red-300 p-3 rounded-lg mb-4 text-sm text-center"><?= $login_error ?></div>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-xs font-bold text-slate-400 mb-1 uppercase">Username</label>
                <input type="text" name="username" required value="admin" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-4 py-3 text-white text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-400 mb-1 uppercase">Password</label>
                <input type="password" name="password" required value="123456" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-4 py-3 text-white text-sm">
            </div>
            <button type="submit" name="login" class="w-full bg-orange-600 hover:bg-orange-500 text-white font-bold py-3 rounded-lg text-sm">Secure Login</button>
        </form>
    </div>
</body>
</html>
<?php exit(); endif;

// Actions Handling
$success_msg = '';
$error_msg = '';
$print_batch = isset($_GET['print_batch']) ? trim($_GET['print_batch']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_router']) && $_SESSION['role'] === 'admin') {
        $name = trim($_POST['router_name']);
        $ip = trim($_POST['router_ip']);
        $user = trim($_POST['router_user']);
        $pass = trim($_POST['router_pass']);
        $port = intval($_POST['router_port']);

        if ($name && $ip) {
            try {
                $stmt = $pdo->prepare("INSERT INTO routers (name, ip_address, username, password, port) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $ip, $user, $pass, $port]);
                $success_msg = "Router added successfully!";
            } catch(Exception $e) { $error_msg = "Error adding router."; }
        }
    }

    if (isset($_POST['add_package'])) {
        $pname = trim($_POST['package_name']);
        $price = floatval($_POST['price']);
        $validity = trim($_POST['validity']);
        $speed = trim($_POST['speed_limit']);

        if ($pname && $price) {
            try {
                $stmt = $pdo->prepare("INSERT INTO packages (package_name, price, validity, speed_limit) VALUES (?, ?, ?, ?)");
                $stmt->execute([$pname, $price, $validity, $speed]);
                $success_msg = "Package created successfully!";
            } catch(Exception $e) { $error_msg = "Error creating package."; }
        }
    }

    if (isset($_POST['generate_vouchers'])) {
        $package_id = intval($_POST['package_id']);
        $quantity = intval($_POST['quantity']);
        $selected_routers = isset($_POST['router_ids']) ? $_POST['router_ids'] : [];

        if ($package_id && $quantity > 0 && count($selected_routers) > 0) {
            $router_ids_str = implode(',', $selected_routers);
            $batch_id = 'BATCH-' . strtoupper(substr(md5(mt_rand()), 0, 6));
            try {
                $placeholders = implode(',', array_fill(0, count($selected_routers), '?'));
                $stmt_r = $pdo->prepare("SELECT * FROM routers WHERE id IN ($placeholders)");
                $stmt_r->execute($selected_routers);
                $target_routers = $stmt_r->fetchAll();

                $stmt = $pdo->prepare("INSERT INTO vouchers (code, password, package_id, router_ids, batch_id, status) VALUES (?, ?, ?, ?, ?, 'Unused')");
                
                for ($i = 0; $i < $quantity; $i++) {
                    $code = rand(100000, 999999);
                    $password = $code;
                    try {
                        $stmt->execute([$code, $password, $package_id, $router_ids_str, $batch_id]);
                        foreach($target_routers as $tr) {
                            $API = new RouterosAPI();
                            if (@$API->connect($tr['ip_address'], $tr['username'], $tr['password'])) {
                                $API->comm("/ip/hotspot/user/add", array(
                                    "name" => $code,
                                    "password" => $password,
                                    "profile" => "default"
                                ));
                            }
                        }
                    } catch(Exception $ex) {}
                }
                
                header("Location: index.php?print_batch=" . $batch_id);
                exit();

            } catch(Exception $e) { $error_msg = "Voucher generation failed."; }
        } else {
            $error_msg = "Please select a package, quantity, and at least one target router.";
        }
    }
}

if (isset($_GET['delete_router']) && $_SESSION['role'] === 'admin') {
    $router_id = intval($_GET['delete_router']);
    $pdo->prepare("DELETE FROM routers WHERE id = ?")->execute([$router_id]);
    header("Location: index.php");
    exit();
}

try {
    $total_routers = $pdo->query("SELECT COUNT(*) FROM routers")->fetchColumn();
    $total_packages = $pdo->query("SELECT COUNT(*) FROM packages")->fetchColumn();
    $total_vouchers = $pdo->query("SELECT COUNT(*) FROM vouchers")->fetchColumn();
    $used_vouchers = $pdo->query("SELECT COUNT(*) FROM vouchers WHERE status='Used'")->fetchColumn();

    $routers = $pdo->query("SELECT * FROM routers ORDER BY id DESC")->fetchAll();
    $packages = $pdo->query("SELECT * FROM packages ORDER BY id DESC")->fetchAll();
    $voucher_batches = $pdo->query("SELECT batch_id, COUNT(*) as total, MIN(created_at) as cdate, package_id, router_ids FROM vouchers GROUP BY batch_id ORDER BY id DESC LIMIT 10")->fetchAll();

    $total_live_active_users = 0;
    $total_router_users = 0;

    foreach($routers as &$r) {
        $API = new RouterosAPI();
        if (@$API->connect($r['ip_address'], $r['username'], $r['password'])) {
            $r['live_status'] = 'Online';
            $all_r_users = $API->comm("/ip/hotspot/user/print");
            $r['total_users'] = is_array($all_r_users) ? count($all_r_users) : 0;
            $total_router_users += $r['total_users'];

            $active_users = $API->comm("/ip/hotspot/active/print");
            $r['active_count'] = is_array($active_users) ? count($active_users) : 0;
            $total_live_active_users += $r['active_count'];
        } else {
            $r['live_status'] = 'Offline';
            $r['total_users'] = 0;
            $r['active_count'] = 0;
        }
    }
    unset($r);

} catch(Exception $e) {
    $total_routers = 0; $total_packages = 0; $total_vouchers = 0; $used_vouchers = 0;
    $total_live_active_users = 0; $total_router_users = 0;
    $routers = []; $packages = []; $voucher_batches = [];
}

$inspect_router = null;
$inspect_users = [];
$inspect_active = [];
if (isset($_GET['inspect'])) {
    $insp_id = intval($_GET['inspect']);
    foreach($routers as $rt) {
        if ($rt['id'] == $insp_id) {
            $inspect_router = $rt;
            $API = new RouterosAPI();
            if (@$API->connect($rt['ip_address'], $rt['username'], $rt['password'])) {
                $inspect_users = $API->comm("/ip/hotspot/user/print");
                $inspect_active = $API->comm("/ip/hotspot/active/print");
            }
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GalaxyRAD - Live Multi-Router API System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            body { background: white !important; color: black !important; }
            .no-print { display: none !important; }
            .print-card { border: 2px dashed #333 !important; break-inside: avoid; background: #fff !important; color: #000 !important; }
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans flex h-screen overflow-hidden">

    <?php if($print_batch): 
        $stmt_b = $pdo->prepare("SELECT v.*, p.package_name, p.price, p.validity FROM vouchers v LEFT JOIN packages p ON v.package_id = p.id WHERE v.batch_id = ?");
        $stmt_b->execute([$print_batch]);
        $batch_cards = $stmt_b->fetchAll();
    ?>
    <div class="fixed inset-0 bg-slate-950 z-50 overflow-y-auto p-8">
        <div class="max-w-5xl mx-auto space-y-6">
            <div class="flex justify-between items-center bg-slate-900 border border-slate-800 p-4 rounded-xl no-print">
                <div>
                    <h2 class="text-lg font-black text-white">🎟️ Voucher Batch Cards: <span class="text-orange-500"><?= htmlspecialchars($print_batch) ?></span></h2>
                    <p class="text-xs text-slate-400">Total Cards: <?= count($batch_cards) ?> | Ready for Print / PDF Export</p>
                </div>
                <div class="space-x-3">
                    <button onclick="window.print()" class="bg-orange-600 hover:bg-orange-500 text-white font-bold px-4 py-2 rounded-lg text-xs">🖨️ Print / Save as PDF</button>
                    <a href="index.php" class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded-lg text-xs font-bold">← Back to Dashboard</a>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <?php foreach($batch_cards as $card): 
                    $r_ids = explode(',', $card['router_ids']);
                    $r_names = [];
                    foreach($routers as $rt) {
                        if(in_array($rt['id'], $r_ids)) $r_names[] = $rt['name'];
                    }
                ?>
                <div class="print-card bg-slate-900 border border-slate-800 rounded-xl p-4 shadow flex flex-col justify-between">
                    <div>
                        <div class="flex justify-between items-center border-b border-slate-800 pb-2 mb-2">
                            <span class="text-xs font-black text-orange-500 uppercase">Galaxy<span class="text-white">NET</span> Hotspot</span>
                            <span class="text-[10px] bg-slate-950 px-2 py-0.5 rounded text-slate-300 font-bold"><?= htmlspecialchars($card['package_name']) ?></span>
                        </div>
                        <div class="text-center my-3">
                            <p class="text-[10px] text-slate-400 uppercase font-bold">Username & Password</p>
                            <h3 class="text-xl font-mono font-black text-white tracking-widest bg-slate-950 py-1.5 rounded my-1 border border-slate-800"><?= htmlspecialchars($card['code']) ?></h3>
                        </div>
                        <div class="text-[10px] text-slate-400 space-y-0.5">
                            <p><strong>Validity:</strong> <?= htmlspecialchars($card['validity']) ?></p>
                            <p><strong>Price:</strong> <?= htmlspecialchars($card['price']) ?>৳</p>
                            <p><strong>Routers:</strong> <?= implode(', ', $r_names) ?></p>
                        </div>
                    </div>
                    <div class="mt-3 pt-2 border-t border-slate-800 text-[9px] text-slate-500 text-center">Login via WiFi Hotspot Portal</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php exit(); endif; ?>

    <aside class="w-64 bg-slate-950 text-slate-300 hidden md:flex flex-col justify-between shrink-0 border-r border-slate-800 no-print">
        <div>
            <div class="p-5 bg-slate-950 border-b border-slate-800 flex items-center space-x-2">
                <span class="text-lg font-black text-white">Galaxy<span class="text-orange-500">RAD</span></span>
            </div>
            <nav class="p-4 space-y-1.5 text-sm">
                <a href="index.php" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-xl bg-orange-600 text-white font-bold"><span>📊</span> <span>Dashboard</span></a>
            </nav>
        </div>
        <div class="p-4 border-t border-slate-800 text-xs text-slate-500">GalaxyRAD API v9.5</div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0 overflow-y-auto no-print">
        <header class="bg-slate-950 border-b border-slate-800 h-16 flex items-center justify-between px-6 shrink-0 sticky top-0 z-20">
            <span class="font-bold text-slate-300 text-sm">MikroTik RouterOS API - Multi-Router Voucher System</span>
            <div class="flex items-center space-x-4">
                <span class="text-xs bg-slate-900 text-slate-300 px-3 py-1 rounded-full border border-slate-800">User: <strong><?= htmlspecialchars($_SESSION['user']) ?></strong></span>
                <a href="?logout=true" class="bg-red-950 hover:bg-red-900 text-red-300 px-3 py-1.5 rounded-lg text-xs font-bold border border-red-800">Logout</a>
            </div>
        </header>

        <main class="p-6 space-y-6">
            <?php if($success_msg): ?><div class="bg-emerald-950 border border-emerald-600 text-emerald-200 p-4 rounded-xl text-sm"><?= $success_msg ?></div><?php endif; ?>
            <?php if($error_msg): ?><div class="bg-red-950 border border-red-600 text-red-200 p-4 rounded-xl text-sm"><?= $error_msg ?></div><?php endif; ?>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div class="bg-slate-950 border border-slate-800 p-4 rounded-2xl text-center">
                    <p class="text-[11px] font-bold text-slate-400 uppercase">TOTAL ROUTERS</p>
                    <h3 class="text-2xl font-black text-white mt-1"><?= $total_routers ?></h3>
                </div>
                <div class="bg-slate-950 border border-slate-800 p-4 rounded-2xl text-center">
                    <p class="text-[11px] font-bold text-indigo-400 uppercase">TOTAL USERS IN ROUTERS</p>
                    <h3 class="text-2xl font-black text-indigo-400 mt-1"><?= $total_router_users ?></h3>
                </div>
                <div class="bg-slate-950 border border-slate-800 p-4 rounded-2xl text-center">
                    <p class="text-[11px] font-bold text-emerald-400 uppercase">TOTAL LIVE ACTIVE</p>
                    <h3 class="text-2xl font-black text-emerald-400 mt-1"><?= $total_live_active_users ?></h3>
                </div>
                <div class="bg-slate-950 border border-slate-800 p-4 rounded-2xl text-center">
                    <p class="text-[11px] font-bold text-amber-400 uppercase">VOUCHERS SOLD</p>
                    <h3 class="text-2xl font-black text-amber-400 mt-1"><?= $used_vouchers ?> / <?= $total_vouchers ?></h3>
                </div>
                <div class="bg-slate-950 border border-slate-800 p-4 rounded-2xl text-center">
                    <p class="text-[11px] font-bold text-cyan-400 uppercase">PACKAGES</p>
                    <h3 class="text-2xl font-black text-cyan-400 mt-1"><?= $total_packages ?></h3>
                </div>
            </div>

            <?php if($inspect_router): ?>
            <div class="bg-slate-950 border border-orange-500/50 rounded-2xl shadow p-5 space-y-4">
                <div class="flex justify-between items-center border-b border-slate-800 pb-3">
                    <div>
                        <h2 class="text-base font-black text-white">🛜 Inspecting Router: <span class="text-orange-500"><?= htmlspecialchars($inspect_router['name']) ?></span> (<?= $inspect_router['ip_address'] ?>)</h2>
                        <p class="text-xs text-slate-400">Total Registered Users: <?= count($inspect_users) ?> | Currently Active: <?= count($inspect_active) ?></p>
                    </div>
                    <a href="index.php" class="bg-slate-800 hover:bg-slate-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold">← Back to All Routers</a>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 overflow-hidden">
                        <h3 class="text-xs font-bold text-emerald-400 uppercase mb-3">🟢 Live Active Connected Users</h3>
                        <div class="overflow-x-auto max-h-64 overflow-y-auto">
                            <table class="w-full text-left text-xs text-slate-300">
                                <thead class="bg-slate-950 text-[10px] uppercase font-bold text-slate-400 sticky top-0">
                                    <tr>
                                        <th class="p-2.5">Username</th>
                                        <th class="p-2.5">IP Address</th>
                                        <th class="p-2.5">Uptime</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800">
                                    <?php if(count($inspect_active) > 0): foreach($inspect_active as $act): ?>
                                    <tr>
                                        <td class="p-2.5 font-bold text-white"><?= htmlspecialchars($act['name'] ?? '-') ?></td>
                                        <td class="p-2.5 font-mono text-cyan-400"><?= htmlspecialchars($act['address'] ?? '-') ?></td>
                                        <td class="p-2.5 text-amber-400"><?= htmlspecialchars($act['uptime'] ?? '-') ?></td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                    <tr><td colspan="3" class="p-4 text-center text-slate-500">No active users currently running.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 overflow-hidden">
                        <h3 class="text-xs font-bold text-indigo-400 uppercase mb-3">👥 All Hotspot Users in Router</h3>
                        <div class="overflow-x-auto max-h-64 overflow-y-auto">
                            <table class="w-full text-left text-xs text-slate-300">
                                <thead class="bg-slate-950 text-[10px] uppercase font-bold text-slate-400 sticky top-0">
                                    <tr>
                                        <th class="p-2.5">Username</th>
                                        <th class="p-2.5">Password</th>
                                        <th class="p-2.5">Profile</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800">
                                    <?php if(count($inspect_users) > 0): foreach($inspect_users as $usr): ?>
                                    <tr>
                                        <td class="p-2.5 font-bold text-white"><?= htmlspecialchars($usr['name'] ?? '-') ?></td>
                                        <td class="p-2.5 font-mono text-emerald-400"><?= htmlspecialchars($usr['password'] ?? '-') ?></td>
                                        <td class="p-2.5 text-slate-400"><?= htmlspecialchars($usr['profile'] ?? 'default') ?></td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                    <tr><td colspan="3" class="p-4 text-center text-slate-500">No users found in this router.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if($_SESSION['role'] === 'admin'): ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-slate-950 border border-slate-800 p-5 rounded-2xl shadow">
                    <h2 class="text-xs font-bold text-white mb-4 border-b border-slate-800 pb-2 uppercase">🛜 Add Cloud Router (API)</h2>
                    <form method="POST" class="space-y-3">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 mb-1 uppercase">Router Name</label>
                            <input type="text" name="router_name" required placeholder="Tower-1" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 mb-1 uppercase">Cloud IP / DNS</label>
                            <input type="text" name="router_ip" required placeholder="xxx.sn.mynetname.net" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 mb-1 uppercase">API Port</label>
                                <input type="number" name="router_port" value="8728" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 mb-1 uppercase">Username</label>
                                <input type="text" name="router_user" required value="admin" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 mb-1 uppercase">Password</label>
                            <input type="password" name="router_pass" placeholder="Password" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white">
                        </div>
                        <button type="submit" name="add_router" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2.5 rounded-lg text-xs transition">Save Router</button>
                    </form>
                </div>

                <div class="bg-slate-950 border border-slate-800 p-5 rounded-2xl shadow">
                    <h2 class="text-xs font-bold text-white mb-4 border-b border-slate-800 pb-2 uppercase">🎟️ Generate Vouchers & Push</h2>
                    <form method="POST" class="space-y-3">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 mb-1 uppercase">Select Target Routers (Multiple)</label>
                            <div class="bg-slate-900 border border-slate-800 rounded-lg p-2.5 max-h-32 overflow-y-auto space-y-1.5">
                                <?php if(count($routers) > 0): foreach($routers as $rt): ?>
                                    <label class="flex items-center space-x-2 text-xs text-slate-300 cursor-pointer">
                                        <input type="checkbox" name="router_ids[]" value="<?= $rt['id'] ?>" class="rounded bg-slate-950 border-slate-800 text-orange-600 focus:ring-0">
                                        <span><?= htmlspecialchars($rt['name']) ?></span>
                                    </label>
                                <?php endforeach; else: ?>
                                    <p class="text-[11px] text-slate-500">No routers added yet!</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 mb-1 uppercase">Select Package</label>
                            <select name="package_id" required class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white">
                                <option value="">-- Choose Package --</option>
                                <?php foreach($packages as $pkg): ?>
                                    <option value="<?= $pkg['id'] ?>"><?= htmlspecialchars($pkg['package_name']) ?> (<?= $pkg['price'] ?>৳)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 mb-1 uppercase">Quantity</label>
                            <input type="number" name="quantity" required value="10" min="1" max="100" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white">
                        </div>
                        <button type="submit" name="generate_vouchers" class="w-full bg-orange-600 hover:bg-orange-500 text-white font-bold py-2.5 rounded-lg text-xs transition">Generate & Print PDF Cards</button>
                    </form>
                </div>

                <div class="bg-slate-950 border border-slate-800 p-5 rounded-2xl shadow">
                    <h2 class="text-xs font-bold text-white mb-4 border-b border-slate-800 pb-2 uppercase">📦 Create Package</h2>
                    <form method="POST" class="space-y-3">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 mb-1 uppercase">Package Name</label>
                            <input type="text" name="package_name" required placeholder="10 Mbps - 30 Days" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 mb-1 uppercase">Price (৳)</label>
                            <input type="number" step="0.01" name="price" required placeholder="500" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 mb-1 uppercase">Validity</label>
                            <input type="text" name="validity" required placeholder="30 Days" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white">
                        </div>
                        <button type="submit" name="add_package" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-2.5 rounded-lg text-xs transition mt-7">Save Package</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-slate-950 border border-slate-800 rounded-2xl shadow overflow-hidden">
                <div class="p-4 bg-slate-900 border-b border-slate-800 flex justify-between items-center">
                    <h3 class="text-xs font-bold text-slate-300 uppercase">🎟️ Recent Voucher Batches (Click 'Print Batch' to generate cards PDF)</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-400">
                        <thead class="bg-slate-900 text-[10px] uppercase font-bold text-slate-300">
                            <tr>
                                <th class="p-3">Batch ID</th>
                                <th class="p-3">Date Created</th>
                                <th class="p-3">Total Cards</th>
                                <th class="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php if(count($voucher_batches) > 0): foreach($voucher_batches as $vb): ?>
                            <tr>
                                <td class="p-3 font-mono font-bold text-orange-500"><?= htmlspecialchars($vb['batch_id']) ?></td>
                                <td class="p-3 text-xs text-slate-400"><?= htmlspecialchars($vb['cdate']) ?></td>
                                <td class="p-3 font-bold text-indigo-400"><?= $vb['total'] ?> Cards</td>
                                <td class="p-3 text-right">
                                    <a href="?print_batch=<?= $vb['batch_id'] ?>" class="bg-orange-600 hover:bg-orange-500 text-white px-3 py-1.5 rounded text-xs font-bold">🖨️ Print / PDF Cards</a>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="4" class="p-6 text-center text-slate-500 text-xs">No voucher batches generated yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-slate-950 border border-slate-800 rounded-2xl shadow overflow-hidden">
                <div class="p-4 bg-slate-900 border-b border-slate-800 flex justify-between items-center">
                    <h3 class="text-xs font-bold text-slate-300 uppercase">Connected Routers (Click 'Inspect' to view live users inside each router)</h3>
                    <span class="text-[10px] bg-emerald-950 text-emerald-400 px-2.5 py-1 rounded border border-emerald-800 font-bold">Multi-Router API Sync Active</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-400">
                        <thead class="bg-slate-900 text-[10px] uppercase font-bold text-slate-300">
                            <tr>
                                <th class="p-3">Router Name & IP</th>
                                <th class="p-3">Status</th>
                                <th class="p-3">Total Users</th>
                                <th class="p-3">Live Active</th>
                                <th class="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php if(count($routers) > 0): foreach($routers as $r): ?>
                            <tr>
                                <td class="p-3">
                                    <div class="font-bold text-white"><?= htmlspecialchars($r['name']) ?></div>
                                    <div class="text-emerald-400 font-mono text-xs"><?= htmlspecialchars($r['ip_address']) ?></div>
                                </td>
                                <td class="p-3">
                                    <?php if($r['live_status'] === 'Online'): ?>
                                        <span class="bg-emerald-950 text-emerald-400 text-[10px] px-2.5 py-1 rounded-full font-bold border border-emerald-800">Online</span>
                                    <?php else: ?>
                                        <span class="bg-red-950 text-red-400 text-[10px] px-2.5 py-1 rounded-full font-bold border border-red-800">Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 font-bold text-indigo-400"><?= $r['total_users'] ?> Users</td>
                                <td class="p-3 font-bold text-emerald-400"><?= $r['active_count'] ?> Active</td>
                                <td class="p-3 text-right space-x-2">
                                    <a href="?inspect=<?= $r['id'] ?>" class="bg-orange-600 hover:bg-orange-500 text-white px-3 py-1.5 rounded text-xs font-bold">🔍 Inspect Router</a>
                                    <?php if($_SESSION['role'] === 'admin'): ?>
                                    <a href="?delete_router=<?= $r['id'] ?>" onclick="return confirm('Delete this router?')" class="bg-red-950 hover:bg-red-900 text-red-300 px-2.5 py-1.5 rounded text-xs font-bold border border-red-800">Delete</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="5" class="p-6 text-center text-slate-500 text-xs">No routers added yet. Add a router above.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
