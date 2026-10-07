// Prepara o ambiente ponta a ponta (rodado uma vez pelo Playwright, antes dos testes):
// - pasta temporária com sites/, media/, var/ e um config.php de teste;
// - banco MariaDB rankly_teste (migrado e esvaziado), ou SQLite se RANKLY_E2E_BANCO=sqlite
//   ou se o MariaDB não responder;
// - usuário admin, fotos JPEG e logo PNG de exemplo;
// - dois servidores php -S: editor/API (public_html) e sites publicados (sites/router-dev.php),
//   cada um numa porta livre.
// O estado (portas, usuário, pastas) vai para process.env, que os testes herdam.
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, mkdirSync, openSync } from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { RAIZ, salvarEstado } from './ambiente.mjs';

function portaLivre() {
  return new Promise((ok, falha) => {
    const s = net.createServer();
    s.unref();
    s.on('error', falha);
    s.listen(0, '127.0.0.1', () => {
      const { port } = s.address();
      s.close(() => ok(port));
    });
  });
}

function configPhp(dir, portaApi, portaSites, banco) {
  const db = banco === 'sqlite'
    ? { driver: 'sqlite', dsn: `sqlite:${dir}/banco.sqlite`, usuario: null, senha: null }
    : {
        driver: 'mysql',
        dsn: process.env.RANKLY_E2E_MYSQL_DSN || 'mysql:host=127.0.0.1;port=3306;dbname=rankly_teste;charset=utf8mb4',
        usuario: process.env.RANKLY_E2E_MYSQL_USUARIO || 'rankly',
        senha: process.env.RANKLY_E2E_MYSQL_SENHA || 'rankly',
      };
  const config = {
    ambiente: 'dev',
    url_editor: `http://127.0.0.1:${portaApi}`,
    dominio_sites: `localhost:${portaSites}`,
    protocolo_sites: 'http',
    db,
    dir_sites: `${dir}/sites`,
    dir_media: `${dir}/media`,
    dir_var: `${dir}/var`,
    segredo_app: 'segredo-app-e2e-' + Math.random().toString(36).slice(2),
    segredo_ip: 'segredo-ip-e2e-' + Math.random().toString(36).slice(2),
    email_modo: 'arquivo',
    smtp: { remetente: 'nao-responda@rankly.teste', nome_remetente: 'Sites Rankly' },
    retencao_leads_meses: 12,
    releases_mantidas: 5,
  };
  // JSON → PHP sem depender de var_export: o PHP decodifica o JSON embutido.
  const json = JSON.stringify(config).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  return `<?php\nreturn json_decode('${json}', true, 512, JSON_THROW_ON_ERROR);\n`;
}

function php(args, env) {
  return execFileSync('php', args, { cwd: RAIZ, env: { ...process.env, ...env }, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

async function esperarPorta(porta, log) {
  for (let i = 0; i < 100; i++) {
    const ok = await new Promise((r) => {
      const c = net.connect(porta, '127.0.0.1');
      c.on('connect', () => { c.end(); r(true); });
      c.on('error', () => r(false));
    });
    if (ok) return;
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error(`O servidor php -S na porta ${porta} não subiu (veja ${log}).`);
}

export default async function globalSetup() {
  const dir = mkdtempSync(path.join(os.tmpdir(), 'rankly-e2e-'));
  for (const sub of ['sites', 'media', 'var', 'fotos']) mkdirSync(path.join(dir, sub), { recursive: true });
  const portaApi = Number(process.env.RANKLY_E2E_PORTA_API) || await portaLivre();
  const portaSites = Number(process.env.RANKLY_E2E_PORTA_SITES) || await portaLivre();
  const arqConfig = path.join(dir, 'config.php');
  const env = { RANKLY_CONFIG: arqConfig };
  const email = 'equipe@rankly.teste';
  const senha = 'senha-e2e-forte-123';

  let banco = process.env.RANKLY_E2E_BANCO === 'sqlite' ? 'sqlite' : 'mysql';
  writeFileSync(arqConfig, configPhp(dir, portaApi, portaSites, banco));
  let infoBanco;
  try {
    infoBanco = JSON.parse(php(['tests/e2e/apoio/preparar.php', 'banco', email, senha], env));
  } catch (e) {
    if (banco === 'sqlite' || process.env.RANKLY_E2E_BANCO === 'mysql') throw e;
    console.warn(`[e2e] MariaDB indisponível (${String(e.stderr || e.message).trim()}); usando SQLite.`);
    banco = 'sqlite';
    writeFileSync(arqConfig, configPhp(dir, portaApi, portaSites, banco));
    infoBanco = JSON.parse(php(['tests/e2e/apoio/preparar.php', 'banco', email, senha], env));
  }
  const fotos = JSON.parse(php(['tests/e2e/apoio/preparar.php', 'fotos', path.join(dir, 'fotos')], env));

  const pids = [];
  const servidores = [
    ['api', portaApi, 'public_html', 'public_html/router-dev.php'],
    ['sites', portaSites, 'sites', 'sites/router-dev.php'],
  ];
  for (const [nome, porta, raizWeb, roteador] of servidores) {
    const log = path.join(dir, `servidor-${nome}.log`);
    const saida = openSync(log, 'a');
    const p = spawn('php', ['-S', `127.0.0.1:${porta}`, '-t', raizWeb, roteador], {
      cwd: RAIZ,
      env: { ...process.env, ...env, PHP_CLI_SERVER_WORKERS: '4' },
      stdio: ['ignore', saida, saida],
      detached: true,
    });
    p.unref();
    pids.push(p.pid);
    await esperarPorta(porta, log);
  }

  const estado = {
    dir, arqConfig, banco: infoBanco.driver, portaApi, portaSites, pids,
    api: `http://127.0.0.1:${portaApi}`,
    usuario: { email, senha },
    fotos: fotos.fotos, logo: fotos.logo,
  };
  process.env.RANKLY_E2E_ESTADO = salvarEstado(estado);
  console.log(`[e2e] banco ${estado.banco}; API ${estado.api}; sites http://{slug}.localhost:${portaSites}; pasta ${dir}`);
}
