document.addEventListener("DOMContentLoaded", () => {
  const navbar = document.getElementById("navbar");
  if (navbar) {
    window.addEventListener("scroll", () => {
      navbar.classList.toggle("scrolled", window.scrollY > 30);
    });
  }

  const hamburger = document.getElementById("hamburger");
  const mobileNav = document.getElementById("mobileNav");

  if (hamburger && mobileNav) {
    hamburger.addEventListener("click", () => {
      hamburger.classList.toggle("active");
      mobileNav.classList.toggle("open");
      document.body.style.overflow = mobileNav.classList.contains("open")
        ? "hidden"
        : "";
    });

    mobileNav.querySelectorAll("a").forEach((link) => {
      link.addEventListener("click", () => {
        hamburger.classList.remove("active");
        mobileNav.classList.remove("open");
        document.body.style.overflow = "";
      });
    });

    document.addEventListener("click", (e) => {
      if (!navbar.contains(e.target) && !mobileNav.contains(e.target)) {
        hamburger.classList.remove("active");
        mobileNav.classList.remove("open");
        document.body.style.overflow = "";
      }
    });
  }

  const fadeEls = document.querySelectorAll(".fade-up");
  if (fadeEls.length) {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry, i) => {
          if (entry.isIntersecting) {
            setTimeout(() => {
              entry.target.classList.add("visible");
            }, i * 100);
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.15 },
    );

    fadeEls.forEach((el) => observer.observe(el));
  }

  document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener("click", (e) => {
      const target = document.querySelector(anchor.getAttribute("href"));
      if (target) {
        e.preventDefault();
        const top = target.getBoundingClientRect().top + window.scrollY - 80;
        window.scrollTo({ top, behavior: "smooth" });
      }
    });
  });

  const flash = document.querySelector(".flash");
  if (flash) {
    setTimeout(() => {
      flash.style.transition = "opacity 0.5s ease, transform 0.5s ease";
      flash.style.opacity = "0";
      flash.style.transform = "translateX(120%)";
      setTimeout(() => flash.remove(), 500);
    }, 4000);
  }

  const tabs = document.querySelectorAll(".auth-tab");
  tabs.forEach((tab) => {
    tab.addEventListener("click", () => {
      const target = tab.getAttribute("data-href");
      if (target) window.location.href = target;
    });
  });

  document.querySelectorAll(".toggle-pw").forEach((btn) => {
    btn.addEventListener("click", () => {
      const input = btn.closest(".input-wrapper").querySelector("input");
      if (!input) return;
      const isHidden = input.type === "password";
      input.type = isHidden ? "text" : "password";
      btn.innerHTML = isHidden ? eyeOpenSVG() : eyeClosedSVG();
    });
  });

  const loginForm = document.getElementById("loginForm");
  if (loginForm) {
    loginForm.addEventListener("submit", (e) => {
      e.preventDefault();
      let valid = true;

      const email = loginForm.querySelector("#email");
      const password = loginForm.querySelector("#password");

      clearErrors(loginForm);

      if (!email.value.trim() || !isValidEmail(email.value)) {
        showError(email, "Masukkan email yang valid.");
        valid = false;
      }

      if (!password.value || password.value.length < 6) {
        showError(password, "Kata sandi minimal 6 karakter.");
        valid = false;
      }

      if (valid) {
        setLoading(loginForm.querySelector(".btn-submit"), true);
        loginForm.submit();
      }
    });
  }

  const registerForm = document.getElementById("registerForm");
  if (registerForm) {
    const pw = registerForm.querySelector("#password");
    const pw2 = registerForm.querySelector("#password_confirm");

    if (pw && pw2) {
      [pw, pw2].forEach((input) => {
        input.addEventListener("input", () => {
          if (pw2.value) {
            if (pw.value !== pw2.value) {
              showError(pw2, "Kata sandi tidak cocok.");
            } else {
              clearError(pw2);
              pw2.classList.add("success");
            }
          }
        });
      });
    }

    registerForm.addEventListener("submit", (e) => {
      e.preventDefault();
      let valid = true;

      clearErrors(registerForm);

      const namaDepan = registerForm.querySelector("#nama_depan");
      const namaBelakang = registerForm.querySelector("#nama_belakang");
      const noHp = registerForm.querySelector("#no_hp");
      const email = registerForm.querySelector("#email");
      const password = registerForm.querySelector("#password");
      const passConfirm = registerForm.querySelector("#password_confirm");
      const terms = registerForm.querySelector("#terms");

      if (!namaDepan?.value.trim()) {
        showError(namaDepan, "Nama depan wajib diisi.");
        valid = false;
      }

      if (!namaBelakang?.value.trim()) {
        showError(namaBelakang, "Nama belakang wajib diisi.");
        valid = false;
      }

      if (
        !noHp?.value.trim() ||
        !/^\d{8,13}$/.test(noHp.value.replace(/\s/g, ""))
      ) {
        showError(noHp, "Nomor HP tidak valid (8-13 digit).");
        valid = false;
      }

      if (!email?.value.trim() || !isValidEmail(email.value)) {
        showError(email, "Masukkan email yang valid.");
        valid = false;
      }

      if (!password?.value || password.value.length < 8) {
        showError(password, "Kata sandi minimal 8 karakter.");
        valid = false;
      }

      if (password?.value !== passConfirm?.value) {
        showError(passConfirm, "Konfirmasi sandi tidak cocok.");
        valid = false;
      }

      if (terms && !terms.checked) {
        showError(terms, "Anda harus menyetujui syarat & ketentuan.");
        valid = false;
      }

      if (valid) {
        setLoading(registerForm.querySelector(".btn-submit"), true);
        registerForm.submit();
      }
    });
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  function showError(input, message) {
    if (!input) return;
    input.classList.add("error");
    input.classList.remove("success");
    const group =
      input.closest(".form-group") || input.closest(".input-wrapper");
    let err = group?.querySelector(".field-error");
    if (!err) {
      err = document.createElement("span");
      err.className = "field-error";
      group?.appendChild(err);
    }
    err.textContent = message;
    err.classList.add("show");
  }

  function clearError(input) {
    if (!input) return;
    input.classList.remove("error", "success");
    const group =
      input.closest(".form-group") || input.closest(".input-wrapper");
    const err = group?.querySelector(".field-error");
    if (err) {
      err.classList.remove("show");
      err.textContent = "";
    }
  }

  function clearErrors(form) {
    form.querySelectorAll("input").forEach((inp) => clearError(inp));
    form.querySelectorAll(".field-error").forEach((el) => {
      el.classList.remove("show");
      el.textContent = "";
    });
  }

  function setLoading(btn, loading) {
    if (!btn) return;
    btn.classList.toggle("loading", loading);
  }

  function eyeOpenSVG() {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
  }

  function eyeClosedSVG() {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
  }
});
