/* ==========================================================================
   Quantuk Perú — forms.js
   Validación y entrega de los formularios de contacto y cotización.

   MODO DE ENTREGA
   ---------------
   Los formularios llevan data-whatsapp="51943704298": al enviar, se arma un
   mensaje con los datos y se abre WhatsApp con todo prellenado.

   Para cambiar a envío por correo más adelante, quitá data-whatsapp y poné
   data-endpoint="TU_URL" (Formspree, Web3Forms o tu propio PHP). El código
   de abajo ya soporta las dos rutas.

   NOTA IMPORTANTE sobre el popup
   ------------------------------
   window.open() se llama de forma SÍNCRONA dentro del handler del submit,
   aprovechando el gesto del usuario. Si se llamara dentro de un .then(),
   Firefox y Safari lo bloquearían como ventana emergente no solicitada.
   No mover esa llamada dentro de una promesa.
   ========================================================================== */

(function () {
  "use strict";

  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  // Móvil o fijo peruano, tolerante a +51, espacios y guiones
  var PHONE_RE = /^[+]?[\d\s()-]{7,20}$/;

  /* ------------------------------------------------------------------------
     Validación por campo
     ------------------------------------------------------------------------ */
  function validateField(control) {
    var field = control.closest(".field");
    if (!field) return true;

    var errorEl = field.querySelector(".field__error");
    var value = (control.value || "").trim();
    var message = "";

    if (control.hasAttribute("required")) {
      if (control.type === "checkbox" && !control.checked) {
        message = "Debes aceptar para continuar.";
      } else if (control.type !== "checkbox" && !value) {
        message = "Este campo es obligatorio.";
      }
    }

    if (!message && value) {
      if (control.type === "email" && !EMAIL_RE.test(value)) {
        message = "Ingresa un correo válido, por ejemplo nombre@empresa.com";
      } else if (control.type === "tel" && !PHONE_RE.test(value)) {
        message = "Ingresa un teléfono válido, por ejemplo +51 943 704 298";
      } else if (control.tagName === "TEXTAREA" && value.length < 10) {
        message = "Cuéntanos un poco más, con al menos 10 caracteres.";
      }
    }

    if (message) {
      field.classList.add("has-error");
      control.setAttribute("aria-invalid", "true");
      if (errorEl) {
        var text = errorEl.querySelector("[data-error-text]");
        if (text) text.textContent = message;
        else errorEl.textContent = message;
      }
      return false;
    }

    field.classList.remove("has-error");
    control.removeAttribute("aria-invalid");
    return true;
  }

  /* ------------------------------------------------------------------------
     Armado del mensaje de WhatsApp
     Toma la etiqueta visible de cada campo para que el mensaje se lea como
     una ficha ordenada y no como un volcado de nombres técnicos.
     ------------------------------------------------------------------------ */
  function buildWhatsAppMessage(form) {
    var lines = ["*Solicitud desde quantukperu.com*", ""];
    var controls = form.querySelectorAll(".field__control");

    Array.prototype.forEach.call(controls, function (control) {
      var value = (control.value || "").trim();
      if (!value) return;

      if (control.tagName === "SELECT") {
        var opt = control.options[control.selectedIndex];
        value = opt ? opt.text : value;
      }

      var labelEl = form.querySelector('label[for="' + control.id + '"]');
      var label = labelEl
        ? labelEl.textContent.replace("*", "").trim()
        : control.name;

      lines.push("*" + label + ":* " + value);
    });

    return lines.join("\n");
  }

  function whatsappUrl(form) {
    var phone = form.getAttribute("data-whatsapp").replace(/\D/g, "");
    return "https://wa.me/" + phone + "?text=" + encodeURIComponent(buildWhatsAppMessage(form));
  }

  /* ------------------------------------------------------------------------
     Envío por endpoint (ruta alternativa, hoy sin usar)
     ------------------------------------------------------------------------ */
  function postToEndpoint(form) {
    return fetch(form.getAttribute("data-endpoint"), {
      method: "POST",
      headers: { Accept: "application/json" },
      body: new FormData(form)
    }).then(function (response) {
      if (!response.ok) throw new Error("HTTP " + response.status);
      return true;
    });
  }

  /* ------------------------------------------------------------------------
     Mensajes de estado
     ------------------------------------------------------------------------ */
  function showStatus(form, type, message, linkUrl, linkText) {
    var status = form.querySelector(".form__status");
    if (!status) return;

    status.classList.remove("form__status--ok", "form__status--error");
    status.classList.add("is-visible", "form__status--" + type);

    // El icono acompaña al estado: un tilde de éxito sobre un mensaje de
    // error se contradice con el color y confunde más de lo que ayuda.
    var use = status.querySelector("svg use");
    if (use) {
      var icon = type === "ok" ? "#i-check" : "#i-alert";
      use.setAttribute("href", icon);
      use.setAttribute("xlink:href", icon);
    }

    var text = status.querySelector("[data-status-text]");
    if (text) text.textContent = message;

    var link = status.querySelector("[data-status-link]");
    if (link) {
      if (linkUrl) {
        link.href = linkUrl;
        link.textContent = linkText || "Abrir";
        link.hidden = false;
      } else {
        link.hidden = true;
      }
    }
  }

  function hideStatus(form) {
    var status = form.querySelector(".form__status");
    if (status) status.classList.remove("is-visible");
  }

  /* ------------------------------------------------------------------------
     Cableado
     ------------------------------------------------------------------------ */
  function initForm(form) {
    var controls = form.querySelectorAll(".field__control, .field--check input");
    var submitBtn = form.querySelector('[type="submit"]');

    // Validamos al salir del campo, nunca en cada tecla: corregir a alguien
    // mientras escribe una palabra es hostil. Si el campo ya está en error,
    // sí revalidamos mientras lo arregla.
    Array.prototype.forEach.call(controls, function (control) {
      control.addEventListener("blur", function () {
        validateField(control);
      });

      control.addEventListener("input", function () {
        var field = control.closest(".field");
        if (field && field.classList.contains("has-error")) validateField(control);
      });

      if (control.type === "checkbox") {
        control.addEventListener("change", function () {
          validateField(control);
        });
      }
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      hideStatus(form);

      var firstInvalid = null;
      Array.prototype.forEach.call(controls, function (control) {
        var valid = validateField(control);
        if (!valid && !firstInvalid) firstInvalid = control;
      });

      if (firstInvalid) {
        // Llevamos el foco al primer problema para que quien navega con
        // teclado o lector de pantalla vaya directo a lo que hay que corregir.
        firstInvalid.focus();
        showStatus(form, "error", "Revisa los campos marcados y vuelve a enviar.");
        return;
      }

      /* --- Ruta WhatsApp -------------------------------------------------
         SÍNCRONA a propósito. Ver la nota del encabezado del archivo. */
      if (form.hasAttribute("data-whatsapp")) {
        var url = whatsappUrl(form);
        // Sin "noopener" en los features: con esa bandera window.open
        // devuelve null por especificación aunque la ventana sí se abra,
        // y no podríamos distinguir el éxito de un bloqueo. Anulamos
        // opener a mano, que da la misma protección.
        var win = window.open(url, "_blank");
        if (win) win.opener = null;

        if (win) {
          showStatus(
            form,
            "ok",
            "Abrimos WhatsApp con tu solicitud lista para enviar. Solo falta que presiones enviar en la conversación."
          );
          form.reset();

          var modal = form.closest(".quote-modal");
          if (modal && modal.closeModal) window.setTimeout(modal.closeModal, 3200);
        } else {
          // El navegador bloqueó la ventana: dejamos el enlace a mano.
          showStatus(
            form,
            "error",
            "Tu navegador bloqueó la ventana emergente.",
            url,
            "Abrir WhatsApp manualmente"
          );
        }
        return;
      }

      /* --- Ruta endpoint (correo) ---------------------------------------- */
      if (!form.hasAttribute("data-endpoint")) {
        showStatus(
          form,
          "error",
          "El formulario no tiene configurado un destino de envío. Escríbenos a info@quantukperu.com"
        );
        return;
      }

      if (submitBtn) {
        submitBtn.setAttribute("aria-busy", "true");
        // innerHTML y no textContent: el botón lleva un icono SVG dentro y
        // asignar textContent lo destruiría sin posibilidad de restaurarlo.
        submitBtn.dataset.label = submitBtn.innerHTML;
        submitBtn.textContent = "Enviando…";
      }

      postToEndpoint(form)
        .then(function () {
          form.reset();
          showStatus(
            form,
            "ok",
            "Gracias por escribirnos. Hemos recibido tu mensaje y te responderemos dentro de las próximas 24 horas hábiles."
          );
          var modal = form.closest(".quote-modal");
          if (modal && modal.closeModal) window.setTimeout(modal.closeModal, 2600);
        })
        .catch(function () {
          showStatus(
            form,
            "error",
            "No pudimos enviar tu mensaje. Inténtalo nuevamente o escríbenos a info@quantukperu.com"
          );
        })
        .finally(function () {
          if (submitBtn) {
            submitBtn.removeAttribute("aria-busy");
            submitBtn.innerHTML = submitBtn.dataset.label || "Enviar";
          }
        });
    });
  }

  function init() {
    var forms = document.querySelectorAll("[data-form]");
    Array.prototype.forEach.call(forms, initForm);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
