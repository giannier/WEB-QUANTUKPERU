/* ==========================================================================
   Quantuk Perú — reclamaciones.js
   Libro de Reclamaciones: validación en el navegador y envío al endpoint.

   La validación de acá es comodidad para el visitante, NO seguridad: quien
   quiera saltársela solo tiene que abrir la consola. La validación que
   cuenta vive en api/src/Validador.php, del lado del servidor.
   ========================================================================== */

(function () {
  "use strict";

  var form = document.getElementById("formReclamo");
  if (!form) return;

  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  var PHONE_RE = /^[+]?[0-9()\s.-]{6,25}$/;

  var enviando = false;
  var esperaMinima = 6;
  var cargadoEn = Date.now();

  /* ------------------------------------------------------------------------
     Token: se pide al cargar la página y liga el envío a esta sesión
     ------------------------------------------------------------------------ */
  function pedirToken() {
    fetch("/api/token.php", { credentials: "same-origin", cache: "no-store" })
      .then(function (r) {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      })
      .then(function (d) {
        document.getElementById("lr-token").value = d.token || "";
        if (d.espera_minima) esperaMinima = d.espera_minima;
        cargadoEn = Date.now();
      })
      .catch(function () {
        // Sin token el envío será rechazado; se avisa acá y no al enviar,
        // para no hacerle llenar todo el formulario en vano.
        mostrarEstado(
          "error",
          "No pudimos preparar el formulario. Recarga la página o escríbenos a reclamaciones@quantukperu.com"
        );
      });
  }

  /* ------------------------------------------------------------------------
     Campos del representante: solo si el consumidor es menor de edad
     ------------------------------------------------------------------------ */
  function initMenor() {
    var check = document.getElementById("lr-menor");
    var bloque = document.getElementById("lr-tutor");
    if (!check || !bloque) return;

    function sincronizar() {
      bloque.hidden = !check.checked;

      var campos = bloque.querySelectorAll("input");
      Array.prototype.forEach.call(campos, function (c) {
        if (check.checked) {
          c.setAttribute("required", "required");
        } else {
          c.removeAttribute("required");
          c.value = "";
          limpiarError(c);
        }
      });
    }

    check.addEventListener("change", sincronizar);
    sincronizar();
  }

  /* ------------------------------------------------------------------------
     Contadores de caracteres
     ------------------------------------------------------------------------ */
  function initContadores() {
    var areas = form.querySelectorAll("[data-contador]");
    Array.prototype.forEach.call(areas, function (area) {
      var salida = document.getElementById(area.getAttribute("data-contador"));
      if (!salida) return;

      function actualizar() {
        salida.textContent = String(area.value.length);
      }

      area.addEventListener("input", actualizar);
      actualizar();
    });
  }

  /* ------------------------------------------------------------------------
     Validación por campo
     ------------------------------------------------------------------------ */
  function contenedor(control) {
    return control.closest(".field") || control.closest(".lr-fieldset");
  }

  function marcarError(control, mensaje) {
    var caja = contenedor(control);
    if (!caja) return;

    caja.classList.add("has-error");
    control.setAttribute("aria-invalid", "true");

    var error = caja.querySelector(".field__error");
    if (error) {
      var texto = error.querySelector("[data-error-text]");
      if (texto) texto.textContent = mensaje;
    }
  }

  function limpiarError(control) {
    var caja = contenedor(control);
    if (!caja) return;
    caja.classList.remove("has-error");
    control.removeAttribute("aria-invalid");
  }

  function validarCampo(control) {
    if (control.type === "radio" || control.type === "checkbox") return true;
    if (!control.hasAttribute("required") && control.value.trim() === "") return true;

    var v = control.value.trim();
    var mensaje = "";

    if (control.hasAttribute("required") && v === "") {
      mensaje = "Este campo es obligatorio.";
    } else if (v !== "") {
      if (control.type === "email" && !EMAIL_RE.test(v)) {
        mensaje = "Ingresa un correo válido.";
      } else if (control.type === "tel" && !PHONE_RE.test(v)) {
        mensaje = "Ingresa un teléfono válido.";
      } else if (control.id === "lr-documento") {
        var tipo = document.getElementById("lr-tipo-doc").value;
        var limpio = v.replace(/[^A-Za-z0-9]/g, "");
        if (tipo === "DNI" && !/^\d{8}$/.test(limpio)) mensaje = "El DNI debe tener 8 dígitos.";
        else if (tipo === "RUC" && !/^\d{11}$/.test(limpio)) mensaje = "El RUC debe tener 11 dígitos.";
        else if (limpio.length < 5) mensaje = "El número de documento es demasiado corto.";
      } else if (control.id === "lr-detalle" && v.length < 20) {
        mensaje = "Cuéntanos un poco más, con al menos 20 caracteres.";
      } else if (control.id === "lr-pedido" && v.length < 10) {
        mensaje = "Indica qué solicitas.";
      } else if (control.id === "lr-monto" && v !== "") {
        var n = parseFloat(v.replace(/[^\d.,]/g, "").replace(",", "."));
        if (isNaN(n) || n < 0) mensaje = "El monto no es válido.";
      } else if (v.length < 2) {
        mensaje = "Este campo es demasiado corto.";
      }
    }

    if (mensaje) {
      marcarError(control, mensaje);
      return false;
    }

    limpiarError(control);
    return true;
  }

  function validarGrupo(nombre, mensaje) {
    var elegidos = form.querySelectorAll('[name="' + nombre + '"]:checked');
    var caja = form.querySelector('[data-error-for="' + nombre + '"]');

    if (elegidos.length === 0) {
      if (caja) {
        caja.classList.add("is-visible");
        var texto = caja.querySelector("[data-error-text]");
        if (texto) texto.textContent = mensaje;
      }
      return false;
    }

    if (caja) caja.classList.remove("is-visible");
    return true;
  }

  /* ------------------------------------------------------------------------
     Estado
     ------------------------------------------------------------------------ */
  function mostrarEstado(tipo, mensaje) {
    var caja = form.querySelector(".form__status");
    if (!caja) return;

    caja.classList.remove("form__status--ok", "form__status--error");
    caja.classList.add("is-visible", "form__status--" + tipo);

    var use = caja.querySelector("svg use");
    if (use) {
      var icono = tipo === "ok" ? "#i-check" : "#i-alert";
      use.setAttribute("href", icono);
      use.setAttribute("xlink:href", icono);
    }

    var texto = caja.querySelector("[data-status-text]");
    if (texto) texto.textContent = mensaje;
  }

  function ocultarEstado() {
    var caja = form.querySelector(".form__status");
    if (caja) caja.classList.remove("is-visible");
  }

  /* ------------------------------------------------------------------------
     Envío
     ------------------------------------------------------------------------ */
  function initEnvio() {
    var boton = document.getElementById("lr-enviar");
    var controles = form.querySelectorAll(".field__control");

    Array.prototype.forEach.call(controles, function (c) {
      c.addEventListener("blur", function () { validarCampo(c); });
      c.addEventListener("input", function () {
        var caja = contenedor(c);
        if (caja && caja.classList.contains("has-error")) validarCampo(c);
      });
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (enviando) return;
      ocultarEstado();

      var primerInvalido = null;

      Array.prototype.forEach.call(form.querySelectorAll(".field__control"), function (c) {
        if (c.closest("[hidden]")) return;   // el bloque del representante oculto no se valida
        if (!validarCampo(c) && !primerInvalido) primerInvalido = c;
      });

      var okTipo = validarGrupo("tipo_reclamo", "Elige si es un reclamo o una queja.");
      var okConf = validarGrupo("conformidad", "Debes confirmar que la información es veraz.");
      var okPriv = validarGrupo("acepta_privacidad", "Debes aceptar la política de privacidad.");

      if (primerInvalido) {
        primerInvalido.focus();
        mostrarEstado("error", "Revisa los campos marcados.");
        return;
      }

      if (!okTipo || !okConf || !okPriv) {
        mostrarEstado("error", "Revisa los campos marcados.");
        return;
      }

      // El servidor rechaza los envíos demasiado rápidos; se avisa acá antes
      // de gastar el token en un intento que va a fallar.
      var transcurrido = (Date.now() - cargadoEn) / 1000;
      if (transcurrido < esperaMinima) {
        mostrarEstado("error", "Un momento, estamos preparando el envío. Vuelve a pulsar en unos segundos.");
        return;
      }

      enviando = true;
      boton.setAttribute("aria-busy", "true");
      var etiqueta = boton.textContent;
      boton.textContent = "Registrando…";

      fetch("/api/reclamo.php", {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        body: new FormData(form)
      })
        .then(function (r) {
          return r.json().then(function (d) { return { estado: r.status, datos: d }; });
        })
        .then(function (r) {
          var d = r.datos || {};

          if (d.ok) {
            mostrarExito(d);
            return;
          }

          // Errores campo por campo devueltos por el servidor
          if (d.errores) {
            Object.keys(d.errores).forEach(function (campo) {
              var control = form.querySelector('[name="' + campo + '"]');
              if (control) marcarError(control, d.errores[campo]);
            });
          }

          mostrarEstado("error", d.mensaje || "No pudimos registrar tu reclamo. Inténtalo de nuevo.");

          // Si el token venció, se pide otro para que pueda reintentar
          if (r.estado === 419 || r.estado === 403) pedirToken();
        })
        .catch(function () {
          mostrarEstado(
            "error",
            "No pudimos conectar con el servidor. Revisa tu conexión o escríbenos a reclamaciones@quantukperu.com"
          );
        })
        .finally(function () {
          enviando = false;
          boton.removeAttribute("aria-busy");
          boton.textContent = etiqueta;
        });
    });
  }

  function mostrarExito(d) {
    var exito = document.getElementById("lrExito");
    if (!exito) return;

    document.getElementById("lrCorrelativo").textContent = d.correlativo || "";
    document.getElementById("lrDetalleExito").textContent = d.mensaje || "";

    form.hidden = true;
    exito.hidden = false;
    exito.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function init() {
    pedirToken();
    initMenor();
    initContadores();
    initEnvio();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
