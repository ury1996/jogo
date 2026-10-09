#!/usr/bin/env bash
# Instala ou atualiza o Construtor Rankly numa VPS (Ubuntu/Debian, ex.: VPS da Hostinger).
#
# Uso (como root, dentro da pasta do projeto):   bash bin/instalar-vps.sh
#
# O que faz, na ordem:
#   1. instala o Docker, se ainda não tiver;
#   2. baixa a versão nova do código (git pull), se a pasta veio do git;
#   3. pergunta o endereço (domínio ou subdomínio; vazio = usa o IP com sslip.io) e as chaves da IA
#      (Gemini) e do banco de imagens (Pixabay) — Enter mantém o que já estava;
#   4. grava tudo no .env (o arquivo fica só na VPS);
#   5. liga o sistema + o Caddy, que faz o HTTPS sozinho (Let's Encrypt), nas portas 80 e 443;
#   6. espera o endereço responder e mostra o e-mail e a senha de acesso.
#
# Rodar de novo é seguro: serve para atualizar o sistema ou trocar as chaves.
# Sem perguntas (automação): DOMINIO=… GEMINI_API_KEY=… PIXABAY_API_KEY=… bash bin/instalar-vps.sh --sim

set -euo pipefail
cd "$(dirname "$0")/.."

SIM=0
[ "${1:-}" = "--sim" ] && SIM=1

verde() { printf '\033[32m%s\033[0m\n' "$*"; }
amarelo() { printf '\033[33m%s\033[0m\n' "$*"; }
vermelho() { printf '\033[31m%s\033[0m\n' "$*" >&2; }
passo() { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
falhar() { vermelho "✗ $*"; exit 1; }

# Perguntas leem do terminal (funciona também com o script vindo por pipe).
perguntar() { # perguntar "texto" "padrão" → resposta (ou o padrão)
	local texto=$1 padrao=${2:-} resposta=''
	if [ "$SIM" = 1 ] || [ ! -r /dev/tty ]; then printf '%s' "$padrao"; return; fi
	read -r -p "$texto" resposta </dev/tty || true
	printf '%s' "${resposta:-$padrao}"
}
confirmar() { # confirmar "pergunta" → 0 se sim
	[ "$SIM" = 1 ] && return 0
	local r
	r=$(perguntar "$1 [s/N] " "n")
	[[ "$r" =~ ^[sSyY] ]]
}

lerEnv() { [ -f .env ] && grep -E "^$1=" .env | tail -n1 | cut -d= -f2- || true; }
definirEnv() { # definirEnv CHAVE valor (substitui ou acrescenta, sem mexer no resto do .env)
	local chave=$1 valor=$2
	touch .env
	chmod 600 .env
	if grep -qE "^$chave=" .env; then
		local esc
		esc=$(printf '%s' "$valor" | sed -e 's/[\\|&]/\\&/g')
		sed -i "s|^$chave=.*|$chave=$esc|" .env
	else
		printf '%s=%s\n' "$chave" "$valor" >>.env
	fi
}
mascarar() { local v=$1; [ -z "$v" ] && { printf '(vazia)'; return; }; printf '%s…%s' "${v:0:4}" "${v: -3}"; }

[ "$(id -u)" = 0 ] || falhar "Rode como root: sudo bash bin/instalar-vps.sh"
[ -f docker-compose.yml ] && [ -f docker-compose.vps.yml ] || falhar "Rode dentro da pasta do projeto (a que tem o docker-compose.yml)."

# ------------------------------------------------------------------ 1. Docker
passo "1/6 Docker"
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
	verde "✓ Docker já instalado ($(docker --version | cut -d, -f1))"
else
	echo "Instalando o Docker (leva 1 a 3 minutos)…"
	command -v curl >/dev/null 2>&1 || { apt-get update -qq && apt-get install -y -qq curl; }
	curl -fsSL https://get.docker.com | sh
	systemctl enable --now docker >/dev/null 2>&1 || true
	verde "✓ Docker instalado"
fi

# ------------------------------------------------------------------ 2. código
passo "2/6 Código"
if [ -d .git ] && command -v git >/dev/null 2>&1; then
	if git pull --ff-only 2>/dev/null; then
		verde "✓ Código na versão mais nova ($(git log -1 --format='%h · %cd' --date=format:'%d/%m %H:%M'))"
	else
		amarelo "! Não deu para baixar a versão nova (sem internet, sem acesso ao GitHub ou arquivos mudados à mão). Seguindo com a versão que está aqui."
	fi
else
	amarelo "! A pasta não veio do git: para atualizar no futuro, envie o código novo para esta pasta e rode o script de novo."
fi

# ------------------------------------------------------------------ 3. endereço e chaves
passo "3/6 Endereço e chaves"
IP=$(curl -fsS4 --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}')
[ -n "$IP" ] || falhar "Não descobri o IP desta VPS."
echo "IP desta VPS: $IP"

atual=$(lerEnv RANKLY_DOMINIO)
padraoDominio=${DOMINIO:-${atual:-}}
echo
echo "Endereço do sistema, sem https:// (ex.: teste.seudominio.com.br). Antes, crie no DNS do domínio"
echo "um registro A com esse nome apontando para $IP. Deixe vazio para usar um endereço provisório."
DOMINIO=$(perguntar "Endereço [${padraoDominio:-provisório}]: " "$padraoDominio")
DOMINIO=$(printf '%s' "$DOMINIO" | tr '[:upper:]' '[:lower:]' | sed -E 's#^https?://##; s#/.*$##; s/[[:space:]]//g')
if [ -z "$DOMINIO" ]; then
	DOMINIO="${IP//./-}.sslip.io"
	echo "Usando o endereço provisório $DOMINIO (funciona sem configurar DNS)."
fi
[[ "$DOMINIO" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || falhar "Endereço inválido: $DOMINIO"

apontado=$(getent ahostsv4 "$DOMINIO" 2>/dev/null | awk '{print $1; exit}' || true)
if [ "$apontado" != "$IP" ]; then
	if [ -z "$apontado" ]; then
		amarelo "! $DOMINIO ainda não existe no DNS (deveria apontar para esta VPS: $IP)."
	else
		amarelo "! $DOMINIO aponta para $apontado, e não para esta VPS ($IP)."
	fi
	amarelo "  O HTTPS só funciona depois que o registro A estiver valendo (de minutos a algumas horas)."
	confirmar "Continuar assim mesmo?" || falhar "Ajuste o DNS e rode de novo."
fi

chaveIa=${GEMINI_API_KEY:-$(lerEnv GEMINI_API_KEY)}
chaveFotos=${PIXABAY_API_KEY:-$(lerEnv PIXABAY_API_KEY)}
echo
echo "Chaves (Enter mantém a atual; como pegar: docs/COMO-TESTAR.md, parte 3)."
chaveIa=$(perguntar "Chave da IA Gemini [$(mascarar "$chaveIa")]: " "$chaveIa")
chaveFotos=$(perguntar "Chave do Pixabay [$(mascarar "$chaveFotos")]: " "$chaveFotos")
chaveIa=$(printf '%s' "$chaveIa" | tr -d '[:space:]"'\''')
chaveFotos=$(printf '%s' "$chaveFotos" | tr -d '[:space:]"'\''')

# ------------------------------------------------------------------ 4. .env
passo "4/6 Configuração (.env)"
definirEnv COMPOSE_FILE "docker-compose.yml:docker-compose.vps.yml"
definirEnv RANKLY_DOMINIO "$DOMINIO"
definirEnv RANKLY_URL "https://$DOMINIO"
definirEnv RANKLY_PORTA "127.0.0.1:8080"
definirEnv GEMINI_API_KEY "$chaveIa"
definirEnv PIXABAY_API_KEY "$chaveFotos"
verde "✓ .env gravado (só nesta VPS, permissão 600)"

# ------------------------------------------------------------------ 5. ligar
passo "5/6 Ligando o sistema"
# As portas 80 e 443 precisam estar livres (ou já com o nosso Caddy).
ocupadas=$(ss -ltnpH '( sport = :80 or sport = :443 )' 2>/dev/null | grep -v docker-proxy || true)
if [ -n "$ocupadas" ]; then
	vermelho "✗ Outro programa já usa a porta 80 ou 443 nesta VPS:"
	echo "$ocupadas" | sed 's/^/    /' >&2
	vermelho "  Em geral é um painel (CloudPanel, aaPanel…) ou um Nginx/Apache instalado antes."
	vermelho "  Opções: desligar esse programa, usar uma VPS limpa (Ubuntu 24.04 com Docker) ou falar"
	vermelho "  com quem cuida do servidor para colocar o sistema atrás do proxy que já existe."
	exit 1
fi
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
	ufw allow 80/tcp >/dev/null && ufw allow 443/tcp >/dev/null && ufw allow 443/udp >/dev/null
	verde "✓ Firewall (ufw): portas 80 e 443 liberadas"
fi
docker compose up -d --build --remove-orphans
verde "✓ Containers no ar"

# ------------------------------------------------------------------ 6. conferir
passo "6/6 Conferindo"
printf 'Esperando o sistema responder'
for _ in $(seq 1 90); do
	curl -fsS -o /dev/null --max-time 3 http://127.0.0.1:8080/editor/ 2>/dev/null && break
	printf '.'; sleep 2
done
echo
curl -fsS -o /dev/null --max-time 3 http://127.0.0.1:8080/editor/ 2>/dev/null \
	|| falhar "O sistema não respondeu. Veja o que aconteceu com: docker compose logs --tail=80 rankly"
verde "✓ Sistema respondendo"

printf 'Esperando o certificado HTTPS de %s' "$DOMINIO"
https=0
for _ in $(seq 1 45); do
	if curl -fsS -o /dev/null --max-time 5 "https://$DOMINIO/editor/" 2>/dev/null; then https=1; break; fi
	printf '.'; sleep 2
done
echo
if [ "$https" = 1 ]; then
	verde "✓ HTTPS funcionando"
else
	amarelo "! O HTTPS ainda não respondeu. Causas comuns: o DNS ainda não aponta para $IP, ou as portas"
	amarelo "  80/443 estão bloqueadas no firewall do painel da VPS. Veja: docker compose logs --tail=40 caddy"
fi

acesso=$(docker compose exec -T rankly cat /dados/acesso.txt 2>/dev/null || true)
cat <<FIM

────────────────────────────────────────────────────────────
  Construtor Rankly no ar
  Editor:  https://$DOMINIO/editor/
  Sites:   https://$DOMINIO/s/nome-do-site/
${acesso:+$(printf '%s\n' "$acesso" | sed 's/^/  /')}
  IA:      $([ -n "$chaveIa" ] && echo "Gemini ligado" || echo "simulada (sem GEMINI_API_KEY)")
  Fotos:   $([ -n "$chaveFotos" ] && echo "Pixabay ligado" || echo "banco de imagens desligado (sem PIXABAY_API_KEY)")

  Atualizar ou trocar chaves:  bash bin/instalar-vps.sh
  Ver o que está acontecendo:  docker compose logs -f
  Mais usuários de teste:      docker compose exec rankly php app/cli/criar-usuario.php \\
                                 --nome="Fulano" --email=fulano@exemplo.com --papel=admin --senha=umasenhaforte
────────────────────────────────────────────────────────────
FIM
