/* ==========================================================================
   Quantuk Perú — main.js
   Header behaviour, mobile drawer, scroll reveal, counters, parallax,
   tabs, service master-detail and the quote modal.
   Runs on every page. Each module bails out silently if its markup
   is not present, so the same file serves all four pages.
   ========================================================================== */

(function () {
  "use strict";

  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ------------------------------------------------------------------------
     Bloqueo del scroll de fondo

     El drawer y el modal pueden estar abiertos a la vez (se abre el modal
     desde el menú móvil). Si cada uno pusiera y quitara la clase por su
     cuenta, cerrar el modal desbloquearía el body con el drawer todavía
     abierto. El contador garantiza que solo se desbloquee cuando ya no
     queda nada abierto encima.
     ------------------------------------------------------------------------ */
  var lockCount = 0;

  function lockScroll() {
    lockCount += 1;
    document.body.classList.add("is-locked");
  }

  function unlockScroll() {
    lockCount = Math.max(0, lockCount - 1);
    if (lockCount === 0) document.body.classList.remove("is-locked");
  }

  /* Selector de elementos enfocables, compartido por drawer y modal */
  var FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  /* Atrapa el foco dentro de un contenedor mientras está abierto */
  function trapFocus(container, event) {
    var focusables = container.querySelectorAll(FOCUSABLE);
    if (!focusables.length) return;

    var first = focusables[0];
    var last = focusables[focusables.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  /* El modal necesita poder cerrar el drawer al abrirse desde el menú móvil */
  var closeDrawerFn = null;

  /* ------------------------------------------------------------------------
     Sticky header
     Switches to the compact fixed bar once the hero top is out of view.
     A small hysteresis gap avoids flicker when scrolling right on the edge.
     ------------------------------------------------------------------------ */
  function initHeader() {
    var header = document.getElementById("siteHeader");
    if (!header) return;

    var ON = 220;
    var OFF = 160;
    var stuck = false;
    var ticking = false;

    function update() {
      var y = window.scrollY;
      if (!stuck && y > ON) {
        header.classList.add("is-stuck");
        stuck = true;
      } else if (stuck && y < OFF) {
        header.classList.remove("is-stuck");
        stuck = false;
      }
      ticking = false;
    }

    window.addEventListener(
      "scroll",
      function () {
        if (!ticking) {
          window.requestAnimationFrame(update);
          ticking = true;
        }
      },
      { passive: true }
    );

    update();
  }

  /* ------------------------------------------------------------------------
     Scroll progress bar
     ------------------------------------------------------------------------ */
  function initScrollProgress() {
    var bar = document.querySelector(".scroll-progress");
    if (!bar) return;

    var ticking = false;

    function update() {
      var max = document.documentElement.scrollHeight - window.innerHeight;
      var ratio = max > 0 ? window.scrollY / max : 0;
      bar.style.transform = "scaleX(" + Math.min(ratio, 1) + ")";
      ticking = false;
    }

    window.addEventListener(
      "scroll",
      function () {
        if (!ticking) {
          window.requestAnimationFrame(update);
          ticking = true;
        }
      },
      { passive: true }
    );

    window.addEventListener("resize", update, { passive: true });
    update();
  }

  /* ------------------------------------------------------------------------
     Mobile drawer
     Traps focus while open and restores it to the trigger on close.
     ------------------------------------------------------------------------ */
  function initDrawer() {
    var toggle = document.querySelector("[data-drawer-open]");
    var drawer = document.getElementById("mobileDrawer");
    var scrim = document.querySelector(".drawer-scrim");
    if (!toggle || !drawer) return;

    var closers = drawer.querySelectorAll("[data-drawer-close]");

    var isOpen = false;

    function open() {
      if (isOpen) return;
      isOpen = true;
      drawer.classList.add("is-open");
      if (scrim) scrim.classList.add("is-open");
      drawer.removeAttribute("aria-hidden");
      toggle.setAttribute("aria-expanded", "true");
      lockScroll();

      var first = drawer.querySelector(FOCUSABLE);
      if (first) first.focus();
    }

    function close(restoreFocus) {
      if (!isOpen) return;
      isOpen = false;
      drawer.classList.remove("is-open");
      if (scrim) scrim.classList.remove("is-open");
      drawer.setAttribute("aria-hidden", "true");
      toggle.setAttribute("aria-expanded", "false");
      unlockScroll();
      // Al abrir el modal desde el drawer no devolvemos el foco al botón:
      // se lo queda el modal, que es lo que el usuario está mirando.
      if (restoreFocus !== false) toggle.focus();
    }

    // El modal la usa para cerrar el drawer al abrirse desde el menú móvil
    closeDrawerFn = close;

    toggle.addEventListener("click", function () {
      if (drawer.classList.contains("is-open")) close();
      else open();
    });

    Array.prototype.forEach.call(closers, function (el) {
      el.addEventListener("click", close);
    });

    if (scrim) scrim.addEventListener("click", close);

    document.addEventListener("keydown", function (e) {
      if (!isOpen) return;

      // Si el modal está encima, Escape es suyo: lo maneja initQuoteModal
      var modal = document.getElementById("quoteModal");
      if (modal && modal.classList.contains("is-open")) return;

      if (e.key === "Escape") {
        close();
        return;
      }

      if (e.key === "Tab") trapFocus(drawer, e);
    });

    // Se cierra al seguir cualquier enlace del menú
    drawer.addEventListener("click", function (e) {
      var link = e.target.closest("a[href]");
      if (link) close();
    });
  }

  /* ------------------------------------------------------------------------
     Scroll reveal
     Elements marked with [data-reveal] fade up as they enter the viewport.
     The .reveal class is added by JS so content never stays hidden when
     JavaScript is unavailable.
     ------------------------------------------------------------------------ */
  function initReveal() {
    var targets = document.querySelectorAll("[data-reveal]");
    if (!targets.length) return;

    if (reduceMotion || !("IntersectionObserver" in window)) {
      Array.prototype.forEach.call(targets, function (el) {
        el.classList.add("is-visible");
      });
      return;
    }

    Array.prototype.forEach.call(targets, function (el) {
      el.classList.add("reveal");
      var variant = el.getAttribute("data-reveal");
      if (variant && variant !== "up") el.classList.add("reveal--" + variant);

      var delay = el.getAttribute("data-reveal-delay");
      if (delay) el.style.setProperty("--reveal-delay", delay + "ms");
    });

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -8% 0px" }
    );

    Array.prototype.forEach.call(targets, function (el) {
      observer.observe(el);
    });
  }

  /* ------------------------------------------------------------------------
     Animated counters
     Counts up once, when the stat scrolls into view.
     ------------------------------------------------------------------------ */
  function initCounters() {
    var counters = document.querySelectorAll("[data-count-to]");
    if (!counters.length) return;

    function run(el) {
      var target = parseFloat(el.getAttribute("data-count-to"));
      if (isNaN(target)) return;

      var prefix = el.getAttribute("data-count-prefix") || "";
      var suffix = el.getAttribute("data-count-suffix") || "";

      if (reduceMotion) {
        el.textContent = prefix + target + suffix;
        return;
      }

      var duration = 1600;
      var start = null;

      function frame(now) {
        if (start === null) start = now;
        var progress = Math.min((now - start) / duration, 1);
        // ease-out cubic
        var eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = prefix + Math.round(target * eased) + suffix;
        if (progress < 1) window.requestAnimationFrame(frame);
      }

      window.requestAnimationFrame(frame);
    }

    if (!("IntersectionObserver" in window)) {
      Array.prototype.forEach.call(counters, run);
      return;
    }

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          run(entry.target);
          observer.unobserve(entry.target);
        });
      },
      { threshold: 0.5 }
    );

    Array.prototype.forEach.call(counters, function (el) {
      observer.observe(el);
    });
  }

  /* ------------------------------------------------------------------------
     Timeline step highlight
     ------------------------------------------------------------------------ */
  function initTimeline() {
    var steps = document.querySelectorAll(".timeline__step");
    if (!steps.length || !("IntersectionObserver" in window)) return;

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) entry.target.classList.add("is-visible");
        });
      },
      { threshold: 0.5 }
    );

    Array.prototype.forEach.call(steps, function (el) {
      observer.observe(el);
    });
  }

  /* ------------------------------------------------------------------------
     Hero parallax
     Single layer only — beyond 3 layers the cost outweighs the effect.
     ------------------------------------------------------------------------ */
  function initParallax() {
    if (reduceMotion) return;

    var layers = document.querySelectorAll("[data-parallax]");
    if (!layers.length) return;

    var ticking = false;

    function update() {
      var y = window.scrollY;
      Array.prototype.forEach.call(layers, function (layer) {
        var speed = parseFloat(layer.getAttribute("data-parallax")) || 0.12;
        // Only worth computing while the layer is anywhere near the viewport
        if (y < window.innerHeight * 1.5) {
          layer.style.transform = "translate3d(0," + y * speed + "px,0)";
        }
      });
      ticking = false;
    }

    window.addEventListener(
      "scroll",
      function () {
        if (!ticking) {
          window.requestAnimationFrame(update);
          ticking = true;
        }
      },
      { passive: true }
    );
  }

  /* ------------------------------------------------------------------------
     Tabs (Misión / Visión / Valores)
     Implements the WAI-ARIA tabs pattern including arrow-key navigation.
     ------------------------------------------------------------------------ */
  function initTabs() {
    var groups = document.querySelectorAll("[data-tabs]");
    if (!groups.length) return;

    Array.prototype.forEach.call(groups, function (group) {
      var tabs = group.querySelectorAll('[role="tab"]');
      var panels = group.querySelectorAll('[role="tabpanel"]');
      if (!tabs.length) return;

      function select(index) {
        Array.prototype.forEach.call(tabs, function (tab, i) {
          var active = i === index;
          tab.setAttribute("aria-selected", active ? "true" : "false");
          tab.setAttribute("tabindex", active ? "0" : "-1");
          if (panels[i]) panels[i].hidden = !active;
        });
      }

      Array.prototype.forEach.call(tabs, function (tab, i) {
        tab.addEventListener("click", function () {
          select(i);
        });

        tab.addEventListener("keydown", function (e) {
          var next = null;
          if (e.key === "ArrowRight") next = (i + 1) % tabs.length;
          else if (e.key === "ArrowLeft") next = (i - 1 + tabs.length) % tabs.length;
          else if (e.key === "Home") next = 0;
          else if (e.key === "End") next = tabs.length - 1;

          if (next !== null) {
            e.preventDefault();
            select(next);
            tabs[next].focus();
          }
        });
      });

      select(0);
    });
  }

  /* ------------------------------------------------------------------------
     Panel de servicios: pestañas en escritorio, acordeón en móvil

     En escritorio la lista va a la izquierda y el detalle a la derecha, que
     es el patrón maestro-detalle clásico. En pantalla angosta esas dos
     mitades se apilan y obligan a subir y bajar para cada servicio, así que
     ahí el detalle se mueve en el DOM justo debajo de su botón.

     El cambio no es sólo visual: en escritorio son pestañas (role="tab" +
     aria-selected) y en móvil son botones de expandir (aria-expanded +
     role="region"). Un acordeón anunciado como pestañas confunde a quien usa
     lector de pantalla, porque promete una navegación que no existe.
     ------------------------------------------------------------------------ */
  function initServicePanel() {
    var panel = document.querySelector("[data-service-panel]");
    if (!panel) return;

    var nav = panel.querySelector(".service-nav");
    var detailsWrap = panel.querySelector(".service-details");
    var items = panel.querySelectorAll(".service-nav__item");
    var details = panel.querySelectorAll(".service-detail");
    if (!items.length || !nav || !detailsWrap) return;

    var mq = window.matchMedia("(max-width: 860px)");
    var isMobile = null;
    var current = 0;

    function applyMode() {
      var mobile = mq.matches;
      if (mobile === isMobile) return;
      isMobile = mobile;

      if (mobile) {
        // El detalle pasa a vivir inmediatamente después de su botón
        Array.prototype.forEach.call(items, function (item, i) {
          if (details[i]) item.insertAdjacentElement("afterend", details[i]);
        });

        nav.removeAttribute("role");
        nav.removeAttribute("aria-orientation");

        Array.prototype.forEach.call(items, function (item) {
          item.removeAttribute("role");
          item.removeAttribute("aria-selected");
          item.removeAttribute("tabindex");
        });

        Array.prototype.forEach.call(details, function (d) {
          d.setAttribute("role", "region");
        });
      } else {
        // Vuelven a la columna derecha, en su orden original
        Array.prototype.forEach.call(details, function (d) {
          detailsWrap.appendChild(d);
        });

        nav.setAttribute("role", "tablist");
        nav.setAttribute("aria-orientation", "vertical");

        Array.prototype.forEach.call(items, function (item) {
          item.setAttribute("role", "tab");
          item.removeAttribute("aria-expanded");
        });

        Array.prototype.forEach.call(details, function (d) {
          d.setAttribute("role", "tabpanel");
        });
      }

      select(current, true);
    }

    function select(index, keepOpen) {
      // En acordeón, volver a tocar el abierto lo cierra. En pestañas
      // siempre hay una activa: cerrar dejaría la columna derecha vacía.
      var collapse = isMobile && !keepOpen && index === current && !details[index].hidden;

      current = index;

      Array.prototype.forEach.call(items, function (item, i) {
        var active = i === index && !collapse;

        if (isMobile) {
          item.setAttribute("aria-expanded", active ? "true" : "false");
        } else {
          item.setAttribute("aria-selected", active ? "true" : "false");
          item.setAttribute("tabindex", i === index ? "0" : "-1");
        }

        if (details[i]) details[i].hidden = !active;
      });
    }

    Array.prototype.forEach.call(items, function (item, i) {
      item.addEventListener("click", function () {
        select(i);
      });

      item.addEventListener("keydown", function (e) {
        // Las flechas mueven el foco sólo en modo pestañas; en acordeón
        // cada botón es una parada normal del tabulador.
        if (isMobile) return;

        var next = null;
        if (e.key === "ArrowDown") next = (i + 1) % items.length;
        else if (e.key === "ArrowUp") next = (i - 1 + items.length) % items.length;
        else if (e.key === "Home") next = 0;
        else if (e.key === "End") next = items.length - 1;

        if (next !== null) {
          e.preventDefault();
          select(next, true);
          items[next].focus();
        }
      });
    });

    applyMode();

    if (mq.addEventListener) mq.addEventListener("change", applyMode);
    else if (mq.addListener) mq.addListener(applyMode);

    /* Deep link: servicios.html#compliance abre ese servicio directamente.
       El id del hash no existe como elemento, así que el navegador no
       desplaza nada por su cuenta: hay que llevar el panel a la vista o el
       usuario aterriza arriba de todo creyendo que el enlace no funcionó. */
    function applyHash(scroll) {
      var hash = window.location.hash.replace("#", "");

      var found = -1;
      if (hash) {
        Array.prototype.forEach.call(items, function (item, i) {
          if (item.getAttribute("data-service") === hash) found = i;
        });
      }

      // El segundo argumento es obligatorio: sin él, en modo acordeón un
      // enlace al servicio que ya está abierto lo cerraría en vez de
      // mostrarlo, que es lo contrario de lo que el visitante espera.
      select(found === -1 ? 0 : found, true);

      if (found === -1 || !scroll) return;

      // En acordeón el destino es el botón, porque el detalle se despliega
      // debajo de él; en escritorio, el panel completo.
      var target = isMobile ? items[found] : panel;
      target.scrollIntoView({
        behavior: reduceMotion ? "auto" : "smooth",
        block: "start"
      });
    }

    applyHash(true);

    // Estando ya en la página, cambiar el hash no recarga: hay que reaccionar
    window.addEventListener("hashchange", function () {
      applyHash(true);
    });
  }

  /* ------------------------------------------------------------------------
     Quote modal
     Opened from the navbar CTA on every page. Focus is trapped while open
     and returned to the trigger on close.
     ------------------------------------------------------------------------ */
  function initQuoteModal() {
    var modal = document.getElementById("quoteModal");
    if (!modal) return;

    var dialog = modal.querySelector(".quote-modal__dialog");
    var lastFocused = null;
    var isOpen = false;

    function open(trigger) {
      if (isOpen) return;
      isOpen = true;
      lastFocused = trigger || document.activeElement;

      // Si se abrió desde el menú móvil, cerramos el drawer: dos capas
      // superpuestas confunden y el foco termina donde no se ve.
      if (closeDrawerFn) closeDrawerFn(false);

      modal.classList.add("is-open");
      modal.removeAttribute("aria-hidden");
      lockScroll();

      var first = dialog.querySelector(FOCUSABLE);
      if (first) first.focus();
    }

    function close() {
      if (!isOpen) return;
      isOpen = false;
      modal.classList.remove("is-open");
      modal.setAttribute("aria-hidden", "true");
      unlockScroll();

      // El disparador pudo haber quedado oculto: si venía del drawer, ese
      // botón ya no se ve, y enfocar un elemento invisible falla en silencio
      // dejando el foco perdido en el body. offsetParent null = no visible.
      if (lastFocused && lastFocused.isConnected && lastFocused.offsetParent !== null) {
        lastFocused.focus();
      } else {
        var fallback = document.querySelector(".navbar [data-quote-open], [data-drawer-open]");
        if (fallback && fallback.offsetParent !== null) fallback.focus();
      }
    }

    document.addEventListener("click", function (e) {
      var opener = e.target.closest("[data-quote-open]");
      if (opener) {
        e.preventDefault();
        open(opener);
        // Pre-select a service when the trigger names one
        var service = opener.getAttribute("data-quote-service");
        var select = modal.querySelector("#quote-service");
        if (service && select) select.value = service;
        return;
      }

      if (e.target.closest("[data-quote-close]")) {
        e.preventDefault();
        close();
      }
    });

    document.addEventListener("keydown", function (e) {
      if (!isOpen) return;

      if (e.key === "Escape") {
        close();
        return;
      }

      if (e.key === "Tab") trapFocus(dialog, e);
    });

    // Expose close so forms.js can dismiss the modal after a successful send
    modal.closeModal = close;
  }

  /* ------------------------------------------------------------------------
     Footer year
     ------------------------------------------------------------------------ */
  function initYear() {
    var nodes = document.querySelectorAll("[data-year]");
    var year = String(new Date().getFullYear());
    Array.prototype.forEach.call(nodes, function (el) {
      el.textContent = year;
    });
  }

  /* ------------------------------------------------------------------------
     Boot
     ------------------------------------------------------------------------ */
  function init() {
    initHeader();
    initScrollProgress();
    initDrawer();
    initReveal();
    initCounters();
    initTimeline();
    initParallax();
    initTabs();
    initServicePanel();
    initQuoteModal();
    initYear();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
