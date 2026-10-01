/* Reseñas: formulario propio <form data-resena>. Guarda la reseña en dblv-leads (página «resenas»)
   y David recibe un aviso por correo. No se publica sola: se revisa antes de añadirla a la web. */
(function () {
  "use strict";
  var API = "https://dblv-leads.davidbello.workers.dev/api/v1/leads";
  var TXT = {
    es: { nombre: "Dime tu nombre.", correo: "Revisa el correo: parece que falta algo.", texto: "Cuéntame algo más: unas pocas líneas bastan.",
          casilla: "Marca la casilla para poder publicarla.", espera: "Enviando…",
          fallo: "No se pudo enviar. Inténtalo de nuevo en un momento.",
          gracias: "¡Gracias! La leo yo mismo y la publico en cuanto pueda." },
    en: { nombre: "Tell me your name.", correo: "Check the email address: something is missing.", texto: "Tell me a bit more: a few lines are enough.",
          casilla: "Tick the box so it can be published.", espera: "Sending…",
          fallo: "It couldn't be sent. Please try again in a moment.",
          gracias: "Thank you! I read every review myself and will publish it as soon as I can." }
  };
  document.querySelectorAll("form[data-resena]").forEach(function (form) {
    var t = TXT[(form.getAttribute("lang") || document.documentElement.lang || "es").slice(0, 2)] || TXT.es;
    var msg = form.querySelector(".msg"), boton = form.querySelector("button[type=submit]");
    function campo(n) { return form.elements[n]; }
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var nombre = campo("name").value.trim(), correo = campo("email").value.trim(), texto = campo("message").value.trim();
      msg.className = "msg err";
      if (!nombre) { msg.textContent = t.nombre; campo("name").focus(); return; }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(correo)) { msg.textContent = t.correo; campo("email").focus(); return; }
      if (texto.length < 20) { msg.textContent = t.texto; campo("message").focus(); return; }
      if (!campo("acepto").checked) { msg.textContent = t.casilla; return; }
      boton.disabled = true; msg.className = "msg"; msg.textContent = t.espera;
      var ahora = new Date().toISOString();
      var p = {
        page: form.getAttribute("data-page") || "resenas", source: "resena",
        name: nombre, city: campo("city").value.trim(), email: correo, message: texto,
        rating: Number((form.querySelector("input[name=rating]:checked") || {}).value || 0),
        website: campo("website").value,
        referrer: location.pathname, language: navigator.language || "",
        timezone: (Intl.DateTimeFormat().resolvedOptions().timeZone) || "",
        screen: screen.width + "x" + screen.height,
        device: /Mobi|Android/i.test(navigator.userAgent) ? "mobile" : "desktop",
        arrived_at: ahora, captured_at: ahora
      };
      fetch(API, { method: "POST", mode: "cors", credentials: "omit",
                   headers: { "Content-Type": "text/plain;charset=UTF-8" }, body: JSON.stringify(p) })
        .then(function (r) { return r.ok; }).catch(function () { return false; })
        .then(function (ok) {
          if (!ok) { boton.disabled = false; msg.className = "msg err"; msg.textContent = t.fallo; return; }
          form.querySelectorAll(".resena-pide").forEach(function (el) { el.hidden = true; });
          msg.className = "msg done"; msg.textContent = t.gracias;
        });
    });
  });
})();
