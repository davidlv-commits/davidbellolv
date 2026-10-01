/* Estadística propia de las páginas-hub (/hola, /musical): visitas y clics agregados, sin cookies.
   La página se identifica con <body data-page="…">; cada enlace medido lleva data-k="…". */
(function () {
  "use strict";
  var API = "https://dblv-leads.davidbello.workers.dev/api/v1/leads";
  var q = new URLSearchParams(location.search);
  var base = {
    page: document.body.getAttribute("data-page") || "hub",
    referrer: document.referrer || "",
    utm_source: q.get("utm_source") || "", utm_medium: q.get("utm_medium") || "", utm_campaign: q.get("utm_campaign") || "",
    language: navigator.language || "",
    timezone: (Intl.DateTimeFormat().resolvedOptions().timeZone) || "",
    screen: screen.width + "x" + screen.height,
    device: /Mobi|Android/i.test(navigator.userAgent) ? "mobile" : "desktop",
    arrived_at: new Date().toISOString()
  };
  // Petición «simple» (text/plain, sin credenciales): no necesita preflight y permite saber si llegó.
  function enviar(extra) {
    var p = {}; for (var k in base) p[k] = base[k]; for (var e in extra) p[e] = extra[e];
    p.captured_at = new Date().toISOString();
    return fetch(API, { method: "POST", mode: "cors", credentials: "omit", keepalive: true,
                        headers: { "Content-Type": "text/plain;charset=UTF-8" }, body: JSON.stringify(p) })
      .then(function (r) { return r.ok; }).catch(function () { return false; });
  }
  enviar({ source: "visita" });
  document.querySelectorAll("[data-k]").forEach(function (a) {
    a.addEventListener("click", function () { enviar({ source: "clic:" + a.getAttribute("data-k") }); });
  });
})();
