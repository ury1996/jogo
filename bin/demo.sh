#!/usr/bin/env bash
# Construtor Rankly · MODO DEMONSTRAÇÃO (para testar e mostrar para outras pessoas).
#
# Um servidor só, num endereço só: editor em /editor/ e sites publicados em /s/{slug}/.
# Banco SQLite, segredos gerados sozinhos, usuário de teste criado na primeira vez.
# Usado pelo Docker (Dockerfile), pelo GitHub Codespaces (.devcontainer) e à mão.
#
# Uso: bin/demo.sh
# Variáveis: PORTA (8080), RANKLY_URL (endereço público), RANKLY_DADOS (pasta dos dados),
#            DEMO_EMAIL / DEMO_SENHA (usuário de teste; num endereço público a senha padrão é
#            aleatória e fica em {dados}/acesso.txt), GEMINI_API_KEY (IA de verdade).

set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"

export RANKLY_CONFIG="${RANKLY_CONFIG:-$RAIZ/config/config.demo.php}"
export PORTA="${PORTA:-${PORT:-8080}}"
DEMO_EMAIL="${DEMO_EMAIL:-demo@rankly.app}"

if [ ! -f vendor/autoload.php ]; then
  echo "== Instalando dependências PHP (composer install)"
  composer install --no-interaction --no-progress --no-dev --optimize-autoloader
fi

echo "== Banco de dados"
php app/cli/migrar.php

url="$(php -r '$app = require "app/bootstrap.php"; echo $app->config("url_editor");')"
dados="$(php -r '$app = require "app/bootstrap.php"; echo dirname($app->dir("var"));')"

usuarios="$(php -r '$app = require "app/bootstrap.php"; echo (int) $app->db()->valor("SELECT COUNT(*) FROM usuarios");')"
if [ "$usuarios" = "0" ]; then
  if [ -z "${DEMO_SENHA:-}" ]; then
    case "$url" in
      http://localhost*|http://127.0.0.1*) DEMO_SENHA="demo12345" ;;
      # Endereço público: senha aleatória (uma senha fixa conhecida deixaria qualquer um entrar).
      *) DEMO_SENHA="$(php -r 'echo substr(strtr(base64_encode(random_bytes(12)), "+/", "xy"), 0, 12);')" ;;
    esac
  fi
  php app/cli/criar-usuario.php --nome="Conta de teste" --email="$DEMO_EMAIL" --papel=admin --senha="$DEMO_SENHA" >/dev/null
  printf 'E-mail: %s\nSenha: %s\n' "$DEMO_EMAIL" "$DEMO_SENHA" > "$dados/acesso.txt"
  chmod 600 "$dados/acesso.txt"
  echo "Usuário de teste criado (dados de acesso em $dados/acesso.txt)."
fi
acesso="$(cat "$dados/acesso.txt" 2>/dev/null || printf 'E-mail: %s\nSenha: (a que foi definida na primeira vez)' "$DEMO_EMAIL")"

ia="$(php -r '$app = require "app/bootstrap.php"; echo $app->config("ia.provedor");')"

pids=()
encerrar() {
  for pid in "${pids[@]:-}"; do
    [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
  done
}
trap encerrar EXIT INT TERM

# Cron (e-mails de aviso de contato, limpezas) a cada minuto.
(
  while true; do
    php app/cli/cron.php >/dev/null 2>&1 || true
    sleep 60
  done
) &
pids+=("$!")

cat <<TXT

  ┌──────────────────────────────────────────────────────────────
  │ Construtor Rankly · modo demonstração
  │
  │ Abra:   $url/editor/
$(printf '%s\n' "$acesso" | sed 's/^/  │ /')
  │ IA:     $ia$( [ "$ia" = "simulado" ] && echo " (defina GEMINI_API_KEY para a IA de verdade)" )
  │
  │ Sites publicados ficam em $url/s/{nome-do-site}/
  │ Ctrl+C desliga.
  └──────────────────────────────────────────────────────────────

TXT

# Vários processos do servidor embutido atendem pedidos ao mesmo tempo (Linux/macOS).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
php -d upload_max_filesize=16M -d post_max_size=20M -d max_execution_time=120 \
  -S "0.0.0.0:${PORTA}" -t public_html public_html/router-dev.php &
pids+=("$!")
wait "${pids[-1]}"
