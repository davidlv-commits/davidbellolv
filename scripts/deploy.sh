#!/bin/bash
# Publica davidbellolv.com en Google (Firebase Hosting, sitio «davidbellolv»
# del proyecto tdqv-app) con la cuenta de despliegue: no necesita la sesión
# de nadie.
#
#   bash scripts/deploy.sh            publica y comprueba
#   bash scripts/deploy.sh --dry-run  solo enseña qué subiría
#
# Qué se publica lo decide firebase.json («ignore»). La web estuvo en
# Cloudflare Workers hasta octubre de 2026; se sacó de allí porque las
# operadoras españolas bloquean sus direcciones en horas de fútbol.
set -euo pipefail
cd "$(dirname "$0")/.."

PROJECT="tdqv-app"
SITE="davidbellolv"
KEY="${WEB_DEPLOY_KEY:-$HOME/.config/gcloud/claude-deploy.json}"
[ -r "$KEY" ] || { echo "Falta la clave de la cuenta de despliegue: $KEY" >&2; exit 66; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
run() {
  env -u FIREBASE_TOKEN XDG_CONFIG_HOME="$WORK/config" \
    GOOGLE_APPLICATION_CREDENTIALS="$KEY" GOOGLE_CLOUD_QUOTA_PROJECT="$PROJECT" \
    firebase "$@" --project "$PROJECT" --non-interactive
}

if [ "${1:-}" = "--dry-run" ]; then
  run deploy --only "hosting:$SITE" --dry-run
  exit 0
fi

run deploy --only "hosting:$SITE"

echo "===> comprobación"
fail=0
check() { # url esperado
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$1")"
  if [ "$code" = "$2" ]; then echo "    $1 → $code"; else echo "    $1 → $code (esperado $2)" >&2; fail=1; fi
}
for base in "https://$SITE.web.app" "https://davidbellolv.com"; do
  check "$base/" 200
  check "$base/hola/" 200
  check "$base/musical/" 200
  check "$base/firebase.json" 404
done
exit $fail
