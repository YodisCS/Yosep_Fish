<?php
// ============================================================
// YOSEPFISH — Admin Dashboard
// Koneksi DB diambil dari config terpisah (db.php)
// ============================================================
session_start();

// ── Auth Guard ──────────────────────────────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// ── Logout ──────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// ── Koneksi dari file terpisah ───────────────────────────────
require_once 'config/db.php'; // $db = PDO instance dari db.php

// Pastikan skema tabel produk kompatibel dengan dashboard
try {
    $columns = $db->query("SHOW COLUMNS FROM produk")->fetchAll(PDO::FETCH_COLUMN);
    if ($columns) {
        $have = array_flip($columns);
        if (!isset($have['kode_produk'])) {
            $db->exec("ALTER TABLE produk ADD COLUMN kode_produk VARCHAR(50) NOT NULL DEFAULT '' AFTER id");
        }
        if (!isset($have['stok_minimum'])) {
            $db->exec("ALTER TABLE produk ADD COLUMN stok_minimum INT NOT NULL DEFAULT 1 AFTER stok");
        }
        if (!isset($have['status'])) {
            $db->exec("ALTER TABLE produk ADD COLUMN status ENUM('ready','habis','tidak_aktif') NOT NULL DEFAULT 'ready' AFTER stok_minimum");
        }
        if (!isset($have['created_at'])) {
            $db->exec("ALTER TABLE produk ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER deskripsi");
        }
    }
} catch (PDOException $e) {
    // Jika tabel belum ada atau terjadi kesalahan, biarkan dashboard tetap berjalan dan tampilkan error saat API dipanggil.
}

// ── Helper: Upload Gambar ────────────────────────────────────
function uploadGambar($file_key, $gambar_lama = '') {
    if (empty($_FILES[$file_key]['name'])) return $gambar_lama;

    $ext_allowed = ['jpg','jpeg','png','webp','gif'];
    $ext = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ext_allowed)) {
        echo json_encode(['success'=>false,'msg'=>'Format gambar tidak valid (jpg/png/webp/gif)']); exit;
    }
    if ($_FILES[$file_key]['size'] > 3 * 1024 * 1024) {
        echo json_encode(['success'=>false,'msg'=>'Ukuran gambar maksimal 3MB']); exit;
    }

    $upload_dir = 'assets/img/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $filename = uniqid('fish_') . '.' . $ext;
    if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $upload_dir . $filename)) {
        // Hapus gambar lama jika ada dan bukan URL eksternal
        if ($gambar_lama && !str_starts_with($gambar_lama, 'http') && file_exists($gambar_lama)) {
            unlink($gambar_lama);
        }
        return $upload_dir . $filename;
    }
    return $gambar_lama;
}

// ── AJAX Handler ────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];

    // ── DASHBOARD STATS ─────────────────────────────────────
    if ($action === 'get_dashboard') {
        $stats = $db->query("
            SELECT
                (SELECT COUNT(*) FROM pesanan)                                               AS total_pesanan,
                (SELECT COUNT(*) FROM pesanan WHERE status_pembelian = 'selesai')            AS pesanan_selesai,
                (SELECT COUNT(*) FROM pesanan WHERE status_pembelian = 'menunggu_pembayaran') AS menunggu_bayar,
                (SELECT COUNT(*) FROM pesanan WHERE status_pembelian = 'dikirim')            AS sedang_dikirim,
                (SELECT COALESCE(SUM(harga_total),0) FROM pesanan WHERE status_pembelian = 'selesai') AS total_revenue,
                (SELECT COUNT(*) FROM produk)                                                AS total_produk,
                (SELECT COUNT(*) FROM produk WHERE status = 'habis')                        AS produk_habis,
                (SELECT COALESCE(SUM(stok),0) FROM produk WHERE status != 'tidak_aktif')    AS total_stok
        ")->fetch();

        $chartRaw = $db->query("
            SELECT DATE(created_at) AS tgl, SUM(harga_total) AS revenue, COUNT(*) AS jumlah
            FROM pesanan
            WHERE status_pembelian = 'selesai'
              AND created_at >= CURDATE() - INTERVAL 6 DAY
            GROUP BY DATE(created_at)
            ORDER BY tgl ASC
        ")->fetchAll();

        $chart_map = [];
        foreach ($chartRaw as $row) $chart_map[$row['tgl']] = ['rev' => (int)$row['revenue'], 'jml' => (int)$row['jumlah']];
        $labels = $revenue = $orders = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $labels[]  = date('d/m', strtotime($d));
            $revenue[] = $chart_map[$d]['rev'] ?? 0;
            $orders[]  = $chart_map[$d]['jml'] ?? 0;
        }

        $statusRaw = $db->query("SELECT status_pembelian, COUNT(*) AS jml FROM pesanan GROUP BY status_pembelian")->fetchAll();
        $statusData = [];
        foreach ($statusRaw as $r) $statusData[$r['status_pembelian']] = (int)$r['jml'];

        $recent = $db->query("
            SELECT p.invoice, p.nama_pemesan, p.harga_total, p.status_pembelian,
                   p.created_at, p.metode_bayar,
                   GROUP_CONCAT(pi.nama_ikan ORDER BY pi.id SEPARATOR ', ') AS ikan
            FROM pesanan p
            LEFT JOIN pesanan_item pi ON pi.pesanan_id = p.id
            GROUP BY p.id
            ORDER BY p.created_at DESC LIMIT 8
        ")->fetchAll();

        $terlaris = $db->query("
            SELECT pi.nama_ikan, SUM(pi.qty) AS terjual, SUM(pi.subtotal_item) AS revenue
            FROM pesanan_item pi
            INNER JOIN pesanan p ON p.id = pi.pesanan_id AND p.status_pembelian = 'selesai'
            GROUP BY pi.produk_id, pi.nama_ikan
            ORDER BY terjual DESC LIMIT 5
        ")->fetchAll();

        echo json_encode([
            'stats'       => $stats,
            'chart'       => ['labels' => $labels, 'revenue' => $revenue, 'orders' => $orders],
            'status_dist' => $statusData,
            'recent'      => $recent,
            'terlaris'    => $terlaris,
        ]);
    }

    // ── PESANAN ─────────────────────────────────────────────
    elseif ($action === 'get_orders') {
        $status = trim($_GET['status'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $sql    = "
            SELECT p.id, p.invoice, p.nama_pemesan, p.nomor_hp, p.alamat_rumah,
                   p.ekspedisi, p.metode_bayar, p.jumlah_produk,
                   p.subtotal, p.ongkir, p.diskon, p.harga_total,
                   p.status_pembelian, p.no_resi, p.created_at,
                   GROUP_CONCAT(pi.nama_ikan, ' x', pi.qty ORDER BY pi.id SEPARATOR ' | ') AS daftar_ikan
            FROM pesanan p
            LEFT JOIN pesanan_item pi ON pi.pesanan_id = p.id
            WHERE 1=1
        ";
        $params = [];
        if ($status) { $sql .= " AND p.status_pembelian = :status"; $params[':status'] = $status; }
        if ($search) { $sql .= " AND (p.invoice LIKE :s OR p.nama_pemesan LIKE :s OR p.nomor_hp LIKE :s)"; $params[':s'] = "%$search%"; }
        $sql .= " GROUP BY p.id ORDER BY p.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    }

    // ── DETAIL PESANAN ───────────────────────────────────────
    elseif ($action === 'get_order_detail') {
        $invoice = trim($_GET['invoice'] ?? '');
        if (!$invoice) { echo json_encode(['success'=>false]); exit; }
        
        $stmt = $db->prepare("SELECT * FROM pesanan WHERE invoice = ?");
        $stmt->execute([$invoice]);
        $pesanan = $stmt->fetch();
        if (!$pesanan) { echo json_encode(['success'=>false]); exit; }
        
        $stmtItems = $db->prepare("SELECT * FROM pesanan_item WHERE pesanan_id = ? ORDER BY id");
        $stmtItems->execute([$pesanan['id']]);
        $items = $stmtItems->fetchAll();
        
        echo json_encode([
            'success' => true,
            'pesanan' => $pesanan,
            'items' => $items
        ]);
    }

    // ── UPDATE STATUS PESANAN ────────────────────────────────
    elseif ($action === 'update_order_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $allowed = ['menunggu_pembayaran','pembayaran_dikonfirmasi','diproses','dikirim','selesai','dibatalkan','refund'];
        if (!in_array($d['status'], $allowed)) { echo json_encode(['success'=>false,'msg'=>'Status tidak valid']); exit; }
        if ($d['status'] === 'dikirim' && !empty($d['resi'])) {
            $db->prepare("UPDATE pesanan SET status_pembelian = ?, no_resi = ?, tgl_kirim = NOW() WHERE invoice = ?")->execute([$d['status'], $d['resi'], $d['invoice']]);
        } elseif ($d['status'] === 'selesai') {
            $db->prepare("UPDATE pesanan SET status_pembelian = ?, tgl_selesai = NOW() WHERE invoice = ?")->execute([$d['status'], $d['invoice']]);
        } elseif ($d['status'] === 'pembayaran_dikonfirmasi') {
            $db->prepare("UPDATE pesanan SET status_pembelian = ?, tgl_bayar = NOW() WHERE invoice = ?")->execute([$d['status'], $d['invoice']]);
        } else {
            $db->prepare("UPDATE pesanan SET status_pembelian = ? WHERE invoice = ?")->execute([$d['status'], $d['invoice']]);
        }
        echo json_encode(['success' => true]);
    }

    // ── PRODUK LIST ─────────────────────────────────────────
    elseif ($action === 'get_products') {
        $search = trim($_GET['search'] ?? '');
        $kat    = trim($_GET['kategori'] ?? '');
        $sql    = "SELECT * FROM produk WHERE 1=1";
        $params = [];
        if ($search) { $sql .= " AND (nama LIKE :s OR warna LIKE :s OR kode_produk LIKE :s)"; $params[':s'] = "%$search%"; }
        if ($kat)    { $sql .= " AND kategori = :kat"; $params[':kat'] = $kat; }
        $sql .= " ORDER BY status ASC, stok DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    }

    // ── TAMBAH PRODUK ────────────────────────────────────────
    elseif ($action === 'add_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['nama']) || empty($_POST['kategori']) || empty($_POST['harga'])) {
            echo json_encode(['success'=>false,'msg'=>'Data tidak lengkap']); exit;
        }

        $gambar_url = uploadGambar('gambar');

        $prefix_map = ['Plakat'=>'PLK','Halfmoon'=>'HLF','Crowntail'=>'CWN','Giant'=>'GNT','Fancy'=>'FNC','Lainnya'=>'LNY'];
        $prefix = $prefix_map[$_POST['kategori']] ?? 'PRD';
        $last = $db->query("SELECT kode_produk FROM produk WHERE kode_produk LIKE 'YF-$prefix-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $num = $last ? (intval(substr($last, -3)) + 1) : 1;
        $kode = 'YF-' . $prefix . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("
            INSERT INTO produk (kode_produk, nama, kategori, warna, harga, stok, stok_minimum, deskripsi, gambar_url, status)
            VALUES (:kode, :nama, :kat, :warna, :harga, :stok, :min_stok, :desk, :img, :status)
        ");
        $stmt->execute([
            ':kode'    => $kode,
            ':nama'    => $_POST['nama'],
            ':kat'     => $_POST['kategori'],
            ':warna'   => $_POST['warna'] ?? '-',
            ':harga'   => (int)$_POST['harga'],
            ':stok'    => (int)($_POST['stok'] ?? 0),
            ':min_stok'=> (int)($_POST['stok_minimum'] ?? 1),
            ':desk'    => $_POST['deskripsi'] ?? '',
            ':img'     => $gambar_url,
            ':status'  => (int)($_POST['stok'] ?? 0) > 0 ? 'ready' : 'habis',
        ]);
        echo json_encode(['success' => true, 'kode' => $kode]);
    }

    // ── UPDATE PRODUK ────────────────────────────────────────
    elseif ($action === 'update_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $gambar_url = uploadGambar('gambar', $_POST['gambar_url_lama'] ?? '');

        $stmt = $db->prepare("
            UPDATE produk SET nama=:nama, kategori=:kat, warna=:warna, harga=:harga,
                              stok=:stok, stok_minimum=:min_stok, deskripsi=:desk,
                              gambar_url=:img, status=:status
            WHERE id = :id
        ");
        $stmt->execute([
            ':nama'    => $_POST['nama'],
            ':kat'     => $_POST['kategori'],
            ':warna'   => $_POST['warna'],
            ':harga'   => (int)$_POST['harga'],
            ':stok'    => (int)$_POST['stok'],
            ':min_stok'=> (int)$_POST['stok_minimum'],
            ':desk'    => $_POST['deskripsi'],
            ':img'     => $gambar_url,
            ':status'  => $_POST['status'],
            ':id'      => (int)$_POST['id'],
        ]);
        echo json_encode(['success' => true]);
    }

    // ── TAMBAH STOK ──────────────────────────────────────────
    elseif ($action === 'tambah_stok' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $stmt = $db->prepare("
            UPDATE produk
            SET stok = stok + :qty,
                status = CASE WHEN (stok + :qty2) > 0 AND status = 'habis' THEN 'ready' ELSE status END
            WHERE id = :id
        ");
        $stmt->execute([':qty' => (int)$d['qty'], ':qty2' => (int)$d['qty'], ':id' => (int)$d['id']]);
        $row = $db->prepare("SELECT stok, status FROM produk WHERE id = ?");
        $row->execute([(int)$d['id']]);
        echo json_encode(['success' => true, 'data' => $row->fetch()]);
    }

    // ── HAPUS PRODUK ─────────────────────────────────────────
    elseif ($action === 'delete_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $db->prepare("UPDATE produk SET status = 'tidak_aktif' WHERE id = ?")->execute([(int)$d['id']]);
        echo json_encode(['success' => true]);
    }

    // ── LAPORAN KEUANGAN ─────────────────────────────────────
    elseif ($action === 'get_reports') {
        $bulan = $_GET['bulan'] ?? date('Y-m');
        $thn   = substr($bulan, 0, 4);
        $bln   = substr($bulan, 5, 2);

        $summary = $db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status_pembelian='selesai' THEN harga_total END), 0) AS total_revenue,
                COALESCE(SUM(CASE WHEN status_pembelian='selesai' THEN subtotal END), 0)    AS total_subtotal,
                COALESCE(SUM(CASE WHEN status_pembelian='selesai' THEN ongkir END), 0)      AS total_ongkir,
                COALESCE(SUM(CASE WHEN status_pembelian='selesai' THEN diskon END), 0)      AS total_diskon,
                COUNT(*) AS total_pesanan,
                SUM(CASE WHEN status_pembelian='selesai' THEN 1 ELSE 0 END)    AS selesai,
                SUM(CASE WHEN status_pembelian='dibatalkan' THEN 1 ELSE 0 END) AS batal,
                COALESCE(SUM(CASE WHEN status_pembelian='selesai' THEN jumlah_produk END), 0) AS total_item
            FROM pesanan
            WHERE YEAR(created_at)=:thn AND MONTH(created_at)=:bln
        ");
        $summary->execute([':thn' => $thn, ':bln' => $bln]);
        $sum = $summary->fetch();

        $harian = $db->prepare("
            SELECT
                DATE(created_at) AS tgl,
                COUNT(*) AS pesanan,
                SUM(CASE WHEN status_pembelian='selesai' THEN jumlah_produk ELSE 0 END)   AS item_terjual,
                COALESCE(SUM(CASE WHEN status_pembelian='selesai' THEN harga_total END),0) AS revenue
            FROM pesanan
            WHERE YEAR(created_at)=:thn AND MONTH(created_at)=:bln
            GROUP BY DATE(created_at)
            ORDER BY tgl ASC
        ");
        $harian->execute([':thn' => $thn, ':bln' => $bln]);

        $terlaris = $db->prepare("
            SELECT pi.nama_ikan, pi.kategori_ikan,
                   SUM(pi.qty)           AS terjual,
                   SUM(pi.subtotal_item) AS revenue
            FROM pesanan_item pi
            INNER JOIN pesanan p ON p.id = pi.pesanan_id
            WHERE p.status_pembelian = 'selesai'
              AND YEAR(p.created_at)=:thn AND MONTH(p.created_at)=:bln
            GROUP BY pi.produk_id, pi.nama_ikan, pi.kategori_ikan
            ORDER BY terjual DESC LIMIT 10
        ");
        $terlaris->execute([':thn' => $thn, ':bln' => $bln]);

        $metode = $db->prepare("
            SELECT metode_bayar, COUNT(*) AS jml,
                   SUM(CASE WHEN status_pembelian='selesai' THEN harga_total ELSE 0 END) AS revenue
            FROM pesanan
            WHERE YEAR(created_at)=:thn AND MONTH(created_at)=:bln
            GROUP BY metode_bayar
        ");
        $metode->execute([':thn' => $thn, ':bln' => $bln]);

        echo json_encode([
            'summary'  => $sum,
            'harian'   => $harian->fetchAll(),
            'terlaris' => $terlaris->fetchAll(),
            'metode'   => $metode->fetchAll(),
        ]);
    }

    exit;
}

$admin_name = $_SESSION['nama_depan'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — YosepFish</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
/* ============================================================
   ROOT & RESET
   ============================================================ */
:root {
  --bg: #0D1117;
  --bg2: #161B22;
  --bg3: #1C2333;
  --bg4: #21262D;
  --border: rgba(255,255,255,0.07);
  --border2: rgba(255,255,255,0.12);
  --accent: #2F81F7;
  --accent2: #1F6FEB;
  --accent-glow: rgba(47,129,247,0.20);
  --green: #3FB950;
  --green-bg: rgba(63,185,80,0.12);
  --orange: #F78166;
  --orange-bg: rgba(247,129,102,0.12);
  --yellow: #E3B341;
  --yellow-bg: rgba(227,179,65,0.12);
  --red: #F85149;
  --red-bg: rgba(248,81,73,0.12);
  --cyan: #58A6FF;
  --text: #E6EDF3;
  --text2: #8B949E;
  --text3: #6E7681;
  --sidebar-w: 240px;
  --font: 'Plus Jakarta Sans', sans-serif;
  --mono: 'JetBrains Mono', monospace;
  --radius: 12px;
  --radius-sm: 8px;
  --transition: 0.18s cubic-bezier(0.4,0,0.2,1);
}

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { font-size: 14px; }
body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; overflow-x: hidden; }
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: var(--bg2); }
::-webkit-scrollbar-thumb { background: var(--bg4); border-radius: 99px; }
::-webkit-scrollbar-thumb:hover { background: var(--accent); }
button { cursor: pointer; border: none; background: none; font-family: var(--font); }
input, textarea, select { font-family: var(--font); }
a { text-decoration: none; color: inherit; }
img { display: block; max-width: 100%; }

/* ============================================================
   LAYOUT
   ============================================================ */
.app { display: flex; min-height: 100vh; }

/* ── SIDEBAR ─────────────────────────────────────────────── */
.sidebar {
  width: var(--sidebar-w);
  background: var(--bg2);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  position: fixed; top: 0; left: 0; bottom: 0;
  z-index: 50;
  transition: transform var(--transition);
}
.sidebar-logo {
  padding: 20px 20px 16px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 10px;
}
.logo-mark {
  width: 34px; height: 34px;
  background: var(--accent);
  border-radius: 9px;
  display: grid; place-items: center;
  font-size: 16px; flex-shrink: 0;
}
.logo-text { font-size: 15px; font-weight: 800; letter-spacing: -0.3px; }
.logo-text span { color: var(--accent); }

.sidebar-nav { padding: 12px 10px; flex: 1; overflow-y: auto; }
.nav-section { margin-bottom: 20px; }
.nav-label {
  font-size: 10px; font-weight: 700;
  letter-spacing: 1.4px; text-transform: uppercase;
  color: var(--text3); padding: 4px 10px 8px;
}
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 10px;
  border-radius: var(--radius-sm);
  font-size: 13.5px; font-weight: 500;
  color: var(--text2);
  cursor: pointer;
  transition: var(--transition);
  margin-bottom: 2px;
  position: relative;
}
.nav-item:hover { background: var(--bg3); color: var(--text); }
.nav-item.active { background: var(--accent-glow); color: var(--accent); font-weight: 600; }
.nav-item.active::before {
  content: '';
  position: absolute; left: 0; top: 4px; bottom: 4px;
  width: 3px; border-radius: 0 4px 4px 0;
  background: var(--accent);
}
.nav-item svg { flex-shrink: 0; opacity: 0.8; }
.nav-item.active svg { opacity: 1; }
.nav-badge {
  margin-left: auto;
  background: var(--red);
  color: white; font-size: 10px; font-weight: 700;
  padding: 1px 6px; border-radius: 99px;
  min-width: 18px; text-align: center;
}

.sidebar-footer {
  padding: 12px 10px;
  border-top: 1px solid var(--border);
}
.admin-info {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  background: var(--bg3);
}
.admin-avatar {
  width: 32px; height: 32px;
  background: linear-gradient(135deg, var(--accent), #7C3AED);
  border-radius: 8px;
  display: grid; place-items: center;
  font-size: 14px; font-weight: 700;
  color: white; flex-shrink: 0;
}
.admin-name { font-size: 13px; font-weight: 600; flex: 1; }
.admin-role { font-size: 11px; color: var(--text3); }
.logout-btn {
  color: var(--text3); padding: 4px;
  border-radius: 6px; transition: var(--transition);
}
.logout-btn:hover { color: var(--red); background: var(--red-bg); }

/* ── MAIN ────────────────────────────────────────────────── */
.main {
  margin-left: var(--sidebar-w);
  flex: 1;
  min-height: 100vh;
  display: flex; flex-direction: column;
}
.topbar {
  position: sticky; top: 0; z-index: 40;
  background: rgba(13,17,23,0.85);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--border);
  padding: 0 28px;
  height: 56px;
  display: flex; align-items: center; justify-content: space-between;
}
.topbar-title {
  font-size: 16px; font-weight: 700;
  display: flex; align-items: center; gap: 8px;
}
.topbar-actions { display: flex; align-items: center; gap: 10px; }
.topbar-time {
  font-family: var(--mono);
  font-size: 12px; color: var(--text3);
  background: var(--bg3);
  padding: 4px 10px; border-radius: 6px;
}

.content { padding: 28px; flex: 1; }

/* ── PAGES ───────────────────────────────────────────────── */
.page { display: none; }
.page.active { display: block; animation: fadeUp 0.3s ease; }
@keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }

/* ============================================================
   COMPONENTS
   ============================================================ */

/* Cards */
.card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  transition: border-color var(--transition);
}
.card:hover { border-color: var(--border2); }
.card-header {
  padding: 18px 20px 14px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.card-title { font-size: 14px; font-weight: 700; }
.card-body { padding: 20px; }

/* Stat cards */
.stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 24px; }
.stat-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 18px 20px;
  display: flex; flex-direction: column; gap: 10px;
  transition: var(--transition);
  position: relative; overflow: hidden;
}
.stat-card::before {
  content: '';
  position: absolute; bottom: 0; left: 0; right: 0; height: 2px;
}
.stat-card.blue::before   { background: var(--accent); }
.stat-card.green::before  { background: var(--green); }
.stat-card.orange::before { background: var(--orange); }
.stat-card.yellow::before { background: var(--yellow); }

.stat-icon {
  width: 36px; height: 36px;
  border-radius: 9px;
  display: grid; place-items: center;
  font-size: 16px;
}
.stat-icon.blue   { background: var(--accent-glow); }
.stat-icon.green  { background: var(--green-bg); }
.stat-icon.orange { background: var(--orange-bg); }
.stat-icon.yellow { background: var(--yellow-bg); }

.stat-label { font-size: 12px; font-weight: 500; color: var(--text2); }
.stat-value { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; font-family: var(--mono); }
.stat-meta  { font-size: 11px; color: var(--text3); }
.stat-meta b { color: var(--green); font-weight: 600; }

/* Grid layouts */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid-3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 16px; }
.grid-65 { display: grid; grid-template-columns: 1.6fr 1fr; gap: 16px; }

/* Tables */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th {
  padding: 10px 14px;
  text-align: left;
  font-size: 11px; font-weight: 700;
  letter-spacing: 0.8px; text-transform: uppercase;
  color: var(--text3);
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
tbody td {
  padding: 11px 14px;
  border-bottom: 1px solid var(--border);
  color: var(--text);
  vertical-align: middle;
}
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: rgba(255,255,255,0.02); }
.mono { font-family: var(--mono); font-size: 12px; color: var(--cyan); }

/* Badges/Pills */
.pill {
  display: inline-flex; align-items: center;
  padding: 3px 9px;
  border-radius: 99px;
  font-size: 11px; font-weight: 600;
  white-space: nowrap;
}
.pill-blue   { background: var(--accent-glow); color: var(--cyan); }
.pill-green  { background: var(--green-bg);    color: var(--green); }
.pill-orange { background: var(--orange-bg);   color: var(--orange); }
.pill-yellow { background: var(--yellow-bg);   color: var(--yellow); }
.pill-red    { background: var(--red-bg);      color: var(--red); }
.pill-gray   { background: rgba(255,255,255,0.07); color: var(--text2); }

/* Buttons */
.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 16px;
  border-radius: var(--radius-sm);
  font-size: 13px; font-weight: 600;
  transition: var(--transition);
  white-space: nowrap;
}
.btn-primary { background: var(--accent); color: white; }
.btn-primary:hover { background: var(--accent2); box-shadow: 0 4px 14px var(--accent-glow); }
.btn-ghost { background: var(--bg3); color: var(--text2); border: 1px solid var(--border); }
.btn-ghost:hover { color: var(--text); border-color: var(--border2); }
.btn-danger { background: var(--red-bg); color: var(--red); }
.btn-danger:hover { background: var(--red); color: white; }
.btn-success { background: var(--green-bg); color: var(--green); }
.btn-success:hover { background: var(--green); color: white; }
.btn-sm { padding: 5px 10px; font-size: 12px; }

/* Form controls */
.form-group { margin-bottom: 14px; }
.form-label { display: block; font-size: 12px; font-weight: 600; color: var(--text2); margin-bottom: 6px; }
.form-control {
  width: 100%;
  padding: 9px 12px;
  background: var(--bg3);
  border: 1.5px solid var(--border2);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-size: 13px;
  outline: none;
  transition: var(--transition);
}
.form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
.form-control::placeholder { color: var(--text3); }
textarea.form-control { resize: vertical; min-height: 80px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* Search bar */
.search-wrap {
  position: relative; display: inline-flex; align-items: center;
}
.search-wrap svg { position: absolute; left: 10px; color: var(--text3); pointer-events: none; }
.search-input {
  padding: 7px 12px 7px 34px;
  background: var(--bg3);
  border: 1.5px solid var(--border);
  border-radius: var(--radius-sm);
  color: var(--text); font-size: 13px; outline: none;
  transition: var(--transition); width: 220px;
}
.search-input:focus { border-color: var(--accent); width: 280px; }
.search-input::placeholder { color: var(--text3); }

/* Filter bar */
.filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }

/* ── UPLOAD AREA ───────────────────────────────────────── */
.upload-area {
  border: 2px dashed var(--border2);
  border-radius: var(--radius);
  padding: 20px;
  text-align: center;
  cursor: pointer;
  transition: all var(--transition);
  background: var(--bg3);
  position: relative;
  min-height: 130px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  overflow: hidden;
}
.upload-area.has-image {
  border-style: solid;
  border-color: var(--accent);
  background: rgba(47,129,247,0.03);
  min-height: 220px;
  padding: 12px;
}
.upload-area:hover,
.upload-area.drag-over {
  border-color: var(--accent);
  background: rgba(47,129,247,0.05);
}
.upload-placeholder-icon { font-size: 30px; line-height: 1; }
.upload-placeholder-text { font-size: 13px; font-weight: 600; color: var(--text2); }
.upload-placeholder-hint { font-size: 11px; color: var(--text3); }
.upload-preview-img {
  display: none;
  width: 100%;
  max-height: 200px;
  border-radius: 8px;
  object-fit: contain;
  background: rgba(0,0,0,0.05);
  padding: 8px;
}
.upload-area.has-image .upload-preview-img {
  display: block !important;
}
.upload-remove-btn {
  display: none;
  position: absolute;
  top: 8px; right: 8px;
  background: var(--red-bg);
  color: var(--red);
  border: none;
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
  transition: var(--transition);
  z-index: 3;
}
.upload-area.has-image .upload-remove-btn {
  display: block !important;
}
.upload-remove-btn:hover { background: var(--red); color: white; }
.upload-filename {
  display: none;
  font-size: 11px;
  color: var(--green);
  font-family: var(--mono);
  margin-top: 4px;
  word-break: break-all;
}
.upload-area.has-image .upload-filename {
  display: block !important;
}

/* ── MODAL ─────────────────────────────────────────────── */
.modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.65);
  backdrop-filter: blur(4px);
  z-index: 200;
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
  opacity: 0; pointer-events: none;
  transition: opacity 0.25s;
}
.modal-backdrop.open { opacity: 1; pointer-events: all; }
.modal {
  background: var(--bg2);
  border: 1px solid var(--border2);
  border-radius: 14px;
  width: 100%; max-width: 520px;
  max-height: 90vh; overflow-y: auto;
  transform: scale(0.96) translateY(12px);
  transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1);
  box-shadow: 0 24px 80px rgba(0,0,0,0.5);
}
.modal-backdrop.open .modal { transform: scale(1) translateY(0); }
.modal-header {
  padding: 20px 24px 16px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.modal-title { font-size: 16px; font-weight: 700; }
.modal-close {
  width: 28px; height: 28px;
  display: grid; place-items: center;
  border-radius: 7px; color: var(--text3);
  transition: var(--transition);
}
.modal-close:hover { background: var(--bg3); color: var(--text); }
.modal-body { padding: 20px 24px; }
.modal-footer {
  padding: 14px 24px 20px;
  display: flex; justify-content: flex-end; gap: 10px;
  border-top: 1px solid var(--border);
}

/* ── TOAST ─────────────────────────────────────────────── */
.toast-wrap { position: fixed; bottom: 24px; right: 24px; z-index: 999; display: flex; flex-direction: column; gap: 8px; }
.toast {
  background: var(--bg3);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  padding: 12px 16px;
  font-size: 13px; font-weight: 500;
  display: flex; align-items: center; gap: 8px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.4);
  animation: slideIn 0.3s ease;
  max-width: 320px;
}
.toast.success { border-left: 3px solid var(--green); }
.toast.error   { border-left: 3px solid var(--red); }
.toast.info    { border-left: 3px solid var(--accent); }
@keyframes slideIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:none} }

/* ── PRODUCT GRID ─────────────────────────────────────── */
.product-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(200px,1fr)); gap: 12px; }
.prod-card {
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
  transition: var(--transition);
}
.prod-card:hover { border-color: var(--border2); transform: translateY(-2px); }
.prod-img {
  width: 100%; padding-top: 70%;
  position: relative; background: var(--bg4); overflow: hidden;
}
.prod-img img { position:absolute;inset:0;width:100%;height:100%;object-fit:cover; }
.prod-img .prod-badge {
  position: absolute; top: 8px; left: 8px;
  font-size: 10px; font-weight: 700;
  padding: 2px 8px; border-radius: 5px;
  background: rgba(13,17,23,0.8); backdrop-filter: blur(4px);
}
.prod-body { padding: 12px; }
.prod-nama { font-size: 13px; font-weight: 600; margin-bottom: 2px; line-height: 1.3; }
.prod-kat  { font-size: 11px; color: var(--text3); margin-bottom: 8px; }
.prod-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; }
.prod-harga { font-family: var(--mono); font-size: 13px; font-weight: 600; color: var(--cyan); }
.prod-stok  { font-size: 11px; color: var(--text3); }
.prod-actions { display: flex; gap: 6px; margin-top: 8px; }

/* ── STATUS INDICATOR ─────────────────────────────────── */
.dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; margin-right: 5px; }
.dot-green  { background: var(--green); box-shadow: 0 0 6px var(--green); }
.dot-red    { background: var(--red); }
.dot-orange { background: var(--orange); }
.dot-yellow { background: var(--yellow); }
.dot-gray   { background: var(--text3); }

/* ── CHART AREA ───────────────────────────────────────── */
.chart-wrap { position: relative; height: 260px; }

/* ── LAPORAN ──────────────────────────────────────────── */
.report-kpi { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 20px; }
.kpi-box {
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 16px; text-align: center;
}
.kpi-val { font-size: 22px; font-weight: 800; font-family: var(--mono); margin-bottom: 4px; }
.kpi-lbl { font-size: 11px; color: var(--text3); font-weight: 500; }

/* ── MOBILE TOGGLE ────────────────────────────────────── */
.sidebar-toggle { display: none; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1100px) {
  .stats-grid { grid-template-columns: repeat(2,1fr); }
  .report-kpi { grid-template-columns: repeat(2,1fr); }
}
@media (max-width: 860px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.open { transform: translateX(0); box-shadow: 8px 0 40px rgba(0,0,0,0.4); }
  .main { margin-left: 0; }
  .sidebar-toggle {
    display: grid; place-items: center;
    width: 36px; height: 36px;
    background: var(--bg3); border-radius: var(--radius-sm);
    color: var(--text2);
  }
  .content { padding: 16px; }
  .grid-65 { grid-template-columns: 1fr; }
  .grid-2 { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
  .stats-grid { grid-template-columns: 1fr 1fr; }
  .report-kpi { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>
<div class="app">

<!-- ============================================================
     SIDEBAR
     ============================================================ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">🐟</div>
    <div>
      <div class="logo-text">YOSEP<span>FISH</span></div>
      <div style="font-size:10px;color:var(--text3);margin-top:1px">Admin Panel</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">
      <div class="nav-label">Menu</div>
      <div class="nav-item active" data-page="dashboard" onclick="goPage(this)">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Dashboard
      </div>
      <div class="nav-item" data-page="pesanan" onclick="goPage(this)">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
        Pesanan
        <span class="nav-badge" id="navBadgePesanan" style="display:none">0</span>
      </div>
      <div class="nav-item" data-page="produk" onclick="goPage(this)">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        Produk & Stok
      </div>
      <div class="nav-item" data-page="laporan" onclick="goPage(this)">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Laporan Keuangan
      </div>
    </div>
  </nav>

  <div class="sidebar-footer">
    <div class="admin-info">
      <div class="admin-avatar"><?= strtoupper(substr($admin_name,0,1)) ?></div>
      <div>
        <div class="admin-name"><?= htmlspecialchars($admin_name) ?></div>
        <div class="admin-role">Administrator</div>
      </div>
      <a href="?logout=1" class="logout-btn" title="Logout">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>
  </div>
</aside>

<!-- ============================================================
     MAIN
     ============================================================ -->
<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
      <button class="sidebar-toggle" onclick="toggleSidebar()">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="topbar-title" id="topbarTitle">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Dashboard
      </div>
    </div>
    <div class="topbar-actions">
      <div class="topbar-time" id="liveClock">—</div>
    </div>
  </div>

  <div class="content">

    <!-- ========================================================
         PAGE: DASHBOARD
         ======================================================== -->
    <div id="pageDashboard" class="page active">
      <div class="stats-grid" id="statsGrid">
        <div class="stat-card blue"><div class="stat-icon blue">💰</div><div class="stat-label">Total Revenue</div><div class="stat-value" id="s_revenue">…</div><div class="stat-meta">Pesanan selesai</div></div>
        <div class="stat-card green"><div class="stat-icon green">📦</div><div class="stat-label">Total Pesanan</div><div class="stat-value" id="s_pesanan">…</div><div class="stat-meta" id="s_selesai_meta">…</div></div>
        <div class="stat-card orange"><div class="stat-icon orange">🐟</div><div class="stat-label">Produk Aktif</div><div class="stat-value" id="s_produk">…</div><div class="stat-meta" id="s_habis_meta">…</div></div>
        <div class="stat-card yellow"><div class="stat-icon yellow">⏳</div><div class="stat-label">Menunggu Bayar</div><div class="stat-value" id="s_menunggu">…</div><div class="stat-meta">Perlu konfirmasi</div></div>
      </div>

      <div class="grid-65" style="margin-bottom:16px">
        <div class="card">
          <div class="card-header">
            <span class="card-title">Revenue 7 Hari Terakhir</span>
            <span class="pill pill-blue">Pesanan Selesai</span>
          </div>
          <div class="card-body">
            <div class="chart-wrap"><canvas id="chartRevenue"></canvas></div>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title">Status Pesanan</span></div>
          <div class="card-body">
            <div class="chart-wrap" style="height:220px"><canvas id="chartStatus"></canvas></div>
          </div>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-header">
            <span class="card-title">Pesanan Terbaru</span>
            <button class="btn btn-ghost btn-sm" onclick="goPageById('pesanan')">Lihat Semua</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Invoice</th><th>Pemesan</th><th>Total</th><th>Status</th></tr></thead>
              <tbody id="recentOrdersTbl"><tr><td colspan="4" style="text-align:center;color:var(--text3);padding:20px">Memuat…</td></tr></tbody>
            </table>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title">Produk Terlaris</span></div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Nama Ikan</th><th>Terjual</th><th>Revenue</th></tr></thead>
              <tbody id="topProdukTbl"><tr><td colspan="3" style="text-align:center;color:var(--text3);padding:20px">Memuat…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ========================================================
         PAGE: PESANAN
         ======================================================== -->
    <div id="pagePesanan" class="page">
      <div class="filter-bar">
        <div class="search-wrap">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input class="search-input" id="searchPesanan" placeholder="Invoice / nama / HP…" oninput="debounce(loadPesanan,400)">
        </div>
        <select class="form-control" id="filterStatus" onchange="loadPesanan()" style="width:auto">
          <option value="">Semua Status</option>
          <option value="menunggu_pembayaran">Menunggu Bayar</option>
          <option value="pembayaran_dikonfirmasi">Dikonfirmasi</option>
          <option value="diproses">Diproses</option>
          <option value="dikirim">Dikirim</option>
          <option value="selesai">Selesai</option>
          <option value="dibatalkan">Dibatalkan</option>
        </select>
        <span id="pesananCount" style="font-size:12px;color:var(--text3)"></span>
      </div>
      <div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Invoice</th><th>Pemesan</th><th>Ikan</th>
                <th>Ekspedisi</th><th>Bayar Via</th><th>Qty</th>
                <th>Total</th><th>Status</th><th>Aksi</th>
              </tr>
            </thead>
            <tbody id="pesananTbl">
              <tr><td colspan="9" style="text-align:center;color:var(--text3);padding:30px">Memuat data…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ========================================================
         PAGE: PRODUK & STOK
         ======================================================== -->
    <div id="pageProduk" class="page">
      <div class="filter-bar">
        <button class="btn btn-primary" onclick="openModalProduk()">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Produk
        </button>
        <div class="search-wrap">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input class="search-input" id="searchProduk" placeholder="Cari nama / kategori…" oninput="debounce(loadProduk,400)">
        </div>
        <select class="form-control" id="filterKat" onchange="loadProduk()" style="width:auto">
          <option value="">Semua Kategori</option>
          <option>Plakat</option><option>Halfmoon</option><option>Crowntail</option>
          <option>Giant</option><option>Fancy</option><option>Lainnya</option>
        </select>
        <span id="produkCount" style="font-size:12px;color:var(--text3)"></span>
      </div>
      <div class="product-grid" id="produkGrid">
        <div style="text-align:center;color:var(--text3);padding:40px;grid-column:1/-1">Memuat produk…</div>
      </div>
    </div>

    <!-- ========================================================
         PAGE: LAPORAN KEUANGAN
         ======================================================== -->
    <div id="pageLaporan" class="page">
      <div class="filter-bar" style="margin-bottom:20px">
        <label style="font-size:13px;color:var(--text2);font-weight:500">Bulan:</label>
        <input type="month" class="form-control" id="pilihanBulan" style="width:180px"
               value="<?= date('Y-m') ?>" onchange="loadLaporan()">
        <button class="btn btn-ghost btn-sm" onclick="loadLaporan()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Refresh
        </button>
      </div>

      <div class="report-kpi" id="reportKpi">
        <div class="kpi-box"><div class="kpi-val" id="kpi_rev" style="color:var(--cyan)">…</div><div class="kpi-lbl">Total Revenue</div></div>
        <div class="kpi-box"><div class="kpi-val" id="kpi_pesanan" style="color:var(--green)">…</div><div class="kpi-lbl">Pesanan Masuk</div></div>
        <div class="kpi-box"><div class="kpi-val" id="kpi_item" style="color:var(--yellow)">…</div><div class="kpi-lbl">Ikan Terjual</div></div>
        <div class="kpi-box"><div class="kpi-val" id="kpi_avg" style="color:var(--orange)">…</div><div class="kpi-lbl">Rata-rata/Order</div></div>
      </div>

      <div class="grid-65" style="margin-bottom:16px">
        <div class="card">
          <div class="card-header"><span class="card-title">Revenue Harian</span></div>
          <div class="card-body"><div class="chart-wrap"><canvas id="chartHarian"></canvas></div></div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title">Metode Pembayaran</span></div>
          <div class="card-body"><div class="chart-wrap" style="height:220px"><canvas id="chartMetode"></canvas></div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><span class="card-title">Produk Terlaris Bulan Ini</span></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>#</th><th>Nama Ikan</th><th>Kategori</th><th>Terjual (ekor)</th><th>Revenue</th></tr></thead>
            <tbody id="terlarisLapTbl"><tr><td colspan="5" style="text-align:center;color:var(--text3);padding:20px">Memuat…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- /.content -->
</div><!-- /.main -->
</div><!-- /.app -->

<!-- ============================================================
     MODAL: TAMBAH / EDIT PRODUK
     ============================================================ -->
<div class="modal-backdrop" id="modalProduk">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalProdukTitle">Tambah Produk</div>
      <button class="modal-close" onclick="closeModal('modalProduk')">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="prod_id">
      <input type="hidden" id="prod_gambar_lama">

      <!-- ── Upload Gambar ── -->
      <div class="form-group">
        <label class="form-label">Foto Produk</label>
        <div class="upload-area" id="uploadArea"
          onclick="document.getElementById('prod_gambar_file').click()"
          ondragover="handleDragOver(event)"
          ondragleave="handleDragLeave(event)"
          ondrop="handleDrop(event)">
          <div id="uploadPlaceholder">
            <div class="upload-placeholder-icon">🖼️</div>
            <div class="upload-placeholder-text">Klik atau seret gambar ke sini</div>
            <div class="upload-placeholder-hint">JPG · PNG · WebP · GIF &nbsp;|&nbsp; Maks. 3MB</div>
          </div>
          <img id="prod_preview" class="upload-preview-img" alt="Preview Gambar">
          <span class="upload-filename" id="upload_filename"></span>
          <button type="button" class="upload-remove-btn" id="uploadRemoveBtn"
            onclick="clearGambar(event)">✕ Hapus Foto</button>
        </div>
        <input type="file" id="prod_gambar_file" accept="image/*"
          style="display:none" onchange="previewGambar(this)">
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Nama Ikan *</label>
          <input class="form-control" id="prod_nama" placeholder="Betta Plakat…">
        </div>
        <div class="form-group">
          <label class="form-label">Kategori *</label>
          <select class="form-control" id="prod_kategori">
            <option>Plakat</option><option>Halfmoon</option><option>Crowntail</option>
            <option>Giant</option><option>Fancy</option><option>Lainnya</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Warna</label>
          <input class="form-control" id="prod_warna" placeholder="Merah, Biru…">
        </div>
        <div class="form-group">
          <label class="form-label">Harga (Rp) *</label>
          <input class="form-control" id="prod_harga" type="number" placeholder="250000">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Stok</label>
          <input class="form-control" id="prod_stok" type="number" value="0" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">Stok Minimum</label>
          <input class="form-control" id="prod_stok_min" type="number" value="1" min="0">
        </div>
      </div>
      <div class="form-group" id="prod_status_wrap" style="display:none">
        <label class="form-label">Status</label>
        <select class="form-control" id="prod_status">
          <option value="ready">Ready</option>
          <option value="habis">Habis</option>
          <option value="pre_order">Pre Order</option>
          <option value="tidak_aktif">Tidak Aktif</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Deskripsi</label>
        <textarea class="form-control" id="prod_desk" rows="3" placeholder="Deskripsi ikan…"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modalProduk')">Batal</button>
      <button class="btn btn-primary" id="saveProdukBtn" onclick="saveProduk()">Simpan Produk</button>
    </div>
  </div>
</div>

<!-- MODAL: TAMBAH STOK -->
<div class="modal-backdrop" id="modalStok">
  <div class="modal" style="max-width:360px">
    <div class="modal-header">
      <div class="modal-title">Tambah Stok</div>
      <button class="modal-close" onclick="closeModal('modalStok')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="stok_prod_id">
      <div style="font-size:14px;color:var(--text2);margin-bottom:14px" id="stok_prod_nama">—</div>
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:14px">
        <span style="font-size:13px;color:var(--text3)">Stok saat ini:</span>
        <span class="pill pill-blue" id="stok_current">—</span>
      </div>
      <div class="form-group">
        <label class="form-label">Tambah Jumlah *</label>
        <input class="form-control" id="stok_tambah" type="number" value="1" min="1" placeholder="Jumlah yang ditambahkan">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modalStok')">Batal</button>
      <button class="btn btn-success" onclick="submitTambahStok()">+ Tambah Stok</button>
    </div>
  </div>
</div>

<!-- MODAL: UPDATE STATUS PESANAN -->
<div class="modal-backdrop" id="modalStatusPesanan">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <div class="modal-title">Update Status Pesanan</div>
      <button class="modal-close" onclick="closeModal('modalStatusPesanan')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="sp_invoice">
      <div class="form-group">
        <label class="form-label">Invoice</label>
        <div class="mono" id="sp_inv_display" style="font-size:14px;padding:8px 0">—</div>
      </div>
      <div class="form-group">
        <label class="form-label">Status Baru *</label>
        <select class="form-control" id="sp_status">
          <option value="menunggu_pembayaran">Menunggu Pembayaran</option>
          <option value="pembayaran_dikonfirmasi">Pembayaran Dikonfirmasi</option>
          <option value="diproses">Diproses</option>
          <option value="dikirim">Dikirim</option>
          <option value="selesai">Selesai</option>
          <option value="dibatalkan">Dibatalkan</option>
        </select>
      </div>
      <div class="form-group" id="resiWrap" style="display:none">
        <label class="form-label">Nomor Resi</label>
        <input class="form-control" id="sp_resi" placeholder="Masukkan nomor resi…">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modalStatusPesanan')">Batal</button>
      <button class="btn btn-primary" onclick="submitStatusPesanan()">Simpan</button>
    </div>
  </div>
</div>

<!-- MODAL: DETAIL INVOICE -->
<div class="modal-backdrop" id="modalInvoice">
  <div class="modal" style="max-width:600px;max-height:90vh;overflow-y:auto">
    <div class="modal-header">
      <div class="modal-title">Detail Invoice</div>
      <button class="modal-close" onclick="closeModal('modalInvoice')">✕</button>
    </div>
    <div class="modal-body" id="invoiceContent">
      <!-- Content diisi secara dinamis -->
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modalInvoice')">Tutup</button>
      <button class="btn btn-primary" onclick="window.print()">Cetak</button>
    </div>
  </div>
</div>

<!-- TOAST CONTAINER -->
<div class="toast-wrap" id="toastWrap"></div>

<!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
<script>
// ── State ───────────────────────────────────────────────────
let chartRevenue = null, chartStatus = null, chartHarian = null, chartMetode = null;
let debounceTimers = {};

// ── Utils ───────────────────────────────────────────────────
const fmt  = n => 'Rp ' + parseInt(n).toLocaleString('id-ID');
const fmtK = n => { n = parseInt(n); return n >= 1000000 ? (n/1000000).toFixed(1)+'jt' : n >= 1000 ? (n/1000).toFixed(0)+'rb' : n; };
const esc  = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

function debounce(fn, ms, key='default') {
  clearTimeout(debounceTimers[key]);
  debounceTimers[key] = setTimeout(fn, ms);
}

function toast(msg, type='info') {
  const w = document.getElementById('toastWrap');
  const t = document.createElement('div');
  const icons = {success:'✅',error:'❌',info:'ℹ️'};
  t.className = 'toast ' + type;
  t.innerHTML = `<span>${icons[type]||'📢'}</span> ${msg}`;
  w.appendChild(t);
  setTimeout(() => {
    t.style.opacity='0'; t.style.transform='translateX(20px)'; t.style.transition='all 0.3s';
    setTimeout(()=>t.remove(),300);
  }, 3500);
}

function statusPill(s) {
  const map = {
    'menunggu_pembayaran':    ['pill-yellow','⏳ Menunggu Bayar'],
    'pembayaran_dikonfirmasi':['pill-blue',  '✅ Dikonfirmasi'],
    'diproses':               ['pill-orange','⚙️ Diproses'],
    'dikirim':                ['pill-blue',  '🚚 Dikirim'],
    'selesai':                ['pill-green', '✅ Selesai'],
    'dibatalkan':             ['pill-red',   '❌ Dibatalkan'],
    'refund':                 ['pill-gray',  '↩️ Refund'],
  };
  const [cls, lbl] = map[s] || ['pill-gray', s];
  return `<span class="pill ${cls}">${lbl}</span>`;
}

function stockPill(stok, min) {
  if (stok == 0) return '<span class="pill pill-red">Habis</span>';
  if (stok <= min) return `<span class="pill pill-yellow">Menipis (${stok})</span>`;
  return `<span class="pill pill-green">${stok} ekor</span>`;
}

// ── Clock ───────────────────────────────────────────────────
function updateClock() {
  document.getElementById('liveClock').textContent =
    new Date().toLocaleTimeString('id-ID', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(updateClock, 1000); updateClock();

// ── Sidebar ─────────────────────────────────────────────────
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); }

// ── Navigation ──────────────────────────────────────────────
const pageIcons = {
  dashboard: '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
  pesanan:   '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>',
  produk:    '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
  laporan:   '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
};
const pageNames = {dashboard:'Dashboard',pesanan:'Pesanan',produk:'Produk & Stok',laporan:'Laporan Keuangan'};

function goPage(el) {
  const page = el.dataset.page;
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page' + page.charAt(0).toUpperCase() + page.slice(1)).classList.add('active');
  document.getElementById('topbarTitle').innerHTML = (pageIcons[page]||'') + ' ' + (pageNames[page]||page);
  if (page === 'dashboard') loadDashboard();
  if (page === 'pesanan')   loadPesanan();
  if (page === 'produk')    loadProduk();
  if (page === 'laporan')   loadLaporan();
  if (window.innerWidth < 860) document.getElementById('sidebar').classList.remove('open');
}
function goPageById(page) {
  const el = document.querySelector(`.nav-item[data-page="${page}"]`);
  if (el) goPage(el);
}

// ── Chart helpers ────────────────────────────────────────────
Chart.defaults.color = '#8B949E';
Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";

function makeLineChart(id, labels, data, label) {
  const ctx = document.getElementById(id);
  if (!ctx) return null;
  return new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label, data,
        borderColor: '#2F81F7',
        backgroundColor: 'rgba(47,129,247,0.08)',
        fill: true, tension: 0.4,
        pointBackgroundColor: '#2F81F7',
        pointRadius: 4, pointHoverRadius: 6, borderWidth: 2,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + fmt(c.raw) } } },
      scales: {
        y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { callback: v => fmtK(v) } },
        x: { grid: { display: false } }
      }
    }
  });
}

function makeDoughnut(id, labels, data, colors) {
  const ctx = document.getElementById(id);
  if (!ctx) return null;
  return new Chart(ctx, {
    type: 'doughnut',
    data: { labels, datasets: [{ data, backgroundColor: colors, borderColor: '#161B22', borderWidth: 3, hoverOffset: 6 }] },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '65%',
      plugins: {
        legend: { position: 'right', labels: { boxWidth: 12, padding: 14, font: { size: 12 } } },
        tooltip: { callbacks: { label: c => ` ${c.label}: ${c.raw}` } }
      }
    }
  });
}

function makeBarChart(id, labels, data, label) {
  const ctx = document.getElementById(id);
  if (!ctx) return null;
  return new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label, data,
        backgroundColor: 'rgba(47,129,247,0.7)',
        borderRadius: 6,
        hoverBackgroundColor: '#2F81F7',
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + fmt(c.raw) } } },
      scales: {
        y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { callback: v => fmtK(v) } },
        x: { grid: { display: false } }
      }
    }
  });
}

// ── DASHBOARD ────────────────────────────────────────────────
function loadDashboard() {
  fetch('?ajax=get_dashboard')
    .then(r => r.json())
    .then(res => {
      const s = res.stats;
      document.getElementById('s_revenue').textContent  = fmtK(s.total_revenue || 0);
      document.getElementById('s_pesanan').textContent  = s.total_pesanan || 0;
      document.getElementById('s_produk').textContent   = s.total_produk || 0;
      document.getElementById('s_menunggu').textContent = s.menunggu_bayar || 0;
      document.getElementById('s_selesai_meta').innerHTML = `<b>${s.pesanan_selesai||0} selesai</b> · ${s.sedang_dikirim||0} dikirim`;
      document.getElementById('s_habis_meta').innerHTML   = `<b style="color:var(--red)">${s.produk_habis||0} habis</b> · ${s.total_stok||0} ekor stok`;

      if (s.menunggu_bayar > 0) {
        const b = document.getElementById('navBadgePesanan');
        b.style.display = 'inline'; b.textContent = s.menunggu_bayar;
      }

      if (chartRevenue) chartRevenue.destroy();
      chartRevenue = makeLineChart('chartRevenue', res.chart.labels, res.chart.revenue, 'Revenue');

      const statusLabels = Object.keys(res.status_dist);
      const statusVals   = Object.values(res.status_dist);
      const statusColors = ['#E3B341','#58A6FF','#F78166','#2F81F7','#3FB950','#F85149','#8B949E'];
      if (chartStatus) chartStatus.destroy();
      chartStatus = makeDoughnut('chartStatus', statusLabels, statusVals, statusColors.slice(0, statusLabels.length));

      document.getElementById('recentOrdersTbl').innerHTML = res.recent.map(r => `
        <tr>
          <td class="mono">${esc(r.invoice)}</td>
          <td>${esc(r.nama_pemesan)}</td>
          <td style="font-family:var(--mono);font-size:12px">${fmt(r.harga_total)}</td>
          <td>${statusPill(r.status_pembelian)}</td>
        </tr>`).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--text3)">Belum ada pesanan</td></tr>';

      document.getElementById('topProdukTbl').innerHTML = res.terlaris.map(r => `
        <tr>
          <td>${esc(r.nama_ikan)}</td>
          <td><span class="pill pill-green">${r.terjual} ekor</span></td>
          <td style="font-family:var(--mono);font-size:12px">${fmt(r.revenue)}</td>
        </tr>`).join('') || '<tr><td colspan="3" style="text-align:center;color:var(--text3)">Belum ada data</td></tr>';
    })
    .catch(() => toast('Gagal memuat dashboard', 'error'));
}

// ── PESANAN ──────────────────────────────────────────────────
function loadPesanan() {
  const search = document.getElementById('searchPesanan').value;
  const status = document.getElementById('filterStatus').value;
  fetch('?' + new URLSearchParams({ajax:'get_orders', search, status}))
    .then(r => r.json())
    .then(rows => {
      document.getElementById('pesananCount').textContent = rows.length + ' pesanan';
      const tbody = document.getElementById('pesananTbl');
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--text3);padding:30px">Tidak ada pesanan</td></tr>';
        return;
      }
      tbody.innerHTML = rows.map(r => `
        <tr style="cursor:pointer;transition:var(--transition)" onmouseover="this.style.background='var(--bg3)'" onmouseout="this.style.background=''" onclick="viewInvoice('${esc(r.invoice)}')">
          <td class="mono">${esc(r.invoice)}</td>
          <td>
            <div style="font-weight:600;font-size:13px">${esc(r.nama_pemesan)}</div>
            <div style="font-size:11px;color:var(--text3)">${esc(r.nomor_hp)}</div>
          </td>
          <td style="max-width:180px;font-size:12px;color:var(--text2)">${esc(r.daftar_ikan||'—')}</td>
          <td style="font-size:12px">${esc(r.ekspedisi)}</td>
          <td style="font-size:12px">${esc(r.metode_bayar)}</td>
          <td style="text-align:center">${r.jumlah_produk}</td>
          <td style="font-family:var(--mono);font-size:12px;white-space:nowrap">${fmt(r.harga_total)}</td>
          <td>${statusPill(r.status_pembelian)}</td>
          <td onclick="event.stopPropagation()">
            <button class="btn btn-ghost btn-sm" onclick="openStatusModal('${esc(r.invoice)}','${r.status_pembelian}')">
              <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Update
            </button>
          </td>
        </tr>`).join('');
    })
    .catch(() => toast('Gagal memuat pesanan', 'error'));
}

// ── MODAL STATUS PESANAN ─────────────────────────────────────
function openStatusModal(invoice, currentStatus) {
  document.getElementById('sp_invoice').value = invoice;
  document.getElementById('sp_inv_display').textContent = invoice;
  document.getElementById('sp_status').value = currentStatus;
  document.getElementById('sp_resi').value = '';
  toggleResi();
  openModal('modalStatusPesanan');
}
document.getElementById('sp_status').addEventListener('change', toggleResi);
function toggleResi() {
  document.getElementById('resiWrap').style.display =
    document.getElementById('sp_status').value === 'dikirim' ? 'block' : 'none';
}
function submitStatusPesanan() {
  const invoice = document.getElementById('sp_invoice').value;
  const status  = document.getElementById('sp_status').value;
  const resi    = document.getElementById('sp_resi').value;
  fetch('?ajax=update_order_status', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({invoice, status, resi})
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) { toast('Status pesanan diperbarui', 'success'); closeModal('modalStatusPesanan'); loadPesanan(); loadDashboard(); }
    else { toast('Gagal memperbarui status', 'error'); }
  })
  .catch(() => toast('Koneksi gagal', 'error'));
}

// ── VIEW INVOICE ─────────────────────────────────────────────
function viewInvoice(invoice) {
  fetch('?ajax=get_order_detail&invoice=' + encodeURIComponent(invoice))
    .then(r => r.json())
    .then(res => {
      if (!res.success || !res.pesanan) { toast('Gagal memuat detail pesanan', 'error'); return; }
      
      const p = res.pesanan;
      const items = res.items || [];
      const tanggal = new Date(p.created_at).toLocaleDateString('id-ID', { year: 'numeric', month: 'long', day: 'numeric' });
      const jam = new Date(p.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      
      let itemsHtml = items.map(item => `
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:13px">
          <div>
            <div style="font-weight:600">${esc(item.nama_ikan)}</div>
            <div style="color:#6b7280;font-size:12px">${esc(item.kategori_ikan || '—')}</div>
          </div>
          <div style="text-align:right">
            <div style="color:#6b7280">x${item.qty}</div>
            <div style="color:#6b7280">${fmt(item.harga_satuan)}</div>
          </div>
        </div>
      `).join('');
      
      const invoiceHtml = `
        <div style="background:#f9fafb;padding:30px;border-radius:8px">
          <div style="text-align:center;margin-bottom:20px">
            <div style="font-size:24px;font-weight:700;color:#0f766e">🐟 YOSEP FISH</div>
            <div style="font-size:12px;color:#6b7280;margin-top:2px">Toko Ikan Hias Berkualitas</div>
          </div>
          
          <div style="border-top:2px solid #0f766e;border-bottom:2px solid #0f766e;padding:15px 0;margin:15px 0">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;font-size:13px">
              <div>
                <div style="color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:0.5px">No. Invoice</div>
                <div style="font-weight:700;font-size:16px;color:#1f2937;margin-top:4px">${esc(p.invoice)}</div>
              </div>
              <div>
                <div style="color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:0.5px">Tanggal & Jam</div>
                <div style="font-weight:600;font-size:13px;color:#1f2937;margin-top:4px">${tanggal} ${jam}</div>
              </div>
            </div>
          </div>
          
          <div style="margin:15px 0">
            <div style="color:#6b7280;font-size:11px;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;margin-bottom:8px">Detail Pembeli</div>
            <div style="font-size:13px">
              <div style="font-weight:600">${esc(p.nama_pemesan)}</div>
              <div style="color:#6b7280;font-size:12px;margin-top:2px">${esc(p.nomor_hp)}</div>
              <div style="color:#6b7280;font-size:12px;margin-top:2px">${esc(p.alamat_rumah)}</div>
            </div>
          </div>
          
          <div style="margin:15px 0">
            <div style="color:#6b7280;font-size:11px;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;margin-bottom:8px">Detail Pesanan</div>
            ${itemsHtml}
          </div>
          
          <div style="background:white;padding:12px;border-radius:6px;margin:15px 0">
            <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13px">
              <span>Subtotal</span>
              <span style="font-family:monospace">${fmt(p.subtotal)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13px">
              <span>Ongkir</span>
              <span style="font-family:monospace">${fmt(p.ongkir)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;border-top:1px solid #e5e7eb;padding-top:8px;font-weight:700;font-size:15px;color:#0f766e">
              <span>Total</span>
              <span style="font-family:monospace">${fmt(p.harga_total)}</span>
            </div>
          </div>
          
          <div style="margin:15px 0;padding:12px;background:#f0fdf4;border-left:4px solid #16a34a;border-radius:4px;font-size:12px;color:#15803d">
            <div style="font-weight:600;margin-bottom:4px">Metode Pembayaran</div>
            <div>${esc(p.metode_bayar)}</div>
            <div style="margin-top:6px;font-weight:600;margin-bottom:4px">Pengiriman</div>
            <div>${esc(p.ekspedisi)}</div>
          </div>
          
          <div style="text-align:center;padding:20px;border-top:2px solid #0f766e;margin-top:20px;font-size:12px;color:#6b7280;line-height:1.6">
            <div style="font-weight:600;color:#1f2937;margin-bottom:6px">Terimakasih, Selamat Belanja Kembali! 🐟</div>
            <div>Ikan Anda akan segera dikirim dalam kondisi prima.</div>
          </div>
        </div>
      `;
      
      document.getElementById('invoiceContent').innerHTML = invoiceHtml;
      openModal('modalInvoice');
    })
    .catch(() => toast('Gagal memuat detail invoice', 'error'));
}

// ── PRODUK ──────────────────────────────────────────────────

// ── PRODUK ───────────────────────────────────────────────────
function loadProduk() {
  const search   = document.getElementById('searchProduk').value;
  const kategori = document.getElementById('filterKat').value;
  fetch('?' + new URLSearchParams({ajax:'get_products', search, kategori}))
    .then(r => r.json())
    .then(rows => {
      document.getElementById('produkCount').textContent = rows.length + ' produk';
      const grid = document.getElementById('produkGrid');
      if (!rows.length) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:var(--text3);padding:40px">Tidak ada produk</div>';
        return;
      }
      grid.innerHTML = rows.map(p => {
        const statusMap = {ready:'pill-green',habis:'pill-red',pre_order:'pill-yellow',tidak_aktif:'pill-gray'};
        const statusLbl = {ready:'Ready',habis:'Habis',pre_order:'Pre Order',tidak_aktif:'Non-Aktif'};
        const safeData  = encodeURIComponent(JSON.stringify(p));
        return `
          <div class="prod-card">
            <div class="prod-img">
              ${p.gambar_url ? `<img src="${esc(p.gambar_url)}" alt="${esc(p.nama)}" loading="lazy" onerror="this.style.display='none'">` : ''}
              <span class="prod-badge pill ${statusMap[p.status]||'pill-gray'}">${statusLbl[p.status]||p.status}</span>
            </div>
            <div class="prod-body">
              <div class="prod-nama">${esc(p.nama)}</div>
              <div class="prod-kat">${esc(p.kategori)} · ${esc(p.warna||'—')}</div>
              <div class="prod-footer">
                <span class="prod-harga">${fmt(p.harga)}</span>
                ${stockPill(p.stok, p.stok_minimum)}
              </div>
              <div class="prod-actions">
                <button class="btn btn-ghost btn-sm" style="flex:1"
                  onclick='openModalProduk(JSON.parse(decodeURIComponent("${safeData}")))'>✏️ Edit</button>
                <button class="btn btn-success btn-sm"
                  onclick="openModalStok(${p.id},'${esc(p.nama)}',${p.stok})">+ Stok</button>
              </div>
            </div>
          </div>`;
      }).join('');
    })
    .catch(() => toast('Gagal memuat produk', 'error'));
}

// ── UPLOAD GAMBAR HELPERS ─────────────────────────────────────
function previewGambar(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  if (file.size > 3 * 1024 * 1024) {
    toast('Ukuran gambar maksimal 3MB!', 'error');
    input.value = '';
    return;
  }
  const reader = new FileReader();
  reader.onload = e => {
    const uploadArea = document.getElementById('uploadArea');
    const preview = document.getElementById('prod_preview');
    preview.src = e.target.result;
    uploadArea.classList.add('has-image');
    document.getElementById('uploadPlaceholder').style.display = 'none';
    document.getElementById('upload_filename').textContent = file.name;
    toast(`Gambar siap diunggah: ${file.name}`, 'success');
  };
  reader.readAsDataURL(file);
}

function handleDragOver(e) {
  e.preventDefault();
  document.getElementById('uploadArea').classList.add('drag-over');
}

function handleDragLeave(e) {
  document.getElementById('uploadArea').classList.remove('drag-over');
}

function handleDrop(e) {
  e.preventDefault();
  document.getElementById('uploadArea').classList.remove('drag-over');
  const file = e.dataTransfer.files[0];
  if (!file || !file.type.startsWith('image/')) {
    toast('File harus berupa gambar!', 'error'); return;
  }
  const input = document.getElementById('prod_gambar_file');
  const dt = new DataTransfer();
  dt.items.add(file);
  input.files = dt.files;
  previewGambar(input);
}

function clearGambar(e) {
  if (e) e.stopPropagation();
  const uploadArea = document.getElementById('uploadArea');
  uploadArea.classList.remove('has-image');
  document.getElementById('prod_gambar_file').value = '';
  document.getElementById('prod_preview').src = '';
  document.getElementById('uploadPlaceholder').style.display = '';
  document.getElementById('upload_filename').textContent = '';
  document.getElementById('prod_gambar_lama').value = '';
}

// ── MODAL PRODUK ─────────────────────────────────────────────
function openModalProduk(data) {
  const isEdit = !!data;
  document.getElementById('modalProdukTitle').textContent  = isEdit ? 'Edit Produk' : 'Tambah Produk';
  document.getElementById('prod_status_wrap').style.display = isEdit ? 'block' : 'none';
  document.getElementById('saveProdukBtn').textContent     = isEdit ? 'Simpan Perubahan' : 'Simpan Produk';

  // Reset upload area
  const uploadArea = document.getElementById('uploadArea');
  uploadArea.classList.remove('has-image');
  document.getElementById('prod_gambar_file').value           = '';
  document.getElementById('prod_preview').src                 = '';
  document.getElementById('uploadPlaceholder').style.display  = '';
  document.getElementById('upload_filename').textContent      = '';

  if (isEdit) {
    document.getElementById('prod_id').value          = data.id;
    document.getElementById('prod_gambar_lama').value = data.gambar_url || '';
    document.getElementById('prod_nama').value        = data.nama;
    document.getElementById('prod_kategori').value    = data.kategori;
    document.getElementById('prod_warna').value       = data.warna || '';
    document.getElementById('prod_harga').value       = data.harga;
    document.getElementById('prod_stok').value        = data.stok;
    document.getElementById('prod_stok_min').value    = data.stok_minimum;
    document.getElementById('prod_status').value      = data.status;
    document.getElementById('prod_desk').value        = data.deskripsi || '';

    // Tampilkan gambar lama
    if (data.gambar_url) {
      const uploadArea = document.getElementById('uploadArea');
      const preview = document.getElementById('prod_preview');
      preview.src = data.gambar_url;
      uploadArea.classList.add('has-image');
      document.getElementById('uploadPlaceholder').style.display = 'none';
      document.getElementById('upload_filename').textContent = '📷 Foto saat ini';
    }
  } else {
    document.getElementById('prod_id').value          = '';
    document.getElementById('prod_gambar_lama').value = '';
    document.getElementById('prod_nama').value        = '';
    document.getElementById('prod_warna').value       = '';
    document.getElementById('prod_harga').value       = '';
    document.getElementById('prod_stok').value        = '0';
    document.getElementById('prod_stok_min').value   = '1';
    document.getElementById('prod_kategori').value    = 'Plakat';
    document.getElementById('prod_desk').value        = '';
  }
  openModal('modalProduk');
}

function saveProduk() {
  const id    = document.getElementById('prod_id').value;
  const nama  = document.getElementById('prod_nama').value.trim();
  const harga = document.getElementById('prod_harga').value;
  if (!nama || !harga) { toast('Nama dan harga wajib diisi!', 'error'); return; }

  // Gunakan FormData agar bisa kirim file
  const fd = new FormData();
  fd.append('nama',          nama);
  fd.append('kategori',      document.getElementById('prod_kategori').value);
  fd.append('warna',         document.getElementById('prod_warna').value.trim());
  fd.append('harga',         harga);
  fd.append('stok',          document.getElementById('prod_stok').value);
  fd.append('stok_minimum',  document.getElementById('prod_stok_min').value);
  fd.append('deskripsi',     document.getElementById('prod_desk').value.trim());
  fd.append('status',        document.getElementById('prod_status').value || 'ready');

  const fileInput = document.getElementById('prod_gambar_file');
  if (fileInput.files[0]) {
    fd.append('gambar', fileInput.files[0]);
  }

  if (id) {
    fd.append('id', id);
    fd.append('gambar_url_lama', document.getElementById('prod_gambar_lama').value);
  }

  const action = id ? 'update_product' : 'add_product';
  const btn    = document.getElementById('saveProdukBtn');
  const hasImage = fileInput.files && fileInput.files[0];
  btn.disabled = true; 
  btn.textContent = hasImage ? 'Menyimpan & Upload Gambar…' : 'Menyimpan…';

  fetch('?ajax=' + action, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      btn.disabled = false;
      btn.textContent = id ? 'Simpan Perubahan' : 'Simpan Produk';
      if (res.success) {
        const msg = id ? 'Produk berhasil diperbarui!' : `Produk ditambahkan (${res.kode||''})`;
        const imgMsg = hasImage ? ' Gambar telah disimpan ke database.' : '';
        toast(msg + imgMsg, 'success');
        closeModal('modalProduk');
        loadProduk();
      } else toast(res.msg || 'Gagal menyimpan', 'error');
    })
    .catch(err => { 
      btn.disabled = false; 
      toast('Terjadi kesalahan jaringan', 'error'); 
    });
}

// ── STOK ─────────────────────────────────────────────────────
function openModalStok(id, nama, stok) {
  document.getElementById('stok_prod_id').value           = id;
  document.getElementById('stok_prod_nama').textContent   = nama;
  document.getElementById('stok_current').textContent     = stok + ' ekor';
  document.getElementById('stok_tambah').value            = 1;
  openModal('modalStok');
}

function submitTambahStok() {
  const id  = document.getElementById('stok_prod_id').value;
  const qty = parseInt(document.getElementById('stok_tambah').value);
  if (!qty || qty < 1) { toast('Jumlah minimal 1', 'error'); return; }
  fetch('?ajax=tambah_stok', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id: parseInt(id), qty})
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      toast(`Stok berhasil ditambahkan. Stok baru: ${res.data.stok} ekor`, 'success');
      closeModal('modalStok');
      loadProduk();
    } else toast('Gagal tambah stok', 'error');
  });
}

// ── LAPORAN ──────────────────────────────────────────────────
function loadLaporan() {
  const bulan = document.getElementById('pilihanBulan').value;
  fetch('?ajax=get_reports&bulan=' + bulan)
    .then(r => r.json())
    .then(res => {
      const s = res.summary;
      const avgOrder = s.selesai > 0 ? Math.round(s.total_revenue / s.selesai) : 0;
      document.getElementById('kpi_rev').textContent     = fmtK(s.total_revenue || 0);
      document.getElementById('kpi_pesanan').textContent = s.total_pesanan || 0;
      document.getElementById('kpi_item').textContent    = s.total_item || 0;
      document.getElementById('kpi_avg').textContent     = fmtK(avgOrder);

      const labels  = res.harian.map(h => h.tgl ? h.tgl.substring(5) : '');
      const revData = res.harian.map(h => parseInt(h.revenue) || 0);
      if (chartHarian) chartHarian.destroy();
      chartHarian = makeBarChart('chartHarian', labels, revData, 'Revenue Harian');

      const mLabels = res.metode.map(m => m.metode_bayar);
      const mVals   = res.metode.map(m => parseInt(m.jml));
      const mColors = ['#2F81F7','#3FB950','#F78166','#E3B341','#58A6FF','#F85149'];
      if (chartMetode) chartMetode.destroy();
      chartMetode = makeDoughnut('chartMetode', mLabels, mVals, mColors.slice(0, mLabels.length));

      document.getElementById('terlarisLapTbl').innerHTML = res.terlaris.length
        ? res.terlaris.map((r, i) => `<tr>
            <td style="color:var(--text3)">${i+1}</td>
            <td style="font-weight:600">${esc(r.nama_ikan)}</td>
            <td><span class="pill pill-blue">${esc(r.kategori_ikan||'—')}</span></td>
            <td><span class="pill pill-green">${r.terjual} ekor</span></td>
            <td style="font-family:var(--mono);font-size:12px">${fmt(r.revenue)}</td>
          </tr>`).join('')
        : '<tr><td colspan="5" style="text-align:center;color:var(--text3);padding:20px">Belum ada data bulan ini</td></tr>';
    })
    .catch(() => toast('Gagal memuat laporan', 'error'));
}

// ── MODAL HELPERS ────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-backdrop').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});

// ── INIT ─────────────────────────────────────────────────────
loadDashboard();

// ── DEBUG: Check for scroll redirect issue ───────────────────
window.addEventListener('scroll', function(e) {
  console.log('Scroll event detected, target:', e.target);
  console.log('Scroll position:', window.scrollY);
});

window.addEventListener('beforeunload', function(e) {
  console.log('Page is about to unload, possible redirect');
  // Prevent accidental redirects
  if (performance.navigation.type === 1) { // TYPE_RELOAD
    console.log('Page reload detected');
  }
});
</script>
</body>
</html>