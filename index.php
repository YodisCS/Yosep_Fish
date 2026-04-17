<?php
session_start();

$flash_message = '';
$flash_type    = '';
if (isset($_SESSION['flash_message'])) {
  $flash_message = $_SESSION['flash_message'];
  $flash_type    = $_SESSION['flash_type'] ?? 'info';
  unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$is_logged_in  = isset($_SESSION['user_id']);
$user_name     = isset($_SESSION['nama_depan']) ? $_SESSION['nama_depan'] : '';
$is_admin      = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Yosep Fish — Toko Ikan Hias Terpercaya</title>
  <meta name="description" content="Temukan ikan hias impianmu di Yosep Fish. Koleksi terlengkap, packing aman, pengiriman ke seluruh Indonesia.">
  <link rel="stylesheet" href="assets/style.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐠</text></svg>">
</head>

<body>

  <?php if ($flash_message): ?>
    <div class="flash flash-<?= htmlspecialchars($flash_type) ?>">
      <?= htmlspecialchars($flash_message) ?>
    </div>
  <?php endif; ?>

  <nav class="navbar" id="navbar">
    <a href="index.php" class="nav-logo">YOSEP <span>FISH</span></a>

    <ul class="nav-links">
      <li><a href="#beranda">Beranda</a></li>
      <li><?php if ($is_logged_in): ?><a href="belanja.php">Produk</a><?php else: ?><a href="login.php">Produk</a><?php endif; ?></li>
      <li><a href="#tentang">Tentang Kami</a></li>
      <li><a href="#kontak">Kontak</a></li>
    </ul>

    <div class="nav-actions">
      <?php if ($is_logged_in): ?>
        <span style="font-size:0.875rem;font-weight:600;color:var(--gray-700)">
          Halo, <?= htmlspecialchars($user_name) ?>
        </span>
        <?php if ($is_admin): ?>
          <a href="admin_dashboard.php" class="btn-solid">Dashboard</a>
        <?php endif; ?>
        <a href="logout.php" class="btn-outline">Keluar</a>
      <?php else: ?>
        <a href="login.php" class="btn-outline">Masuk</a>
        <a href="register.php" class="btn-solid">Daftar</a>
      <?php endif; ?>
    </div>

    <button class="hamburger" id="hamburger" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
  </nav>

  <!-- Mobile Nav -->
  <div class="mobile-nav" id="mobileNav">
    <a href="#beranda">Beranda</a>
    <?php if ($is_logged_in): ?><a href="belanja.php">Produk</a><?php else: ?><a href="login.php">Produk</a><?php endif; ?>
    <a href="#tentang">Tentang Kami</a>
    <a href="#kontak">Kontak</a>
    <div class="mobile-nav-actions">
      <?php if ($is_logged_in): ?>
        <a href="logout.php" class="btn-outline">Keluar</a>
        <?php if ($is_admin): ?>
          <a href="admin_dashboard.php" class="btn-solid">Dashboard</a>
        <?php endif; ?>
      <?php else: ?>
        <a href="login.php" class="btn-outline">Masuk</a>
        <a href="register.php" class="btn-solid">Daftar</a>
      <?php endif; ?>
    </div>
  </div>

  <section class="hero" id="beranda">
    <div class="hero-bg"></div>
    <div class="hero-overlay"></div>

    <div class="hero-content">
      <div class="hero-badge">🐠 #1 Toko Ikan Hias Indonesia</div>

      <h1>
        TEMUKAN IKAN HIAS
        <span class="highlight">IMPIANMU DISINI</span>
      </h1>

      <p>
        Menyediakan perlengkapan aquarium berkualitas dengan harga terjangkau
        dan koleksi ikan hias terlengkap untuk mempercantik hunian Anda.
      </p>

      <div class="hero-cta">
        <?php if ($is_logged_in): ?>
          <a href="belanja.php" class="btn-hero-primary">Lihat Produk</a>
        <?php else: ?>
          <a href="login.php" class="btn-hero-primary">Lihat Produk</a>
        <?php endif; ?>
        <a href="#koleksi" class="btn-hero-secondary">Galeri Koleksi</a>
      </div>
    </div>

    <div class="hero-scroll">
      <span>Scroll</span>
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 5v14M5 12l7 7 7-7" />
      </svg>
    </div>
  </section>
  <section class="why-section" id="tentang">
    <div class="section-header fade-up">
      <span class="section-tag">Keunggulan Kami</span>
      <h2>Mengapa Memilih Yosep Fish?</h2>
      <p>Visi kami adalah menjadi pusat hobi ikan hias terpercaya yang mengedepankan kualitas kesehatan ikan dan kepuasan pelanggan di seluruh Indonesia.</p>
    </div>

    <div class="features-grid">
      <div class="feature-card fade-up">
        <div class="feature-icon icon-blue">📦</div>
        <h3>Packing Aman</h3>
        <p>Kemasan khusus beroksigen tinggi dengan double plastic untuk menjamin ikan tiba dalam kondisi prima.</p>
      </div>

      <div class="feature-card fade-up">
        <div class="feature-icon icon-green">🚚</div>
        <h3>Pengiriman Aman</h3>
        <p>Bekerja sama dengan ekspedisi spesialis hewan hidup untuk pengiriman cepat ke seluruh wilayah.</p>
      </div>

      <div class="feature-card fade-up">
        <div class="feature-icon icon-amber">🏷️</div>
        <h3>Harga Terjangkau</h3>
        <p>Kami menyediakan produk berkualitas premium dengan harga kompetitif bagi para penghobi.</p>
      </div>
    </div>
  </section>

  <section class="collection-section" id="koleksi">
    <div class="section-header fade-up">
      <span class="section-tag">Galeri</span>
      <h2>Koleksi Unggulan</h2>
    </div>

    <div class="collection-scroll fade-up">

      <div class="collection-card">
        <img
          src="assets/img/ikan4.jpeg"
          alt="Betta Halfmoon"
          loading="lazy">
        <div class="collection-card-overlay"></div>
        <div class="collection-card-info">
          <h4>Betta Halfmoon</h4>
          <span>Koleksi Eksklusif</span>
        </div>
      </div>

      <div class="collection-card">
        <img
          src="assets/img/ikan6.jpeg"
          alt="Betta Plakat"
          loading="lazy">
        <div class="collection-card-overlay"></div>
        <div class="collection-card-info">
          <h4>Betta Plakat</h4>
          <span>Koleksi Eksklusif</span>
        </div>
      </div>

      <div class="collection-card">
        <img
          src="assets/img/ikan5.jpeg"
          alt="Betta Halfmoon Fancy"
          loading="lazy">
        <div class="collection-card-overlay"></div>
        <div class="collection-card-info">
          <h4>Betta Halfmoon Fancy</h4>
          <span>Koleksi Premium</span>
        </div>
      </div>

      <div class="collection-card">
        <img
          src="assets/img/ikan3.jpeg"
          alt="Betta DTPK"
          loading="lazy">
        <div class="collection-card-overlay"></div>
        <div class="collection-card-info">
          <h4>Betta DTPK</h4>
          <span>Koleksi Eksklusif</span>
        </div>
      </div>

      <div class="collection-card">
        <img
          src="assets/img/ikan12.jpeg"
          alt="Betta CTPK"
          loading="lazy">
        <div class="collection-card-overlay"></div>
        <div class="collection-card-info">
          <h4>Betta CTPK</h4>
          <span>Koleksi Populer</span>
        </div>
      </div>

    </div>
  </section>

  <section class="contact-section" id="kontak">
    <div class="contact-grid">
      <div class="contact-info fade-up">
        <h2>Hubungi Kami</h2>

        <div class="contact-item">
          <div class="contact-icon">✉️</div>
          <div class="contact-item-text">
            <strong>Email</strong>
            <a href="mailto:halo@yosepfish.com">halo@yosepfish.com</a>
          </div>
        </div>

        <div class="contact-item">
          <div class="contact-icon">💬</div>
          <div class="contact-item-text">
            <strong>WhatsApp</strong>
            <a href="https://wa.me/6287750135143" target="_blank" rel="noopener">+62 877 5013 5143</a>
          </div>
        </div>

        <div class="contact-item">
          <div class="contact-icon">📍</div>
          <div class="contact-item-text">
            <strong>Alamat Toko</strong>
            <span>Jl. Puspitek, Buaran, Kec. Pamulang, Kota Tangerang Selatan, Banten 15310</span>
          </div>
        </div>
      </div>

      <div class="map-wrapper fade-up">
        <iframe
          src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3965.3701518094904!2d106.68893947418431!3d-6.346090362086225!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2e69e5a6e26dc3cd%3A0xccd6344b8021119d!2sPamulang%20University%20Campus%202%20(UNPAM%20Viktor)!5e0!3m2!1sen!2sid!4v1775467432596!5m2!1sen!2sid"
          allowfullscreen=""
          loading="lazy"
          referrerpolicy="no-referrer-when-downgrade"
          title="Lokasi Yosep Fish"></iframe>
      </div>
    </div>
  </section>

  <footer class="footer">
    <p>© 2026 <strong>Yosep Fish</strong>. Quality First.</p>
  </footer>

  <script src="assets/script.js"></script>
</body>

</html>