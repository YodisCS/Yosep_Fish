<?php
session_start();
require_once 'config/db.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_depan    = sanitize($_POST['nama_depan']    ?? '');
    $nama_belakang = sanitize($_POST['nama_belakang'] ?? '');
    $no_hp         = sanitize($_POST['no_hp']         ?? '');
    $email         = trim(strtolower($_POST['email']  ?? ''));
    $password      = $_POST['password']               ?? '';
    $pw_confirm    = $_POST['password_confirm']       ?? '';

    if (empty($nama_depan) || empty($nama_belakang) || empty($no_hp) || empty($email) || empty($password)) {
        $error = 'Semua field wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (!preg_match('/^\d{8,13}$/', preg_replace('/\s+/', '', $no_hp))) {
        $error = 'Nomor HP tidak valid (8–13 digit angka).';
    } elseif (strlen($password) < 8) {
        $error = 'Kata sandi minimal 8 karakter.';
    } elseif ($password !== $pw_confirm) {
        $error = 'Konfirmasi sandi tidak cocok.';
    } elseif (!isset($_POST['terms'])) {
        $error = 'Anda harus menyetujui Syarat & Ketentuan.';
    } else {
        $chk = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1");
        $chk->bind_param('s', $email);
        $chk->execute();
        $chk->store_result();
        $exists = $chk->num_rows > 0;
        $chk->close();

        if ($exists) {
            $error = 'Email sudah terdaftar. Silakan masuk atau gunakan email lain.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins  = $conn->prepare(
                "INSERT INTO users (nama_depan, nama_belakang, email, password, no_hp, role)
                 VALUES (?, ?, ?, ?, ?, 'pelanggan')"
            );
            $ins->bind_param('sssss', $nama_depan, $nama_belakang, $email, $hash, $no_hp);

            if ($ins->execute()) {
                redirect('login.php', 'Akun berhasil dibuat! Silakan masuk.', 'success');
            } else {
                $error = 'Terjadi kesalahan server. Silakan coba lagi.';
            }
            $ins->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Daftar — Yosep Fish</title>
  <link rel="stylesheet" href="assets/auth.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐠</text></svg>">
</head>
<body>

<div class="auth-wrapper">

  <div class="auth-left">
    <a href="index.php" class="auth-left-logo">YOSEP FISH</a>
    <div class="auth-left-content">
      <p>Bergabunglah dengan komunitas pecinta ikan hias terbesar dan dapatkan penawaran eksklusif setiap minggunya.</p>
      <div class="auth-features">
        <div class="auth-feature">
          <div class="auth-feature-icon">✓</div>
          <span>Akses Katalog Lengkap</span>
        </div>
        <div class="auth-feature">
          <div class="auth-feature-icon">🚚</div>
          <span>Lacak Pesanan Real-time</span>
        </div>
        <div class="auth-feature">
          <div class="auth-feature-icon">%</div>
          <span>Diskon Member Khusus</span>
        </div>
      </div>
    </div>
    <div class="auth-left-footer">© 2026 Yosep Fish. Quality First.</div>
  </div>

  <div class="auth-right">

    <div class="auth-tabs" style="max-width:480px;width:100%">
      <button class="auth-tab"        data-href="login.php">Masuk</button>
      <button class="auth-tab active" data-href="register.php">Daftar</button>
    </div>

    <div class="auth-form-container">
      <h2>Buat Akun Baru</h2>
      <p class="subtitle">Lengkapi data diri Anda untuk mendaftar</p>

      <?php if ($error): ?>
      <div class="auth-alert auth-alert-error show">
        <span>⚠️</span>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <form id="registerForm" method="POST" action="register.php" novalidate>

        <div class="form-row">
          <div class="form-group">
            <label for="nama_depan">Nama Depan</label>
            <div class="input-wrapper">
              <input type="text" id="nama_depan" name="nama_depan"
                placeholder="Yosep"
                value="<?= htmlspecialchars($_POST['nama_depan'] ?? '') ?>"
                autocomplete="given-name" required>
            </div>
          </div>
          <div class="form-group">
            <label for="nama_belakang">Nama Belakang</label>
            <div class="input-wrapper">
              <input type="text" id="nama_belakang" name="nama_belakang"
                placeholder="Fish"
                value="<?= htmlspecialchars($_POST['nama_belakang'] ?? '') ?>"
                autocomplete="family-name" required>
            </div>
          </div>
        </div>

        <div class="form-group phone-group">
          <label for="no_hp">Nomor HP / WhatsApp</label>
          <div class="input-wrapper">
            <span class="phone-prefix">+62</span>
            <input type="tel" id="no_hp" name="no_hp"
              placeholder="812345678"
              value="<?= htmlspecialchars($_POST['no_hp'] ?? '') ?>"
              autocomplete="tel" required>
          </div>
        </div>

        <div class="form-group">
          <label for="email">Email</label>
          <div class="input-wrapper">
            <input type="email" id="email" name="email"
              placeholder="yosep@fish.com"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              autocomplete="email" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="password">Kata Sandi</label>
            <div class="input-wrapper">
              <input type="password" id="password" name="password"
                placeholder="Min. 8 karakter"
                autocomplete="new-password" required>
              <button type="button" class="toggle-pw" aria-label="Tampilkan">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                  fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
          </div>
          <div class="form-group">
            <label for="password_confirm">Konfirmasi Sandi</label>
            <div class="input-wrapper">
              <input type="password" id="password_confirm" name="password_confirm"
                placeholder="Ulangi sandi"
                autocomplete="new-password" required>
              <button type="button" class="toggle-pw" aria-label="Tampilkan">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                  fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
          </div>
        </div>

        <div class="terms-row">
          <input type="checkbox" id="terms" name="terms"
            <?= isset($_POST['terms']) ? 'checked' : '' ?>>
          <label for="terms">
            Saya menyetujui <a href="#">Syarat &amp; Ketentuan</a>
            serta <a href="#">Kebijakan Privasi</a>.
          </label>
        </div>

        <button type="submit" class="btn-submit">
          <span class="btn-text">Daftar Sekarang</span>
          <span class="spinner"></span>
        </button>

      </form>

      <p class="auth-footer-link">
        Sudah punya akun? <a href="login.php">Masuk di sini</a>
      </p>

    </div>
  </div>

</div>

<script src="assets/script.js"></script>
</body>
</html> 