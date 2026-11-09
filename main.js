(() => {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const header = document.querySelector('[data-header]');

  /* ---------- Header: beim Scrollen weiß + Linie + leicht kleiner ---------- */
  const onScroll = () => header && header.classList.toggle('is-scrolled', window.scrollY > 40);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------- Mobiles Menü ---------- */
  const burger = document.querySelector('[data-burger]');
  const menu = document.querySelector('[data-mobile-menu]');
  const setMenu = (open) => {
    if (!burger || !menu) return;
    burger.setAttribute('aria-expanded', String(open));
    burger.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
    menu.classList.toggle('hidden', !open);
    burger.querySelector('[data-burger-open]').classList.toggle('hidden', open);
    burger.querySelector('[data-burger-close]').classList.toggle('hidden', !open);
  };
  burger && burger.addEventListener('click', () => setMenu(burger.getAttribute('aria-expanded') !== 'true'));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setMenu(false); });

  /* ---------- Smooth Scroll (Lenis) ---------- */
  let lenis = null;
  if (!reduceMotion && window.Lenis) {
    lenis = new window.Lenis({ duration: 1.1, smoothWheel: true });
    if (window.gsap && window.ScrollTrigger) {
      lenis.on('scroll', window.ScrollTrigger.update);
      window.gsap.ticker.add((t) => lenis.raf(t * 1000));
      window.gsap.ticker.lagSmoothing(0);
    } else {
      const raf = (t) => { lenis.raf(t); requestAnimationFrame(raf); };
      requestAnimationFrame(raf);
    }
  }

  /* Anker-Links (inkl. Header-Offset) */
  document.querySelectorAll('a[href^="#"]').forEach((a) => {
    a.addEventListener('click', (e) => {
      const id = a.getAttribute('href');
      if (id.length < 2) return;
      const target = document.querySelector(id);
      if (!target) return;
      e.preventDefault();
      setMenu(false);
      const offset = -(header ? header.offsetHeight : 0) + 1;
      if (lenis) lenis.scrollTo(target, { offset });
      else window.scrollTo({ top: target.getBoundingClientRect().top + window.scrollY + offset, behavior: reduceMotion ? 'auto' : 'smooth' });
      history.replaceState(null, '', id);
      target.setAttribute('tabindex', '-1');
      target.focus({ preventScroll: true });
    });
  });

  /* ---------- Google-Kalender: 2-Klick-Lösung ---------- */
  document.querySelectorAll('[data-booking]').forEach((booking) => {
    const loadBtn = booking.querySelector('[data-booking-load]');
    if (!loadBtn) return;
    loadBtn.addEventListener('click', () => {
      const iframe = document.createElement('iframe');
      iframe.src = booking.dataset.bookingUrl;
      iframe.title = booking.dataset.bookingTitle || 'Terminbuchung – Google Kalender';
      iframe.loading = 'lazy';
      iframe.className = 'block h-[650px] w-full rounded border-0 bg-white';
      booking.replaceWith(iframe);
      iframe.setAttribute('tabindex', '0');
      iframe.focus();
    });
  });

  /* ---------- Rückruf-Formular: öffnet eine vorausgefüllte E-Mail ---------- */
  const callback = document.querySelector('[data-callback]');
  if (callback) {
    const status = callback.querySelector('[data-callback-status]');
    callback.addEventListener('submit', (e) => {
      e.preventDefault();
      const lines = ['Name', 'Telefon', 'E-Mail', 'Erreichbarkeit', 'Anliegen']
        .map((name) => [name, callback.elements[name].value.trim()])
        .filter(([, value]) => value)
        .map(([name, value]) => `${name}: ${value}`);
      const link = document.createElement('a');
      link.href = `mailto:info@bergkapital.de?subject=${encodeURIComponent('Rückrufbitte über die Website')}&body=${encodeURIComponent(lines.join('\n'))}`;
      link.click();
      status.textContent = 'Ihr E-Mail-Programm wurde geöffnet. Bitte senden Sie die E-Mail dort ab.';
    });
  }

  /* ---------- Motion (GSAP + ScrollTrigger) ---------- */
  if (reduceMotion || !window.gsap || !window.ScrollTrigger) return;
  const { gsap, ScrollTrigger } = window;
  gsap.registerPlugin(ScrollTrigger);

  // Hero: Zoom-in beim Eintreten
  gsap.from('[data-hero-media]', { scale: 1.08, duration: 1.8, ease: 'power2.out' });
  gsap.from('[data-hero-content] > *', { y: 16, opacity: 0, duration: 0.7, ease: 'power2.out', stagger: 0.1, delay: 0.15 });

  // Hero: dezenter Parallax auf dem Monogramm
  gsap.to('[data-monogram]', {
    yPercent: -18, ease: 'none',
    scrollTrigger: { trigger: '[data-monogram]', start: 'top top', end: 'bottom top', scrub: true },
  });

  // Gestaffeltes Scroll-Reveal (Fade-up, 16px, 600ms, ease-out)
  gsap.set('[data-reveal]', { opacity: 0, y: 16 });
  ScrollTrigger.batch('[data-reveal]', {
    start: 'top 88%',
    once: true,
    onEnter: (els) => gsap.to(els, { opacity: 1, y: 0, duration: 0.6, ease: 'power2.out', stagger: 0.12, overwrite: true }),
  });

  // Kennzahlen (falls aktiviert): <span data-count="15">0</span>
  document.querySelectorAll('[data-count]').forEach((el) => {
    const end = parseFloat(el.dataset.count);
    const obj = { v: 0 };
    gsap.to(obj, {
      v: end, duration: 1.6, ease: 'power1.out',
      scrollTrigger: { trigger: el, start: 'top 90%', once: true },
      onUpdate: () => { el.textContent = Math.round(obj.v).toLocaleString('de-DE'); },
    });
  });
})();
