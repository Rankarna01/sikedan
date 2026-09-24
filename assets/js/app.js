// SiKedan - base JS
document.addEventListener('DOMContentLoaded', function () {
  // Auto-dismiss alerts after 5s
  document.querySelectorAll('.alert').forEach(function (alert) {
    setTimeout(function () {
      var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
      if (bsAlert) bsAlert.close();
    }, 5000);
  });

  // Global submit-button loading state for better UX feedback on forms
  // (skips forms that already manage their own submit state, e.g. quiz timer form)
  document.querySelectorAll('form').forEach(function (form) {
    if (form.id === 'quizForm') return; // has custom handling
    form.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return; // cancelled (e.g. via confirm() dialog returning false)
      if (form.checkValidity && !form.checkValidity()) return; // let browser show native validation
      var btn = form.querySelector('button[type="submit"]');
      if (btn && !btn.disabled) {
        btn.dataset.originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Memproses...';
      }
    });
  });

  // Animated number counters (e.g. landing page stats)
  document.querySelectorAll('.counter').forEach(function (el) {
    var target = parseInt(el.getAttribute('data-target'), 10) || 0;
    var duration = 1200;
    var startTime = null;
    function step(ts) {
      if (!startTime) startTime = ts;
      var progress = Math.min((ts - startTime) / duration, 1);
      el.textContent = Math.floor(progress * target);
      if (progress < 1) requestAnimationFrame(step);
      else el.textContent = target;
    }
    requestAnimationFrame(step);
  });

  // Scroll-reveal animation for elements with .reveal-on-scroll
  if ('IntersectionObserver' in window) {
    var revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          revealObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.15 });
    document.querySelectorAll('.reveal-on-scroll').forEach(function (el) {
      revealObserver.observe(el);
    });
  } else {
    document.querySelectorAll('.reveal-on-scroll').forEach(function (el) {
      el.classList.add('is-visible');
    });
  }
});
