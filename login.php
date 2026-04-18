<?php
session_start();
require_once 'config/db.php';
if (isLoggedIn()) {
    isAdmin() ? redirect('admin_dashboard.php') : redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email dan kata sandi wajib diisi.';
    } else {
        $stmt = $conn->prepare("SELECT id, nama_depan, email, password, role FROM users WHERE LOWER(email) = ? LIMIT 1");

        if (!$stmt) {
            $error = 'Database error: ' . $conn->error;
        } else {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) {
                $error = 'Email tidak ditemukan. Silakan daftar terlebih dahulu.';
            } else {
                $pw_db  = $user['password'];
                $valid  = false;

                if (password_needs_rehash($pw_db, PASSWORD_BCRYPT) === false && password_verify($password, $pw_db)) {
                    $valid = true;
                }
                elseif (!str_starts_with($pw_db, '$2')) {
                    if ($pw_db === $password) {
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $upd  = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $upd->bind_param('si', $hash, $user['id']);
                        $upd->execute();
                        $upd->close();
                        $valid = true;
                    }
                }
                else {
                    $valid = password_verify($password, $pw_db);
                }

                if ($valid) {
                    session_regenerate_id(true);
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['nama_depan'] = $user['nama_depan'];
                    $_SESSION['email']      = $user['email'];
                    $_SESSION['role']       = $user['role'];

                    if ($user['role'] === 'admin') {
                        redirect('admin_dashboard.php', 'Selamat datang, Admin!', 'success');
                    } else {
                        redirect('belanja.php', 'Selamat datang kembali, ' . htmlspecialchars($user['nama_depan']) . '!', 'success');
                    }
                } else {
                    $error = 'Kata sandi salah. Silakan coba lagi.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Masuk — Yosep Fish</title>
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
      <button class="auth-tab active" data-href="login.php">Masuk</button>
      <button class="auth-tab"        data-href="register.php">Daftar</button>
    </div>

    <div class="auth-form-container">
      <h2>Selamat Datang Kembali!</h2>
      <p class="subtitle">Silakan masuk ke akun Anda</p>

      <?php if ($error): ?>
      <div class="auth-alert auth-alert-error show">
        <span>⚠️</span>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <form id="loginForm" method="POST" action="login.php" novalidate>

        <div class="form-group">
          <label for="email">Email</label>
          <div class="input-wrapper">
            <input type="email" id="email" name="email"
              placeholder="contoh@email.com"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              autocomplete="email" required>
          </div>
        </div>

        <div class="form-group">
          <label for="password" style="display:flex;justify-content:space-between;align-items:center">
            Kata Sandi
            <a href="#" class="forgot-link">Lupa Sandi?</a>
          </label>
          <div class="input-wrapper">
            <input type="password" id="password" name="password"
              placeholder="••••••••"
              autocomplete="current-password" required>
            <button type="button" class="toggle-pw" aria-label="Tampilkan kata sandi">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>

        <div class="form-meta">
          <label class="form-check">
            <input type="checkbox" name="remember" id="remember">
            Ingat saya
          </label>
        </div>

        <button type="submit" class="btn-submit">
          <span class="btn-text">Masuk Sekarang</span>
          <span class="spinner"></span>
        </button>

      </form>

      <p class="auth-footer-link">
        Belum punya akun? <a href="register.php">Daftar di sini</a>
      </p>

    </div>
  </div>

</div>

<script src="assets/script.js"></script>
</body>
</html> 