/* El regalo: el libreto del musical. El formulario <form data-regalo data-page="…"> pide el correo,
   lo guarda en dblv-leads y entrega la descarga al momento (sin esperar a ningún correo). */
(function () {
  "use strict";
  var API = "https://dblv-leads.davidbello.workers.dev/api/v1/leads";
  var PDF = "/descargas/libreto-tu-de-que-vas-el-musical.pdf";
  var CLAVE = "dblv-regalo-libreto", PENDIENTE = "dblv-regalo-pendiente";
  var TXT = {
    es: { correo: "Revisa el correo: parece que falta algo.", casilla: "Marca la casilla para poder dártelo.", espera: "Un segundo…",
          listo: "¡Aquí lo tienes! Si la descarga no empieza sola, pulsa el botón.", vuelta: "Ya es tuyo: descárgalo cuando quieras.",
          boton: "Descargar el libreto (PDF)", archivo: "Libreto - Tú de qué vas, El Musical.pdf" },
    en: { correo: "Check the email address: something is missing.", casilla: "Tick the box so I can give it to you.", espera: "One second…",
          listo: "Here it is! If the download doesn't start by itself, tap the button.", vuelta: "It's yours: download it whenever you like.",
          boton: "Download the booklet (PDF)", archivo: "Booklet - Tú de qué vas, El Musical.pdf" }
  };
  function guardado(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function guardar(k, v) { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {} }
  function enviar(p) {
    return fetch(API, { method: "POST", mode: "cors", credentials: "omit", keepalive: true,
                        headers: { "Content-Type": "text/plain;charset=UTF-8" }, body: JSON.stringify(p) })
      .then(function (r) { return r.ok; }).catch(function () { return false; });
  }
  // Un correo que no llegó a guardarse (sin cobertura, por ejemplo) se reintenta en la siguiente visita.
  var resto = guardado(PENDIENTE);
  if (resto) enviar(JSON.parse(resto)).then(function (ok) { if (ok) guardar(PENDIENTE, null); });

  document.querySelectorAll("form[data-regalo]").forEach(function (form) {
    var t = TXT[(form.getAttribute("lang") || document.documentElement.lang || "es").slice(0, 2)] || TXT.es;
    var msg = form.querySelector(".msg"), correo = form.querySelector("input[type=email]"),
        casilla = form.querySelector("input[type=checkbox]"), boton = form.querySelector("button");

    function entregar(texto, descargar) {
      form.querySelectorAll(".regalo-pide").forEach(function (el) { el.hidden = true; });
      var a = document.createElement("a");
      a.className = "regalo-descarga"; a.href = PDF; a.download = t.archivo; a.textContent = t.boton;
      form.insertBefore(a, msg);
      msg.className = "msg done"; msg.textContent = texto;
      if (descargar) a.click();
    }
    if (guardado(CLAVE)) { entregar(t.vuelta, false); return; }

    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var valor = correo.value.trim();
      msg.className = "msg err";
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(valor)) { msg.textContent = t.correo; correo.focus(); return; }
      if (!casilla.checked) { msg.textContent = t.casilla; return; }
      boton.disabled = true; msg.className = "msg"; msg.textContent = t.espera;
      var q = new URLSearchParams(location.search), ahora = new Date().toISOString();
      var p = {
        page: form.getAttribute("data-page") || "hola", source: "regalo-libreto", email: valor,
        referrer: document.referrer || "",
        utm_source: q.get("utm_source") || "", utm_medium: q.get("utm_medium") || "", utm_campaign: q.get("utm_campaign") || "",
        language: navigator.language || "",
        timezone: (Intl.DateTimeFormat().resolvedOptions().timeZone) || "",
        screen: screen.width + "x" + screen.height,
        device: /Mobi|Android/i.test(navigator.userAgent) ? "mobile" : "desktop",
        arrived_at: ahora, captured_at: ahora
      };
      enviar(p).then(function (ok) {
        if (!ok) guardar(PENDIENTE, JSON.stringify(p));      // el regalo se entrega igual
        guardar(CLAVE, "1");
        entregar(t.listo, true);
      });
    });
  });
})();
