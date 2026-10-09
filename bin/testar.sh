#!/usr/bin/env bash
# Construtor Rankly · roda todos os testes: JS (node:test), PHP (PHPUnit), paridade JS≡PHP
# e, se existir, o ponta a ponta (Playwright). Termina com erro se alguma etapa falhar.
#
# Uso: bin/testar.sh [--mysql] [--apache] [--sem-e2e]
#   --mysql    PHPUnit também no MariaDB local (banco rankly_teste), além do SQLite
#   --apache   testa o sites/.htaccess no Apache de verdade (precisa de apache2 + mod_php)
#   --sem-e2e  pula o Playwright (que usa o MariaDB rankly_teste, ou SQLite se ele não responder)

set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"

COM_E2E=1
MYSQL=0
APACHE=0
for arg in "$@"; do
  case "$arg" in
    --mysql) MYSQL=1 ;;
    --apache) APACHE=1 ;;
    --sem-e2e) COM_E2E=0 ;;
    -h|--ajuda) sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Opção desconhecida: $arg" >&2; exit 2 ;;
  esac
done

falhas=()
etapa() {
  local nome="$1"
  shift
  echo
  echo "=== ${nome}"
  local inicio=$SECONDS
  if "$@"; then
    echo "--- ${nome}: ok ($((SECONDS - inicio)) s)"
  else
    echo "--- ${nome}: FALHOU ($((SECONDS - inicio)) s)"
    falhas+=("$nome")
  fi
}

# Node 22 não aceita pasta em "node --test tests/js/" (script do package.json): passa um glob
# (entre aspas: quem expande é o próprio node, inclusive subpastas).
etapa "JS (node --test)" node --test 'tests/js/**/*.test.mjs'
etapa "PHP (PHPUnit, SQLite)" env RANKLY_TESTE_APACHE="$APACHE" vendor/bin/phpunit -c tests/php/phpunit.xml
if [ "$MYSQL" = "1" ]; then
  etapa "PHP (PHPUnit, MariaDB)" env RANKLY_TESTE_MYSQL=1 vendor/bin/phpunit -c tests/php/phpunit.xml
fi
etapa "Paridade JS≡PHP" npm run --silent paridade
if [ "$COM_E2E" = "1" ] && [ -f tests/e2e/playwright.config.mjs ]; then
  etapa "Estresse de layout (todas as seções)" npm run --silent estresse
  etapa "Ponta a ponta (Playwright)" npx --no-install playwright test --config tests/e2e/playwright.config.mjs
elif [ "$COM_E2E" = "1" ]; then
  echo
  echo "(tests/e2e/playwright.config.mjs não existe: ponta a ponta pulado)"
fi

echo
if [ "${#falhas[@]}" -gt 0 ]; then
  echo "FALHOU: ${falhas[*]}"
  exit 1
fi
echo "Tudo certo."
