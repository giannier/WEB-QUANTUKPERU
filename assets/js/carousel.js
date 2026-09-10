/* ==========================================================================
   Quantuk Perú — carousel.js
   Hero slider: 4 slides, each carrying its own background image and copy.
   Autoplay, dots, arrows, keyboard, touch swipe.

   HOW TO ADD YOUR IMAGES
   ----------------------
   Drop four files into assets/imagen/hero/ named hero-1.jpg … hero-4.jpg
   (1920x1080 recommended). Nothing else to change — the markup already
   points at those paths. Until then the dark gradient stands in.
   ========================================================================== */

(function () {
  "use strict";

  var AUTOPLAY_MS = 6500;
  var SWIPE_THRESHOLD = 55;

  function initHeroCarousel() {
    var root = document.querySelector("[data-carousel]");
    if (!root) return;

    var slides = root.querySelectorAll(".hero__slide");
    var dots = root.querySelectorAll(".hero__dot");
    var prevBtn = root.querySelector("[data-carousel-prev]");
    var nextBtn = root.querySelector("[data-carousel-next]");
    var progress = root.querySelector(".hero__progress span");
    var live = root.querySelector("[data-carousel-live]");

    if (slides.length < 2) return;

    var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var index = 0;
    var timer = null;

    root.style.setProperty("--hero-delay", AUTOPLAY_MS + "ms");

    function restartProgress() {
      if (!progress || reduceMotion) return;
      progress.classList.remove("is-running");
      // Force reflow so the animation restarts from zero
      void progress.offsetWidth;
      progress.classList.add("is-running");
    }

    function goTo(next) {
      index = (next + slides.length) % slides.length;

      Array.prototype.forEach.call(slides, function (slide, i) {
        var active = i === index;
        slide.classList.toggle("is-active", active);
        slide.setAttribute("aria-hidden", active ? "false" : "true");
        // Keep hidden slides out of the tab order
        var focusables = slide.querySelectorAll("a, button");
        Array.prototype.forEach.call(focusables, function (el) {
          if (active) el.removeAttribute("tabindex");
          else el.setAttribute("tabindex", "-1");
        });
      });

      Array.prototype.forEach.call(dots, function (dot, i) {
        dot.setAttribute("aria-selected", i === index ? "true" : "false");
        dot.setAttribute("tabindex", i === index ? "0" : "-1");
      });

      if (live) {
        live.textContent = "Diapositiva " + (index + 1) + " de " + slides.length;
      }

      restartProgress();
    }

    function next() {
      goTo(index + 1);
    }

    function prev() {
      goTo(index - 1);
    }

    function play() {
      if (reduceMotion) return;
      stop();
      timer = window.setInterval(next, AUTOPLAY_MS);
      restartProgress();
    }

    function stop() {
      if (timer) {
        window.clearInterval(timer);
        timer = null;
      }
      if (progress) progress.classList.remove("is-running");
    }

    /* --- Controls --- */
    if (nextBtn) {
      nextBtn.addEventListener("click", function () {
        next();
        play();
      });
    }

    if (prevBtn) {
      prevBtn.addEventListener("click", function () {
        prev();
        play();
      });
    }

    Array.prototype.forEach.call(dots, function (dot, i) {
      dot.addEventListener("click", function () {
        goTo(i);
        play();
      });
    });

    /* --- Keyboard --- */
    root.addEventListener("keydown", function (e) {
      if (e.key === "ArrowRight") {
        e.preventDefault();
        next();
        play();
      } else if (e.key === "ArrowLeft") {
        e.preventDefault();
        prev();
        play();
      }
    });

    /* --- Pause while the user is reading or interacting --- */
    root.addEventListener("mouseenter", stop);
    root.addEventListener("mouseleave", play);
    root.addEventListener("focusin", stop);
    root.addEventListener("focusout", function (e) {
      if (!root.contains(e.relatedTarget)) play();
    });

    // Stop burning cycles when the tab is in the background
    document.addEventListener("visibilitychange", function () {
      if (document.hidden) stop();
      else play();
    });

    /* --- Touch swipe ---
       Horizontal only, and only past a threshold, so it never fights
       the vertical page scroll. */
    var startX = 0;
    var startY = 0;
    var tracking = false;

    root.addEventListener(
      "touchstart",
      function (e) {
        startX = e.changedTouches[0].clientX;
        startY = e.changedTouches[0].clientY;
        tracking = true;
        stop();
      },
      { passive: true }
    );

    root.addEventListener(
      "touchend",
      function (e) {
        if (!tracking) return;
        tracking = false;

        var dx = e.changedTouches[0].clientX - startX;
        var dy = e.changedTouches[0].clientY - startY;

        if (Math.abs(dx) > SWIPE_THRESHOLD && Math.abs(dx) > Math.abs(dy)) {
          if (dx < 0) next();
          else prev();
        }

        play();
      },
      { passive: true }
    );

    goTo(0);
    play();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initHeroCarousel);
  } else {
    initHeroCarousel();
  }
})();
