/* /hola · «¿Te vas sin tu regalo?» y «Avísame del próximo estreno» (31-oct-2026, David: «la gente va ahí, ve el vídeo
   directamente y se va… un popup de abandono para que, si no solicitaron ya su regalo, les diga ¿Te vas sin tu regalo?»).

   El aviso sale UNA vez (y no más de una cada 3 días) y solo a quien no ha pedido el libreto: al tocar un enlace que se va de
   la página (el vídeo, Spotify…) o, en ordenador, al llevar el ratón fuera de la ventana por arriba. «No, gracias» le lleva
   adonde iba. El formulario del aviso es el mismo del regalo (regalo.js lo atiende). */
(function () {
  "use strict";
  var API = "https://dblv-leads.davidbello.workers.dev/api/v1/leads";
  var REGALO = "dblv-regalo-libreto", VISTO = "dblv-salida-vista", AVISO = "dblv-aviso-estrenos";
  var DIAS = 3 * 864e5;
  function leer(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function escribir(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

  // ---- «Avísame del próximo estreno»
  document.querySelectorAll("form[data-aviso]").forEach(function (form) {
    var msg = form.querySelector(".msg"), correo = form.querySelector("input[type=email]"),
        casilla = form.querySelector("input[type=checkbox]"), boton = form.querySelector("button");
    function hecho(txt) {
      form.querySelectorAll(".row, .ok").forEach(function (el) { el.hidden = true; });
      msg.className = "msg done"; msg.textContent = txt;
    }
    if (leer(AVISO)) { hecho("Te aviso del próximo estreno. ¡Gracias!"); return; }
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var valor = correo.value.trim();
      msg.className = "msg err";
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(valor)) { msg.textContent = "Revisa el correo: parece que falta algo."; correo.focus(); return; }
      if (!casilla.checked) { msg.textContent = "Marca la casilla para poder avisarte."; return; }
      boton.disabled = true;
      var q = new URLSearchParams(location.search), ahora = new Date().toISOString();
      fetch(API, { method: "POST", mode: "cors", credentials: "omit", keepalive: true, headers: { "Content-Type": "text/plain;charset=UTF-8" },
                   body: JSON.stringify({ page: form.getAttribute("data-page") || "hola", source: "aviso-estrenos", email: valor,
                                          referrer: document.referrer || "", utm_source: q.get("utm_source") || "",
                                          utm_medium: q.get("utm_medium") || "", utm_campaign: q.get("utm_campaign") || "",
                                          language: navigator.language || "", arrived_at: ahora, captured_at: ahora }) })
        .catch(function () {})
        .then(function () { escribir(AVISO, "1"); hecho("¡Hecho! Te aviso del próximo estreno."); });
    });
  });

  // ---- «¿Te vas sin tu regalo?»
  var modal = document.getElementById("salida");
  if (!modal) return;
  var destino = null, sesion = false;
  function puede() {
    if (sesion || leer(REGALO)) return false;
    var v = +leer(VISTO) || 0;
    return Date.now() - v > DIAS;
  }
  function abrir(href) {
    destino = href || null; sesion = true; escribir(VISTO, String(Date.now()));
    modal.hidden = false;
    var seguir = modal.querySelector("[data-seguir]");
    seguir.textContent = destino ? "No, gracias: ir al vídeo" : "No, gracias";
    var c = modal.querySelector("input[type=email]"); if (c && matchMedia("(pointer:fine)").matches) c.focus();
  }
  function cerrar() { modal.hidden = true; }
  function irse() { var d = destino; cerrar(); if (d) window.open(d, "_blank", "noopener"); }

  modal.querySelector("[data-cerrar]").addEventListener("click", cerrar);
  modal.querySelector("[data-seguir]").addEventListener("click", function (ev) { ev.preventDefault(); irse(); });
  modal.addEventListener("click", function (ev) { if (ev.target === modal) cerrar(); });
  document.addEventListener("keydown", function (ev) { if (ev.key === "Escape" && !modal.hidden) cerrar(); });
  // cuando ya tiene el regalo, el botón de seguir lleva adonde iba
  new MutationObserver(function () {
    if (modal.querySelector(".regalo-descarga") && destino) modal.querySelector("[data-seguir]").textContent = "Ahora sí: ir al vídeo ›";
  }).observe(modal, { childList: true, subtree: true });

  // al irse por un enlace de fuera (el vídeo, Spotify, Amazon…)
  document.addEventListener("click", function (ev) {
    var a = ev.target.closest && ev.target.closest("a[href]");
    if (!a || modal.contains(a) || !puede()) return;
    if (a.target !== "_blank" && a.host === location.host) return;
    ev.preventDefault(); abrir(a.href);
  }, true);
  // en ordenador, al sacar el ratón por arriba de la ventana
  document.addEventListener("mouseout", function (ev) {
    if (!ev.relatedTarget && ev.clientY <= 0 && puede()) abrir(null);
  });
})();
