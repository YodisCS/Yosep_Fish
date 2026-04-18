<?php

require_once __DIR__ . '/config/db.php';
$pdo = $db;

session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit();
}
if (!isset($_SESSION['keranjang'])) $_SESSION['keranjang'] = [];

header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'get_products') {
  header('Content-Type: application/json');
  $kategori = $_GET['kategori'] ?? '';
  $search   = $_GET['search'] ?? '';
  $maxHarga = (int)($_GET['max_harga'] ?? 1000000);

  if ($pdo) {
    $sql = "SELECT id, nama, kategori, warna, harga, deskripsi, gambar_url FROM produk WHERE status = 'ready' AND stok > 0 AND harga <= :harga";
    $params = [':harga' => $maxHarga];
    if ($kategori && $kategori !== 'Semua') {
      $sql .= " AND kategori = :kategori";
      $params[':kategori'] = $kategori;
    }
    if ($search) {
      $sql .= " AND (nama LIKE :search OR warna LIKE :search2)";
      $params[':search']  = "%$search%";
      $params[':search2'] = "%$search%";
    }
    $sql .= " ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
  } else {
    echo json_encode(['success' => false, 'message' => 'DB tidak terhubung']);
  }
  exit;
}

if ($action === 'add_to_cart') {
  header('Content-Type: application/json');
  $id = (int)($_POST['id'] ?? 0);
  if ($id && $pdo) {
    $prod = $pdo->prepare("SELECT * FROM produk WHERE id = ?");
    $prod->execute([$id]);
    $item = $prod->fetch(PDO::FETCH_ASSOC);
    if ($item) {
      $found = false;
      foreach ($_SESSION['keranjang'] as &$k) {
        if ($k['id'] == $id) {
          $k['qty']++;
          $found = true;
          break;
        }
      }
      if (!$found) {
        $_SESSION['keranjang'][] = [
          'id'       => $item['id'],
          'nama'     => $item['nama'],
          'kategori' => $item['kategori'],
          'harga'    => $item['harga'],
          'gambar'   => $item['gambar_url'],
          'qty'      => 1,
        ];
      }
    }
  }
  echo json_encode(['success' => true, 'count' => array_sum(array_column($_SESSION['keranjang'], 'qty')), 'keranjang' => $_SESSION['keranjang']]);
  exit;
}

if ($action === 'remove_from_cart') {
  header('Content-Type: application/json');
  $id = (int)($_POST['id'] ?? 0);
  $_SESSION['keranjang'] = array_values(array_filter($_SESSION['keranjang'], fn($k) => $k['id'] != $id));
  $subtotal = array_sum(array_map(fn($k) => $k['harga'] * $k['qty'], $_SESSION['keranjang']));
  echo json_encode(['success' => true, 'count' => array_sum(array_column($_SESSION['keranjang'], 'qty')), 'keranjang' => $_SESSION['keranjang'], 'subtotal' => $subtotal]);
  exit;
}

if ($action === 'get_cart') {
  header('Content-Type: application/json');
  $subtotal = array_sum(array_map(fn($k) => $k['harga'] * $k['qty'], $_SESSION['keranjang']));
  echo json_encode(['success' => true, 'count' => array_sum(array_column($_SESSION['keranjang'], 'qty')), 'keranjang' => $_SESSION['keranjang'], 'subtotal' => $subtotal]);
  exit;
}

if ($action === 'buat_pesanan') {
  header('Content-Type: application/json');
  $nama      = trim($_POST['nama'] ?? '');
  $telepon   = trim($_POST['telepon'] ?? '');
  $alamat    = trim($_POST['alamat'] ?? '');
  $ekspedisi = $_POST['ekspedisi'] ?? 'J&T Express (Standard)';
  $wilayah   = $_POST['wilayah'] ?? 'Jabodetabek';
  $bayar     = $_POST['metode_bayar'] ?? 'BCA';

  $ekspedisiMap = [
    'J&T Express (Standard)' => 'J&T Express Standard',
    'J&T Express (Express)'  => 'J&T Express Express',
    'SiCepat REG'            => 'SiCepat REG',
    'SiCepat BEST'           => 'SiCepat BEST',
    'AnterAja'               => 'AnterAja',
    'Gosend Same Day'        => 'Gosend Same Day',
  ];
  $metodeMap = [
    'BCA'      => 'Transfer BCA',
    'Mandiri'  => 'Transfer Mandiri',
    'BRI'      => 'Transfer BRI',
    'Gopay'    => 'Gopay',
    'Dana'     => 'Dana',
    'OVO'      => 'OVO',
  ];
  $ongkirMap = [
    'Jabodetabek'      => 20000,
    'Luar Jabodetabek' => 40000,
    'Luar Pulau Jawa'  => 60000,
  ];

  $ekspedisi = $ekspedisiMap[$ekspedisi] ?? $ekspedisi;
  $bayar     = $metodeMap[$bayar] ?? $bayar;
  $ongkir    = $ongkirMap[$wilayah] ?? 20000;

  if (!$nama || !$telepon || !$alamat) {
    echo json_encode(['success' => false, 'message' => 'Lengkapi data pengiriman!']);
    exit;
  }
  if (empty($_SESSION['keranjang'])) {
    echo json_encode(['success' => false, 'message' => 'Keranjang kosong!']);
    exit;
  }

  $subtotal      = array_sum(array_map(fn($k) => $k['harga'] * $k['qty'], $_SESSION['keranjang']));
  $jumlahProduk  = array_sum(array_column($_SESSION['keranjang'], 'qty'));
  $total         = $subtotal + $ongkir;
  $kode          = 'YF' . date('ymd') . strtoupper(substr(uniqid(), -4));

  if ($pdo) {
    try {
      $pdo->beginTransaction();
      $stmt = $pdo->prepare(
        "INSERT INTO pesanan (invoice,nama_pemesan,nomor_hp,alamat_rumah,ekspedisi,metode_bayar,jumlah_produk,subtotal,ongkir,harga_total,status_pembelian) VALUES (?,?,?,?,?,?,?,?,?,?,?)"
      );
      $stmt->execute([$kode, $nama, $telepon, $alamat, $ekspedisi, $bayar, $jumlahProduk, $subtotal, $ongkir, $total, 'menunggu_pembayaran']);
      $pesanan_id = $pdo->lastInsertId();

      $stmtItem = $pdo->prepare(
        "INSERT INTO pesanan_item (pesanan_id,produk_id,nama_ikan,kategori_ikan,harga_satuan,qty,subtotal_item) VALUES (?,?,?,?,?,?,?)"
      );
      foreach ($_SESSION['keranjang'] as $k) {
        $stmtItem->execute([
          $pesanan_id,
          $k['id'],
          $k['nama'],
          $k['kategori'] ?? '',
          $k['harga'],
          $k['qty'],
          $k['harga'] * $k['qty'],
        ]);
      }
      $pdo->commit();
      $_SESSION['keranjang'] = [];
      echo json_encode([
        'success' => true,
        'kode' => $kode,
        'total' => $total,
        'ongkir' => $ongkir,
        'metode' => $bayar,
        'nama' => $nama,
        'telepon' => $telepon,
        'alamat' => $alamat,
        'ekspedisi' => $ekspedisi,
        'wilayah' => $wilayah,
        'subtotal' => $subtotal
      ]);
    } catch (Exception $e) {
      $pdo->rollBack();
      echo json_encode(['success' => false, 'message' => 'Gagal menyimpan pesanan: ' . $e->getMessage()]);
    }
  } else {
    echo json_encode(['success' => false, 'message' => 'Database tidak terhubung.']);
  }
  exit;
}

?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>YOSEPFISH — Betta Store Eksklusif</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --navy: #0F172A;
      --navy-dark: #0A0F1F;
      --slate-600: #475569;
      --slate-700: #334155;
      --blue: #0F766E;
      --blue-light: #14B8A6;
      --cyan: #06B6D4;
      --white: #FFFFFF;
      --bg-light: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      --bg-glass: rgba(255, 255, 255, 0.1);
      --bg-glass-strong: rgba(255, 255, 255, 0.15);
      --bg-glass-light: rgba(255, 255, 255, 0.05);
      --bg-white: rgba(255, 255, 255, 0.95);
      --gray-100: rgba(241, 245, 249, 0.8);
      --gray-200: rgba(226, 232, 240, 0.6);
      --gray-300: rgba(203, 213, 225, 0.5);
      --gray-400: rgba(148, 163, 184, 0.7);
      --gray-600: #475569;
      --text: rgba(255, 255, 255, 0.95);
      --text-muted: rgba(255, 255, 255, 0.75);
      --text-light: rgba(255, 255, 255, 0.6);
      --accent: #0F766E;
      --success: #22C55E;
      --error: #DC2626;
      --shadow-sm: 0 8px 32px rgba(31, 38, 135, 0.37);
      --shadow-md: 0 8px 32px rgba(31, 38, 135, 0.37);
      --shadow-lg: 0 8px 32px rgba(31, 38, 135, 0.37);
      --shadow-xl: 0 8px 32px rgba(31, 38, 135, 0.37);
      --border-glass: rgba(255, 255, 255, 0.2);
      --radius: 20px;
      --radius-sm: 12px;
      --radius-lg: 24px;
      --font-display: 'Poppins', sans-serif;
      --font-body: 'Inter', sans-serif;
      --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      --blur: blur(20px);
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html {
      scroll-behavior: smooth;
    }

    body {
      font-family: var(--font-body);
      background: var(--bg-light);
      background-attachment: fixed;
      color: var(--text);
      min-height: 100vh;
      overflow-x: hidden;
      letter-spacing: 0.01em;
      position: relative;
    }

    body::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.03)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.03)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.02)"/><circle cx="10" cy="50" r="0.5" fill="rgba(255,255,255,0.02)"/><circle cx="90" cy="30" r="0.5" fill="rgba(255,255,255,0.02)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
      pointer-events: none;
      z-index: -1;
    }

    h1,
    h2,
    h3,
    h4,
    h5,
    h6 {
      font-family: var(--font-display);
      color: var(--white);
      line-height: 1.25;
      letter-spacing: -0.03em;
    }

    .filter-title,
    .checkout-title,
    .checkout-section-title,
    .modal-title,
    .page-title {
      font-family: var(--font-display);
    }

    .filter-title {
      text-transform: none;
      letter-spacing: 0.2px;
      color: #FFFFFF;
    }

    .checkout-title {
      font-size: 34px;
      letter-spacing: -0.4px;
    }

    .checkout-section-title {
      font-size: 17px;
    }

    img {
      display: block;
      max-width: 100%;
    }

    button {
      cursor: pointer;
      border: none;
      background: none;
      font-family: var(--font-body);
    }

    a {
      text-decoration: none;
      color: inherit;
    }

    input,
    textarea,
    select {
      font-family: var(--font-body);
    }

    option {
      background: #0F172A;
      color: #FFFFFF;
    }

    ::-webkit-scrollbar {
      width: 6px;
    }

    ::-webkit-scrollbar-track {
      background: var(--gray-100);
    }

    ::-webkit-scrollbar-thumb {
      background: var(--blue);
      border-radius: 99px;
    }

    .navbar {
      position: sticky;
      top: 0;
      z-index: 100;
      background: var(--bg-glass-strong);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      border-bottom: 1px solid var(--border-glass);
      padding: 0 28px;
      height: 70px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: var(--shadow-lg);
    }

    .logo {
      font-family: var(--font-display);
      font-size: 20px;
      font-weight: 700;
      letter-spacing: -0.3px;
      display: flex;
      align-items: center;
      gap: 12px;
      color: var(--white);
    }

    .logo-icon {
      width: 36px;
      height: 36px;
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      border: 1px solid var(--border-glass);
      border-radius: 12px;
      display: grid;
      place-items: center;
      color: var(--white);
      font-size: 18px;
      box-shadow: var(--shadow-sm);
    }

    .logo span {
      opacity: 1;
      color: var(--white);
    }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .cart-btn {
      position: relative;
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      color: var(--white);
      border-radius: 12px;
      padding: 10px 16px;
      display: flex;
      align-items: center;
      gap: 8px;
      font-family: var(--font-display);
      font-size: 13px;
      font-weight: 600;
      transition: var(--transition);
      border: 1px solid var(--border-glass);
      box-shadow: var(--shadow-sm);
    }

    .cart-btn:hover {
      background: var(--bg-glass-strong);
      border-color: rgba(255, 255, 255, 0.3);
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
    }

    .cart-badge {
      position: absolute;
      top: -8px;
      right: -8px;
      background: var(--error);
      color: white;
      font-size: 10px;
      font-weight: 700;
      width: 20px;
      height: 20px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: var(--font-display);
      border: 2px solid var(--navy);
    }

    .page-shop {
      display: none;
    }

    .page-shop.active {
      display: block;
    }

    .page-checkout {
      display: none;
    }

    .page-checkout.active {
      display: block;
    }

    .shop-layout {
      display: grid;
      grid-template-columns: 280px 1fr;
      gap: 28px;
      max-width: 1320px;
      margin: 0 auto;
      padding: 32px 24px;
    }

    .filter-panel {
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-glass);
      padding: 28px;
      height: fit-content;
      position: sticky;
      top: 90px;
      box-shadow: var(--shadow-lg);
    }

    .filter-title {
      font-family: var(--font-display);
      font-size: 15px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 28px;
      color: #FFFFFF;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .filter-title svg {
      color: var(--blue);
    }

    .filter-label {
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.8px;
      color: #FFFFFF;
      text-transform: uppercase;
      margin-bottom: 12px;
      display: block;
    }

    .search-input {
      width: 100%;
      padding: 11px 14px;
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-sm);
      font-size: 14px;
      color: #FFFFFF;
      background: var(--bg-glass-light);
      transition: var(--transition);
      outline: none;
      margin-bottom: 24px;
    }

    .search-input::placeholder {
      color: var(--text-light);
    }

    .search-input:focus {
      border-color: var(--blue-light);
      background: var(--bg-glass);
      box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.2);
    }

    .kategori-group {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 28px;
    }

    .kat-btn {
      padding: 8px 14px;
      border-radius: 99px;
      font-size: 12px;
      font-weight: 600;
      border: 1px solid var(--border-glass);
      background: var(--bg-glass-light);
      color: #FFFFFF;
      transition: var(--transition);
    }

    .kat-btn:hover {
      border-color: var(--blue-light);
      color: var(--blue-light);
      background: var(--bg-glass);
    }

    .kat-btn.active {
      background: var(--blue);
      border-color: var(--blue);
      color: white;
    }

    .price-display {
      font-family: var(--font-display);
      font-size: 13px;
      font-weight: 700;
      color: var(--blue);
      float: right;
    }

    input[type="range"] {
      width: 100%;
      height: 5px;
      -webkit-appearance: none;
      background: linear-gradient(to right, var(--blue) var(--pct, 50%), var(--bg-glass-strong) var(--pct, 50%));
      border-radius: 99px;
      outline: none;
      cursor: pointer;
      margin-top: 10px;
    }

    input[type="range"]::-webkit-slider-thumb {
      -webkit-appearance: none;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: var(--blue);
      border: 2px solid white;
      box-shadow: 0 2px 6px rgba(15, 118, 110, 0.3);
      cursor: pointer;
    }

    .products-area {}

    .products-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 32px;
    }

    .product-card {
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-glass);
      overflow: hidden;
      transition: var(--transition);
      box-shadow: var(--shadow-sm);
      cursor: default;
    }

    .product-card:hover {
      transform: translateY(-8px) scale(1.02);
      box-shadow: var(--shadow-xl);
      border-color: rgba(255, 255, 255, 0.3);
      background: var(--bg-glass-strong);
    }

    .card-img-wrap {
      position: relative;
      padding-top: 72%;
      overflow: hidden;
      background: var(--bg-glass-light);
    }

    .card-img-wrap img {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform 0.35s ease;
    }

    .product-card:hover .card-img-wrap img {
      transform: scale(1.06);
    }

    .card-badge {
      position: absolute;
      top: 12px;
      left: 12px;
      background: var(--bg-glass-strong);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      color: #FFFFFF;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.5px;
      padding: 5px 10px;
      border-radius: 20px;
      text-transform: uppercase;
      border: 1px solid var(--border-glass);
      box-shadow: var(--shadow-sm);
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }

    .card-body {
      padding: 16px;
    }

    .card-nama {
      font-family: var(--font-display);
      font-size: 14px;
      font-weight: 700;
      color: #FFFFFF;
      margin-bottom: 4px;
      line-height: 1.4;
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }

    .card-warna {
      font-size: 12px;
      color: rgba(255, 255, 255, 0.9);
      margin-bottom: 12px;
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }

    .card-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding-top: 12px;
      border-top: 1px solid var(--border-glass);
    }

    .card-harga {
      font-family: var(--font-display);
      font-size: 16px;
      font-weight: 700;
      color: #FFFFFF;
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }

    .add-btn {
      width: 36px;
      height: 36px;
      border-radius: 12px;
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      color: var(--white);
      display: grid;
      place-items: center;
      font-size: 18px;
      transition: var(--transition);
      line-height: 1;
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--border-glass);
    }

    .add-btn:hover {
      transform: scale(1.1);
      box-shadow: var(--shadow-md);
      background: var(--bg-glass-strong);
    }

    .add-btn:active {
      transform: scale(0.95);
    }

    .add-btn.added {
      background: var(--success);
      border-color: var(--success);
    }

    .no-products {
      grid-column: 1/-1;
      text-align: center;
      padding: 60px 20px;
      color: #FFFFFF;
    }

    .no-products .icon {
      font-size: 48px;
      margin-bottom: 12px;
    }

    .float-checkout {
      position: fixed;
      bottom: 24px;
      right: 24px;
      z-index: 90;
      background: var(--bg-glass-strong);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      color: var(--white);
      padding: 14px 20px;
      border-radius: var(--radius-lg);
      display: flex;
      align-items: center;
      gap: 10px;
      font-family: var(--font-display);
      font-size: 14px;
      font-weight: 600;
      box-shadow: var(--shadow-xl);
      transition: var(--transition);
      border: 1px solid var(--border-glass);
      transform: translateY(100px);
      opacity: 0;
      pointer-events: none;
    }

    .float-checkout.visible {
      transform: translateY(0);
      opacity: 1;
      pointer-events: all;
    }

    .float-checkout:hover {
      transform: translateY(-4px) scale(1.05);
      box-shadow: 0 20px 40px rgba(31, 38, 135, 0.5);
      background: var(--bg-glass);
    }

    .float-checkout .f-badge {
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      color: var(--white);
      border-radius: 99px;
      font-size: 11px;
      padding: 2px 8px;
      font-weight: 700;
      border: 1px solid var(--border-glass);
    }

    footer {
      background: var(--navy);
      color: white;
      padding: 48px 28px;
      margin-top: 60px;
    }

    .footer-inner {
      max-width: 1320px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: 2fr 1fr 1fr;
      gap: 48px;
    }

    .footer-brand .logo {
      font-family: var(--font-display);
      font-size: 18px;
      font-weight: 700;
      color: white;
      margin-bottom: 12px;
    }

    .footer-brand .logo span {
      color: var(--blue);
    }

    .footer-tagline {
      font-size: 13px;
      color: rgba(255, 255, 255, 0.9);
      line-height: 1.6;
      max-width: 260px;
    }

    .footer-col h4 {
      font-family: var(--font-display);
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: rgba(255, 255, 255, 0.9);
      margin-bottom: 16px;
    }

    .footer-col a,
    .footer-col p {
      display: block;
      font-size: 13px;
      color: rgba(255, 255, 255, 0.8);
      margin-bottom: 10px;
      transition: var(--transition);
      cursor: pointer;
    }

    .footer-col a:hover {
      color: white;
    }

    .footer-bottom {
      max-width: 1320px;
      margin: 32px auto 0;
      padding-top: 24px;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
      font-size: 12px;
      color: rgba(255, 255, 255, 0.8);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .checkout-wrap {
      max-width: 1100px;
      margin: 0 auto;
      padding: 48px 28px;
    }

    .checkout-title {
      font-family: var(--font-display);
      font-size: 32px;
      font-weight: 700;
      margin-bottom: 32px;
      letter-spacing: -0.5px;
      color: var(--white);
    }

    .checkout-grid {
      display: grid;
      grid-template-columns: 1fr 380px;
      gap: 32px;
      align-items: start;
    }

    .checkout-card {
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-glass);
      padding: 32px;
      box-shadow: var(--shadow-lg);
      margin-bottom: 24px;
    }

    .checkout-section-title {
      font-family: var(--font-display);
      font-size: 16px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 24px;
      color: #FFFFFF;
      text-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
    }

    .checkout-section-title svg {
      color: #A5F3FC;
    }

    .form-field {
      margin-bottom: 18px;
    }

    .form-field input,
    .form-field textarea,
    .form-field select {
      width: 100%;
      padding: 11px 14px;
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-sm);
      font-size: 14px;
      background: var(--bg-glass-light);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      color: var(--white);
      outline: none;
      transition: var(--transition);
      resize: vertical;
    }

    .form-field input:focus,
    .form-field textarea:focus,
    .form-field select:focus {
      border-color: var(--blue-light);
      background: var(--bg-glass);
      box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.2);
    }

    .form-field input::placeholder,
    .form-field textarea::placeholder {
      color: var(--text-light);
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .metode-group {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
    }

    .metode-btn {
      padding: 12px;
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-sm);
      font-family: var(--font-display);
      font-size: 13px;
      font-weight: 600;
      background: var(--bg-glass-light);
      color: #FFFFFF;
      transition: var(--transition);
      text-align: center;
    }

    .metode-btn:hover {
      border-color: var(--blue-light);
      color: var(--blue-light);
      background: var(--bg-glass);
    }

    .metode-btn.active {
      background: var(--blue);
      border-color: var(--blue);
      color: white;
    }

    .cart-sidebar {
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-glass);
      padding: 28px;
      box-shadow: var(--shadow-lg);
      position: sticky;
      top: 90px;
    }

    .cart-sidebar h3 {
      font-family: var(--font-display);
      font-size: 16px;
      font-weight: 700;
      margin-bottom: 20px;
      color: var(--white);
    }

    .cart-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 0;
      border-bottom: 1px solid var(--border-glass);
      animation: fadeIn 0.3s ease;
    }

    .cart-item-img {
      width: 48px;
      height: 48px;
      border-radius: 8px;
      object-fit: cover;
      flex-shrink: 0;
      background: var(--bg-glass-light);
    }

    .cart-item-info {
      flex: 1;
      min-width: 0;
    }

    .cart-item-nama {
      font-size: 13px;
      font-weight: 600;
      color: #FFFFFF;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .cart-item-qty {
      font-size: 12px;
      color: #FFFFFF;
    }

    .cart-item-harga {
      font-family: var(--font-display);
      font-size: 13px;
      font-weight: 700;
      color: #FFFFFF;
      white-space: nowrap;
    }

    .cart-item-del {
      color: var(--error);
      font-size: 18px;
      line-height: 1;
      padding: 4px;
      border-radius: 6px;
      transition: var(--transition);
      flex-shrink: 0;
    }

    .cart-item-del:hover {
      background: rgba(220, 38, 38, 0.1);
    }

    .cart-summary {
      margin-top: 20px;
    }

    .summary-row {
      display: flex;
      justify-content: space-between;
      font-size: 13px;
      color: #FFFFFF;
      margin-bottom: 8px;
    }

    .summary-total {
      display: flex;
      justify-content: space-between;
      font-family: var(--font-display);
      font-size: 18px;
      font-weight: 700;
      color: #FFFFFF;
      padding-top: 16px;
      border-top: 1px solid var(--border-glass);
      margin-top: 16px;
    }

    .order-btn {
      width: 100%;
      margin-top: 20px;
      padding: 13px;
      background: var(--blue);
      color: white;
      border-radius: var(--radius-sm);
      font-family: var(--font-display);
      font-size: 14px;
      font-weight: 700;
      transition: var(--transition);
      border: none;
      box-shadow: var(--shadow-sm);
    }

    .order-btn:hover {
      background: var(--blue-light);
      box-shadow: var(--shadow-md);
    }

    .order-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .back-btn {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      font-weight: 600;
      color: #FFFFFF;
      margin-bottom: 20px;
      padding: 8px 12px;
      border-radius: 8px;
      transition: var(--transition);
      border: none;
      background: none;
      cursor: pointer;
    }

    .back-btn:hover {
      background: var(--bg-glass-strong);
      color: var(--blue-light);
    }

    /* Empty cart */
    .empty-cart {
      text-align: center;
      padding: 40px 20px;
      color: #FFFFFF;
    }

    .empty-cart .icon {
      font-size: 48px;
      margin-bottom: 12px;
    }

    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      z-index: 200;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.25s;
    }

    .modal-overlay.open {
      opacity: 1;
      pointer-events: all;
    }

    /* Perubahan untuk modal putih agar teks di dalamnya gelap */
    .modal-box {
      background: rgba(255, 255, 255, 0.98);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-glass);
      padding: 48px 40px;
      max-width: 460px;
      width: 100%;
      text-align: center;
      transform: scale(0.95) translateY(10px);
      transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: var(--shadow-xl);
    }

    .modal-overlay.open .modal-box {
      transform: scale(1) translateY(0);
    }

    .modal-customer-info {
      background: #F8FAFC;
      border-radius: 8px;
      padding: 16px;
      margin: 16px 0;
      text-align: left;
      border: 1px solid #E2E8F0;
    }

    .info-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 8px;
      font-size: 14px;
    }

    .info-row:last-child {
      margin-bottom: 0;
    }

    .info-row strong {
      color: #0F172A;
      min-width: 80px;
      font-weight: 600;
    }

    .info-row span {
      color: #334155;
      text-align: right;
      flex: 1;
      font-weight: 500;
    }

    .modal-icon {
      width: 80px;
      height: 80px;
      background: rgba(22, 163, 74, 0.12);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 40px;
      margin: 0 auto 24px;
    }

    .modal-title {
      font-family: var(--font-display);
      font-size: 24px;
      font-weight: 700;
      margin-bottom: 12px;
      color: #0F172A;
    }

    .modal-kode {
      display: inline-block;
      background: #F1F5F9;
      border-radius: 8px;
      padding: 12px 24px;
      font-family: var(--font-display);
      font-size: 16px;
      font-weight: 700;
      color: #0F766E;
      letter-spacing: 0.5px;
      margin: 16px 0;
      border: 1px solid #E2E8F0;
    }

    .modal-info {
      font-size: 13px;
      color: #475569;
      margin-bottom: 28px;
      line-height: 1.7;
    }

    .modal-btn {
      background: var(--blue);
      color: white;
      border: none;
      border-radius: var(--radius-sm);
      padding: 13px 36px;
      font-family: var(--font-display);
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      transition: var(--transition);
      box-shadow: var(--shadow-sm);
    }

    .modal-btn:hover {
      background: var(--blue-light);
      box-shadow: var(--shadow-md);
    }

    .modal-buttons {
      display: flex;
      gap: 12px;
      justify-content: center;
    }

    .modal-btn.secondary {
      background: #F1F5F9;
      color: #0F172A;
      border: 1px solid #E2E8F0;
    }

    .modal-btn.secondary:hover {
      background: #E2E8F0;
    }

    .modal-btn.primary {
      background: var(--success);
    }

    .modal-btn.primary:hover {
      background: #16a34a;
    }

    /* Modal khusus produk menggunakan warna gelap/glass */
    .product-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      z-index: 200;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.25s;
    }

    .product-modal-overlay.open {
      opacity: 1;
      pointer-events: all;
    }

    .product-modal-box {
      background: var(--bg-glass-strong);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-glass);
      padding: 32px;
      max-width: 500px;
      width: 100%;
      max-height: 80vh;
      overflow-y: auto;
      transform: scale(0.95) translateY(10px);
      transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: var(--shadow-xl);
    }

    .product-modal-overlay.open .product-modal-box {
      transform: scale(1) translateY(0);
    }

    .product-modal-close {
      position: absolute;
      top: 16px;
      right: 16px;
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      border: 1px solid var(--border-glass);
      border-radius: 50%;
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: var(--transition);
      color: var(--white);
      box-shadow: var(--shadow-sm);
    }

    .product-modal-close:hover {
      background: var(--error);
      border-color: var(--error);
      color: white;
      transform: scale(1.1);
    }

    .product-modal-img {
      width: 100%;
      height: 250px;
      object-fit: cover;
      border-radius: 12px;
      margin-bottom: 20px;
      background: var(--bg-glass-light);
    }

    .product-modal-title {
      font-family: var(--font-display);
      font-size: 24px;
      font-weight: 700;
      margin-bottom: 8px;
      color: var(--white);
    }

    .product-modal-category {
      display: inline-block;
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      color: var(--white);
      font-size: 12px;
      font-weight: 600;
      padding: 4px 12px;
      border-radius: 99px;
      margin-bottom: 16px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border: 1px solid var(--border-glass);
    }

    .product-modal-price {
      font-family: var(--font-display);
      font-size: 20px;
      font-weight: 700;
      color: var(--blue-light);
      margin-bottom: 16px;
    }

    .product-modal-desc {
      font-size: 14px;
      color: var(--white);
      line-height: 1.6;
      margin-bottom: 24px;
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      padding: 16px;
      border-radius: 12px;
      border: 1px solid var(--border-glass);
    }

    .product-modal-footer {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    .product-modal-add {
      flex: 1;
      padding: 12px 20px;
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      color: var(--white);
      border: 1px solid var(--border-glass);
      border-radius: 8px;
      font-family: var(--font-display);
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .product-modal-add:hover {
      background: var(--bg-glass-strong);
      transform: translateY(-1px);
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
    }

    .whatsapp-float {
      position: fixed;
      left: 16px;
      bottom: 16px;
      z-index: 320;
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: #25D366;
      color: white;
      display: grid;
      place-items: center;
      font-family: var(--font-display);
      font-weight: 700;
      text-decoration: none;
      box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .whatsapp-float:hover {
      transform: translateY(-2px);
      box-shadow: 0 16px 28px rgba(0, 0, 0, 0.2);
    }

    .skeleton {
      background: linear-gradient(90deg, var(--bg-glass-light) 25%, var(--bg-glass-strong) 50%, var(--bg-glass-light) 75%);
      background-size: 200% 100%;
      animation: shimmer 1.5s infinite;
      border-radius: var(--radius);
    }

    @keyframes shimmer {
      0% {
        background-position: 200% 0
      }

      100% {
        background-position: -200% 0
      }
    }

    .skel-card {
      height: 320px;
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(8px)
      }

      to {
        opacity: 1;
        transform: none
      }
    }

    .product-card {
      animation: fadeIn 0.35s ease both;
    }

    @media (max-width: 900px) {
      .shop-layout {
        grid-template-columns: 1fr;
        padding: 16px;
        gap: 16px;
      }

      .filter-panel {
        position: static;
      }

      .kategori-group {
        flex-direction: row;
      }

      .checkout-grid {
        grid-template-columns: 1fr;
      }

      .cart-sidebar {
        position: static;
      }

      .footer-inner {
        grid-template-columns: 1fr 1fr;
      }

      .footer-brand {
        grid-column: 1/-1;
      }
    }

    @media (max-width: 600px) {
      .navbar {
        padding: 0 16px;
      }

      .logo-text {
        display: none;
      }

      .products-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
      }

      .checkout-wrap {
        padding: 20px 16px;
      }

      .checkout-title {
        font-size: 24px;
      }

      .form-row {
        grid-template-columns: 1fr;
      }

      .metode-group {
        grid-template-columns: repeat(3, 1fr);
      }

      .footer-inner {
        grid-template-columns: 1fr;
      }

      .footer-bottom {
        flex-direction: column;
        gap: 8px;
        text-align: center;
      }

      .float-checkout {
        bottom: 16px;
        right: 16px;
        left: 16px;
        justify-content: center;
      }

      .modal-box {
        padding: 28px 20px;
      }
    }

    @media (max-width: 380px) {
      .products-grid {
        grid-template-columns: 1fr;
      }
    }

    .toast {
      position: fixed;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%) translateY(100px);
      background: var(--bg-glass);
      backdrop-filter: var(--blur);
      -webkit-backdrop-filter: var(--blur);
      color: var(--white);
      padding: 12px 24px;
      border-radius: 12px;
      font-family: var(--font-display);
      font-size: 14px;
      font-weight: 500;
      border: 1px solid var(--border-glass);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
      z-index: 300;
      opacity: 0;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .toast.show {
      transform: translateX(-50%) translateY(0);
      opacity: 1;
    }
  </style>
</head>

<body>

  <nav class="navbar">
    <div class="logo">
      <div class="logo-icon">🐟</div>
      <span class="logo-text">YOSEP<span>FISH</span></span>
    </div>
    <div class="nav-actions">
      <button class="cart-btn" id="cartNavBtn" onclick="showPage('checkout')">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <circle cx="9" cy="21" r="1" />
          <circle cx="20" cy="21" r="1" />
          <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
        </svg>
        <span id="cartCountNav">0</span>
      </button>
    </div>
  </nav>

  <div id="pageShop" class="page-shop active">
    <div class="shop-layout">

      <aside class="filter-panel">
        <div class="filter-title">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
          </svg>
          Filter
        </div>

        <label class="filter-label">Cari</label>
        <input class="search-input" id="searchInput" type="text" placeholder="Nama ikan..." oninput="debounceFilter()">

        <label class="filter-label">Kategori</label>
        <div class="kategori-group" id="kategoriGroup">
          <?php
          $cats = ['Semua', 'Plakat', 'Halfmoon', 'Crowntail', 'Giant', 'Fancy'];
          foreach ($cats as $c) {
            $active = $c === 'Semua' ? 'active' : '';
            echo "<button class='kat-btn $active' data-kat='$c' onclick='setKategori(this)'>$c</button>";
          }
          ?>
        </div>

        <label class="filter-label">Harga Maks <span class="price-display" id="priceDisplay">Rp 1.000.000</span></label>
        <input type="range" id="priceRange" min="50000" max="1000000" step="50000" value="1000000"
          oninput="updatePrice(this)" style="--pct:100%">
      </aside>

      <div class="products-area">
        <div class="products-grid" id="productsGrid">

          <div class="skeleton skel-card"></div>
          <div class="skeleton skel-card"></div>
          <div class="skeleton skel-card"></div>
          <div class="skeleton skel-card"></div>
          <div class="skeleton skel-card"></div>
          <div class="skeleton skel-card"></div>
        </div>
      </div>

    </div>

    <footer>
      <div class="footer-inner">
        <div class="footer-brand">
          <div class="logo" style="color:white">
            <div class="logo-icon">🐟</div>
            <span>YOSEP<span>FISH</span></span>
          </div>
          <p class="footer-tagline">Betta Store eksklusif. Kualitas kontes dengan pengiriman bergaransi hidup ke seluruh Indonesia.</p>
        </div>
        <div class="footer-col">
          <h4>Navigasi</h4>
          <a onclick="showPage('shop')">Koleksi Ikan</a>
          <a onclick="showPage('checkout')">Keranjang</a>
          <a>Garansi</a>
        </div>
        <div class="footer-col">
          <h4>Kontak</h4>
          <p>Tangerang, Banten</p>
          <p>+62 877 5013 5143</p>
        </div>
      </div>
      <div class="footer-bottom">
        <span>© 2026 YosepFish. All rights reserved.</span>
        <span>Made with 🐠 in Indonesia</span>
      </div>
    </footer>
  </div>

  <div id="pageCheckout" class="page-checkout">
    <div class="checkout-wrap">
      <button class="back-btn" onclick="confirmPayment()">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
          <path d="M19 12H5m7-7-7 7 7 7" />
        </svg>
        Konfirmasi Pembayaran
      </button>
      <h1 class="checkout-title">CHECKOUT</h1>

      <div class="checkout-grid">
        <div>
          <div class="checkout-card">
            <div class="checkout-section-title">
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                <circle cx="12" cy="10" r="3" />
              </svg>
              Data Pengiriman
            </div>
            <div class="form-field">
              <input type="text" id="namaPenerima" placeholder="Nama Penerima">
            </div>
            <div class="form-row">
              <div class="form-field">
                <input type="tel" id="telepon" placeholder="08xxxx">
              </div>
              <div class="form-field">
                <select id="ekspedisi">
                  <option>J&T Express (Standard)</option>
                  <option>J&T Express (Express)</option>
                  <option>SiCepat REG</option>
                  <option>SiCepat BEST</option>
                  <option>AnterAja</option>
                  <option>Gosend Same Day</option>
                </select>
              </div>
            </div>
            <div class="form-field">
              <textarea id="alamat" rows="4" placeholder="Alamat lengkap rumah..."></textarea>
            </div>
            <div class="form-field">
              <select id="region" onchange="renderCartCheckout()">
                <option value="Jabodetabek">Jabodetabek (Rp 20.000)</option>
                <option value="Luar Jabodetabek">Luar Jabodetabek (Rp 40.000)</option>
                <option value="Luar Pulau Jawa">Luar Pulau Jawa (Rp 60.000)</option>
              </select>
            </div>
          </div>

          <div class="checkout-card">
            <div class="checkout-section-title">
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <rect x="1" y="4" width="22" height="16" rx="2" />
                <line x1="1" y1="10" x2="23" y2="10" />
              </svg>
              Metode Pembayaran (QRIS / QR Code)
            </div>
            <div style="text-align:center; padding: 16px; background: var(--bg-glass-light); border-radius: 12px; border: 1px solid var(--border-glass);">
              <p style="font-size: 13px; color: #FFFFFF; margin-bottom: 16px; line-height: 1.5;">
                Silakan scan QR Code di bawah ini menggunakan aplikasi e-Wallet atau M-Banking Anda.
              </p>

              <img src="assets/img/QRCode.jpeg"
                alt="QR Code Pembayaran"
                style="width: 200px; height: 200px; object-fit: contain; margin: 0 auto; border-radius: 12px; border: 4px solid white; box-shadow: var(--shadow-sm);">

              <p style="font-size: 12px; color: #A5F3FC; margin-top: 16px; font-weight: 600;">
                * Wajib screenshot bukti pembayaran untuk dikirim via WhatsApp.
              </p>
            </div>
          </div>
        </div>

        <div class="cart-sidebar">
          <h3>Keranjang</h3>
          <div id="cartItemsEl"></div>
          <div class="cart-summary" id="cartSummary" style="display:none">
            <div class="summary-row"><span>Subtotal</span><span id="subtotalEl">—</span></div>
            <div class="summary-row"><span>Ongkir + Box</span><span id="ongkirEl">Rp 20.000</span></div>
            <div class="summary-total"><span>Bayar</span><span id="totalEl">—</span></div>
            <button class="order-btn" id="orderBtn" onclick="buatPesanan()">Buat Pesanan</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <button class="float-checkout" id="floatCheckout" onclick="showPage('checkout')">
    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="9" cy="21" r="1" />
      <circle cx="20" cy="21" r="1" />
      <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
    </svg>
    Checkout
    <span class="f-badge" id="floatBadge">0</span>
  </button>

  <div class="modal-overlay" id="successModal">
    <div class="modal-box">
      <div class="modal-icon">✅</div>
      <div class="modal-title">Pesanan Dibuat!</div>
      <div class="modal-kode" id="modalKode">—</div>
      <div class="modal-customer-info">
        <div class="info-row"><strong>Nama:</strong> <span id="modalNama">—</span></div>
        <div class="info-row"><strong>Telepon:</strong> <span id="modalTelepon">—</span></div>
        <div class="info-row"><strong>Alamat:</strong> <span id="modalAlamat">—</span></div>
        <div class="info-row"><strong>Ekspedisi:</strong> <span id="modalEkspedisi">—</span></div>
        <div class="info-row"><strong>Wilayah:</strong> <span id="modalWilayah">—</span></div>
      </div>
      <p class="modal-info">
        Pembayaran via <strong id="modalMetode" style="color:#0F172A">—</strong>.<br>
        Subtotal: <strong id="modalSubtotal" style="color:#0F172A">—</strong> | Ongkir: <strong id="modalOngkir" style="color:#0F172A">—</strong><br>
        <span style="font-size: 16px; color: #0F766E;"><strong>Total Bayar: <span id="modalTotal">—</span></strong></span><br><br>
        Jika sudah scan QR Code & bayar, klik tombol di bawah untuk melampirkan <strong style="color:#0F172A">Bukti Screenshot</strong> via WhatsApp.
      </p>
      <div class="modal-buttons">
        <button class="modal-btn secondary" onclick="closeModal()">Kembali Belanja</button>
        <button class="modal-btn primary" onclick="confirmPaymentFromModal()">Konfirmasi Pembayaran</button>
      </div>
    </div>
  </div>

  <div class="product-modal-overlay" id="productModal">
    <div class="product-modal-box">
      <button class="product-modal-close" onclick="closeProductModal()">×</button>
      <img id="productModalImg" class="product-modal-img" src="" alt="">
      <h2 id="productModalTitle" class="product-modal-title"></h2>
      <span id="productModalCategory" class="product-modal-category"></span>
      <div id="productModalPrice" class="product-modal-price"></div>
      <div id="productModalDesc" class="product-modal-desc"></div>
      <div class="product-modal-footer">
        <button id="productModalAddBtn" class="product-modal-add" onclick="addToCartFromModal()">Tambah ke Keranjang</button>
      </div>
    </div>
  </div>

  <div class="toast" id="toast"></div>

  <script>
    let filterTimer = null;
    let currentKategori = 'Semua';
    let currentMetode = 'QR Code';
    let cart = [];
    const shippingRates = {
      'Jabodetabek': 20000,
      'Luar Jabodetabek': 40000,
      'Luar Pulau Jawa': 60000,
    };

    const fmt = n => 'Rp ' + n.toLocaleString('id-ID');

    function showPage(page) {
      document.getElementById('pageShop').classList.toggle('active', page === 'shop');
      document.getElementById('pageCheckout').classList.toggle('active', page === 'checkout');
      if (page === 'checkout') renderCartCheckout();
      window.scrollTo(0, 0);
    }

    function loadProducts() {
      const search = document.getElementById('searchInput').value;
      const harga = document.getElementById('priceRange').value;
      const kat = currentKategori;

      const params = new URLSearchParams({
        action: 'get_products',
        kategori: kat,
        search,
        max_harga: harga
      });

      fetch('?' + params)
        .then(r => r.json())
        .then(res => {
          const grid = document.getElementById('productsGrid');
          if (!res.success || !res.data.length) {
            grid.innerHTML = '<div class="no-products"><div class="icon">🐟</div><p>Tidak ada ikan ditemukan</p></div>';
            return;
          }
          grid.innerHTML = '';
          res.data.forEach((p, i) => {
            const card = document.createElement('div');
            card.className = 'product-card';
            card.style.animationDelay = (i * 0.04) + 's';
            card.onclick = () => showProductModal(p);
            card.innerHTML = `
          <div class="card-img-wrap">
            <img src="${p.gambar_url || 'https://via.placeholder.com/400x300/0B1628/FFFFFF?text=🐟'}"
                 alt="${p.nama}" loading="lazy" onerror="this.src='https://via.placeholder.com/400x300/0B1628/FFFFFF?text=🐟'">
            <span class="card-badge">${p.kategori}</span>
          </div>
          <div class="card-body">
            <div class="card-nama">${p.nama}</div>
            <div class="card-warna">${p.warna || '—'}</div>
            <div class="card-footer">
              <span class="card-harga">${fmt(p.harga)}</span>
              <button class="add-btn" id="add-${p.id}" onclick="event.stopPropagation(); addToCart(${p.id}, this)" title="Tambah ke keranjang">+</button>
            </div>
          </div>`;
            grid.appendChild(card);
          });
        })
        .catch(() => {
          document.getElementById('productsGrid').innerHTML =
            '<div class="no-products"><div class="icon">⚠️</div><p>Gagal memuat produk. Cek koneksi database.</p></div>';
        });
    }

    function debounceFilter() {
      clearTimeout(filterTimer);
      filterTimer = setTimeout(loadProducts, 350);
    }

    function setKategori(el) {
      document.querySelectorAll('.kat-btn').forEach(b => b.classList.remove('active'));
      el.classList.add('active');
      currentKategori = el.dataset.kat;
      loadProducts();
    }

    function updatePrice(input) {
      const val = parseInt(input.value);
      const pct = ((val - 50000) / (1000000 - 50000)) * 100;
      input.style.setProperty('--pct', pct + '%');
      document.getElementById('priceDisplay').textContent = fmt(val);
      debounceFilter();
    }

    function addToCart(id, btn) {
      const fd = new FormData();
      fd.append('action', 'add_to_cart');
      fd.append('id', id);

      btn.disabled = true;
      btn.classList.add('added');
      btn.textContent = '✓';
      setTimeout(() => {
        btn.disabled = false;
        btn.classList.remove('added');
        btn.textContent = '+';
      }, 1200);

      fetch('', {
          method: 'POST',
          body: fd
        })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            cart = res.keranjang;
            updateCartUI(res.count);
            showToast('Ditambahkan ke keranjang 🐟');
          }
        });
    }

    function removeFromCart(id) {
      const fd = new FormData();
      fd.append('action', 'remove_from_cart');
      fd.append('id', id);

      fetch('', {
          method: 'POST',
          body: fd
        })
        .then(r => r.json())
        .then(res => {
          cart = res.keranjang;
          updateCartUI(res.count);
          renderCartCheckout();
        });
    }

    function updateCartUI(count) {
      document.getElementById('cartCountNav').textContent = count;
      document.getElementById('floatBadge').textContent = count;

      const badge = document.querySelector('.cart-badge') || document.createElement('span');
      const nav = document.querySelector('.cart-btn .cart-badge');
      if (nav) {
        nav.classList.add('bump');
        setTimeout(() => nav.classList.remove('bump'), 300);
      }

      const fc = document.getElementById('floatCheckout');
      fc.classList.toggle('visible', count > 0);
    }

    function renderCartCheckout() {
      const el = document.getElementById('cartItemsEl');
      const summary = document.getElementById('cartSummary');

      fetch('?action=get_cart')
        .then(r => r.json())
        .then(res => {
          cart = res.keranjang || [];
          updateCartUI(res.count);

          if (!cart.length) {
            el.innerHTML = '<div class="empty-cart"><div class="icon">🛒</div><p>Keranjang masih kosong</p></div>';
            summary.style.display = 'none';
            return;
          }

          el.innerHTML = cart.map(k => `
        <div class="cart-item" id="ci-${k.id}">
          <img class="cart-item-img" src="${k.gambar || 'https://via.placeholder.com/96/0B1628/fff?text=🐟'}"
               alt="${k.nama}" onerror="this.src='https://via.placeholder.com/96/0B1628/fff?text=🐟'">
          <div class="cart-item-info">
            <div class="cart-item-nama">${k.nama}</div>
            <div class="cart-item-qty">x${k.qty}</div>
          </div>
          <div class="cart-item-harga">${fmt(k.harga * k.qty)}</div>
          <button class="cart-item-del" onclick="removeFromCart(${k.id})">✕</button>
        </div>`).join('');

          const sub = res.subtotal;
          const region = document.getElementById('region') ? document.getElementById('region').value : 'Jabodetabek';
          const ongkir = shippingRates[region] ?? 20000;
          document.getElementById('subtotalEl').textContent = fmt(sub);
          document.getElementById('ongkirEl').textContent = fmt(ongkir);
          document.getElementById('totalEl').textContent = fmt(sub + ongkir);
          summary.style.display = 'block';
        });
    }


    function buatPesanan() {
      const nama = document.getElementById('namaPenerima').value.trim();
      const telepon = document.getElementById('telepon').value.trim();
      const alamat = document.getElementById('alamat').value.trim();
      const eks = document.getElementById('ekspedisi').value;
      const wilayah = document.getElementById('region').value;

      if (!nama) {
        showToast('⚠️ Isi nama penerima');
        document.getElementById('namaPenerima').focus();
        return;
      }
      if (!telepon) {
        showToast('⚠️ Isi nomor telepon');
        document.getElementById('telepon').focus();
        return;
      }
      if (!alamat) {
        showToast('⚠️ Isi alamat pengiriman');
        document.getElementById('alamat').focus();
        return;
      }

      const btn = document.getElementById('orderBtn');
      btn.disabled = true;
      btn.textContent = 'Memproses...';

      const fd = new FormData();
      fd.append('action', 'buat_pesanan');
      fd.append('nama', nama);
      fd.append('telepon', telepon);
      fd.append('alamat', alamat);
      fd.append('ekspedisi', eks);
      fd.append('wilayah', wilayah);
      fd.append('metode_bayar', currentMetode);

      fetch('', {
          method: 'POST',
          body: fd
        })
        .then(r => r.json())
        .then(res => {
          btn.disabled = false;
          btn.textContent = 'Buat Pesanan';
          if (res.success) {
            window.currentOrderData = res;

            document.getElementById('modalKode').textContent = res.kode;
            document.getElementById('modalMetode').textContent = res.metode;
            document.getElementById('modalOngkir').textContent = fmt(res.ongkir || 0);
            document.getElementById('modalTotal').textContent = fmt(res.total);
            document.getElementById('modalSubtotal').textContent = fmt(res.subtotal || 0);
            document.getElementById('modalNama').textContent = res.nama;
            document.getElementById('modalTelepon').textContent = res.telepon;
            document.getElementById('modalAlamat').textContent = res.alamat;
            document.getElementById('modalEkspedisi').textContent = res.ekspedisi;
            document.getElementById('modalWilayah').textContent = res.wilayah;
            document.getElementById('successModal').classList.add('open');

            updateCartUI(0);
            renderCartCheckout();
          } else {
            showToast('❌ ' + (res.message || 'Gagal membuat pesanan'));
          }
        })
        .catch(() => {
          btn.disabled = false;
          btn.textContent = 'Buat Pesanan';
          showToast('❌ Koneksi gagal');
        });
    }

    function closeModal() {
      document.getElementById('successModal').classList.remove('open');
      // Clear order data
      window.currentOrderData = null;
      // reset form
      ['namaPenerima', 'telepon', 'alamat'].forEach(id => document.getElementById(id).value = '');
      showPage('shop');
    }

    let currentProduct = null;

    function showProductModal(product) {
      currentProduct = product;
      document.getElementById('productModalImg').src = product.gambar_url || 'https://via.placeholder.com/400x300/0B1628/FFFFFF?text=🐟';
      document.getElementById('productModalImg').alt = product.nama;
      document.getElementById('productModalTitle').textContent = product.nama;
      document.getElementById('productModalCategory').textContent = product.kategori;
      document.getElementById('productModalPrice').textContent = fmt(product.harga);
      document.getElementById('productModalDesc').textContent = product.deskripsi || 'Deskripsi produk tidak tersedia.';
      document.getElementById('productModal').classList.add('open');
    }

    function closeProductModal() {
      document.getElementById('productModal').classList.remove('open');
      currentProduct = null;
    }

    function addToCartFromModal() {
      if (!currentProduct) return;
      const btn = document.getElementById('productModalAddBtn');
      btn.disabled = true;
      btn.textContent = 'Menambah...';

      const fd = new FormData();
      fd.append('action', 'add_to_cart');
      fd.append('id', currentProduct.id);

      fetch('', {
          method: 'POST',
          body: fd
        })
        .then(r => r.json())
        .then(res => {
          btn.disabled = false;
          btn.textContent = 'Tambah ke Keranjang';
          if (res.success) {
            cart = res.keranjang;
            updateCartUI(res.count);
            showToast('Ditambahkan ke keranjang 🐟');
            closeProductModal();
          }
        });
    }

    function generateOrderMessage(orderData) {
      const items = cart.map(item =>
        `🐟 ${item.nama}\n   ${item.jumlah}x @ Rp ${fmt(item.harga)} = Rp ${fmt(item.jumlah * item.harga)}`
      ).join('\n\n');

      const message = `   *PESANAN BARU - YOSEPFISH*\n` +
        `  *=============================*\n` +
        `  *Kode Pesanan: ${orderData.kode}*\n` +
        `  *=============================*\n` +
        `  *Detail Pesanan:*\n` +
        `  *-----------------------------------------------*\n` +
        `${items}` +
        `  *Pengiriman:*\n` +
        `   Nama: ${orderData.nama}\n` +
        `   Telepon: ${orderData.telepon}\n` +
        `   Alamat: ${orderData.alamat}\n` +
        `   Ekspedisi: ${orderData.ekspedisi}\n` +
        `   Wilayah: ${orderData.wilayah}\n` +
        `  *=============================*\n` +
        `  *Pembayaran:*\n` +
        `   Metode: ${orderData.metode}\n` +
        `   Subtotal: Rp ${fmt(orderData.subtotal || 0)}\n` +
        `   Ongkir: Rp ${fmt(orderData.ongkir || 0)}\n` +
        `   Total Bayar: Rp ${fmt(orderData.total)}\n` +
        `  *=============================*\n\n` +
        `   ✅ *Saya sudah melakukan pembayaran via QR Code.*\n` +
        `   *(Silakan lampirkan gambar/screenshot bukti transfer Anda di chat ini)* `;

      return message;
    }

    function confirmPaymentFromModal() {
      if (!window.currentOrderData) {
        showToast('❌ Data pesanan tidak ditemukan');
        return;
      }

      const orderData = window.currentOrderData;
      const message = generateOrderMessage(orderData);
      const whatsappUrl = `https://wa.me/6287750135143?text=${encodeURIComponent(message)}`;

      window.open(whatsappUrl, '_blank');
      showToast('✅ Mengalihkan ke WhatsApp...');

      closeModal();
    }

    function showToast(msg) {
      const t = document.getElementById('toast');
      t.textContent = msg;
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 2200);
    }

    document.addEventListener('DOMContentLoaded', () => {
      loadProducts();
      fetch('?action=get_cart')
        .then(r => r.json())
        .then(res => updateCartUI(res.count || 0));

      document.getElementById('productModal').addEventListener('click', (e) => {
        if (e.target.id === 'productModal') closeProductModal();
      });
    });
  </script>
</body>

</html>