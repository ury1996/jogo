#!/usr/bin/env bash
# Construtor Rankly · ambiente de desenvolvimento.
#
# Sobe o editor/API em http://localhost:8080 e os sites publicados em http://{slug}.localhost:8081
# (servidor embutido do PHP), aplica as migrações pendentes e roda o cron a cada minuto.
#
# Uso: bin/dev.sh [--sem-cron]
# Variáveis: PORTA_EDITOR (8080), PORTA_SITES (8081), HOST_DEV (127.0.0.1), RANKLY_CONFIG.

set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"

PORTA_EDITOR="${PORTA_EDITOR:-8080}"
PORTA_SITES="${PORTA_SITES:-8081}"
HOST_DEV="${HOST_DEV:-127.0.0.1}"
COM_CRON=1
for arg in "$@"; do
  case "$arg" in
    --sem-cron) COM_CRON=0 ;;
    -h|--ajuda) sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Opção desconhecida: $arg" >&2; exit 2 ;;
  esac
done

if [ ! -f vendor/autoload.php ]; then
  echo "Dependências PHP ausentes: rode 'composer install'." >&2
  exit 1
fi
if [ ! -d node_modules ]; then
  echo "Aviso: node_modules ausente ('npm install' para testes e ferramentas)." >&2
fi

if [ -z "${RANKLY_CONFIG:-}" ] && [ ! -f config/config.php ]; then
  cp config/config.exemplo.php config/config.php
  echo "Criado config/config.php a partir do exemplo (revise os segredos antes de usar fora do seu computador)."
fi

echo "== Migrações"
php app/cli/migrar.php || {
  echo "Não foi possível migrar o banco. O MariaDB/MySQL está rodando e config/config.php está certo?" >&2
  exit 1
}

usuarios="$(php -r '$app = require "app/bootstrap.php"; echo (int) $app->db()->valor("SELECT COUNT(*) FROM usuarios");' 2>/dev/null || echo '?')"
if [ "$usuarios" = "0" ]; then
  echo "Nenhum usuário ainda. Crie o primeiro com:"
  echo "  php app/cli/criar-usuario.php --nome=\"Seu Nome\" --email=voce@exemplo.com --papel=admin"
fi

dominio="$(php -r '$app = require "app/bootstrap.php"; echo $app->config("dominio_sites");' 2>/dev/null || echo '')"
if [ -n "$dominio" ] && [ "$dominio" != "localhost:${PORTA_SITES}" ]; then
  echo "Aviso: dominio_sites é '$dominio', mas os sites sobem em localhost:${PORTA_SITES}." >&2
fi

pids=()
encerrar() {
  for pid in "${pids[@]:-}"; do
    [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
  done
}
trap encerrar EXIT INT TERM

php -S "${HOST_DEV}:${PORTA_EDITOR}" -t public_html public_html/router-dev.php &
pids+=("$!")
php -S "${HOST_DEV}:${PORTA_SITES}" -t sites sites/router-dev.php &
pids+=("$!")

if [ "$COM_CRON" = "1" ]; then
  mkdir -p "$RAIZ/var/logs"
  (
    while true; do
      php app/cli/cron.php >> "$RAIZ/var/logs/cron-dev.log" 2>&1 || true
      sleep 60
    done
  ) &
  pids+=("$!")
fi

sleep 0.5
echo
echo "Editor/API: http://localhost:${PORTA_EDITOR}/editor/"
echo "Sites:      http://{slug}.localhost:${PORTA_SITES}/  (no curl: -H \"Host: {slug}.localhost:${PORTA_SITES}\")"
if [ "$COM_CRON" = "1" ]; then
  echo "Cron:       a cada 60 s (log em var/logs/cron-dev.log)"
fi
echo "Ctrl+C encerra tudo."
wait
