# davidbellolv.com

Web de David Bello: el centro de su universo (música, novelas, la app
«Tú de qué vas», el Cuaderno de bitácora). Estática: HTML, CSS y JS sin
compilar.

## Dónde vive

En **Google (Firebase Hosting)**: sitio `davidbellolv` del proyecto
`tdqv-app`, con dirección provisional `https://davidbellolv.web.app`.

Hasta octubre de 2026 estuvo en Cloudflare Workers. Se sacó de allí porque
las operadoras españolas bloquean direcciones de Cloudflare durante las
retransmisiones de fútbol: las dos del dominio pasaron bloqueadas entre 41 y
60 horas en 30 días, según la operadora. Las de Google no han estado
bloqueadas nunca en ese registro (hayahora.futbol).

Lo que sigue en Cloudflare, a propósito:

- el DNS del dominio (zona `davidbellolv.com`);
- el worker `dblv-leads` (`~/Developer/dblv-leads-worker`): estadística
  propia, regalo y reseñas. La web lo llama en
  `dblv-leads.davidbello.workers.dev`, cuyas direcciones no han estado
  bloqueadas; si llegaran a estarlo, habría que pasarlo por Google como se
  hizo con los archivos de la app (función `media` de `tdqv_flutter`);
- el worker `davidbellolv` (la copia anterior de la web), como reserva en
  `davidbellolv.davidbello.workers.dev`.

## Publicar

```bash
bash scripts/deploy.sh            # publica y comprueba
bash scripts/deploy.sh --dry-run  # solo enseña qué subiría
```

Usa la cuenta de despliegue (`~/.config/gcloud/claude-deploy.json`), que
tiene el papel de alojamiento web en `tdqv-app`. Qué se publica lo decide
`firebase.json` (`ignore`): fuera quedan los restos del alojamiento antiguo
(`*.php`, `evento-soledad`, `s`, `scripts`) y la configuración.

**No publicar con `wrangler deploy`**: eso actualiza la copia de reserva de
Cloudflare, que ya no es la que ve la gente.

## Ver en local

```bash
python3 -m http.server 8080
```

## Estructura

- `index.html`, `en/` — portada (el musical) en español e inglés
- `hola/` — página de enlaces de la bio
- `musical/` — el álbum
- `libre-conmigo/` — campaña del sencillo
- `backoffice/` — panel de métricas
- `aviso-legal/`, `politica-privacidad/`, `cookies/`
- `js/` — `hub.js` (visitas y clics), `regalo.js`, `resena.js`,
  `analytics.js`
- `img/`, `video/`, `descargas/`
