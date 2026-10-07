// Testes ponta a ponta (Playwright + Chromium instalado).
// Uso: npx playwright test --config tests/e2e/playwright.config.mjs
//
// O global-setup sobe um ambiente isolado: banco rankly_teste no MariaDB local (ou SQLite com
// RANKLY_E2E_BANCO=sqlite, ou automaticamente se o MariaDB não responder), pasta temporária
// para sites/media/var e dois servidores php -S em portas livres (editor/API e sites publicados,
// abertos como http://{slug}.localhost:{porta}). RANKLY_E2E_MANTER=1 guarda a pasta no fim.
import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const aqui = path.dirname(fileURLToPath(import.meta.url));

// Nada de proxy para os servidores locais (o ambiente pode ter HTTPS_PROXY definido).
const semProxy = ['localhost', '127.0.0.1', '.localhost'];
process.env.NO_PROXY = [...new Set([...(process.env.NO_PROXY || '').split(','), ...semProxy].filter(Boolean))].join(',');
process.env.no_proxy = process.env.NO_PROXY;

export default defineConfig({
  testDir: aqui,
  testMatch: /.*\.spec\.mjs$/,
  outputDir: path.join(aqui, '../../var/e2e/resultados'),
  globalSetup: path.join(aqui, 'apoio/global-setup.mjs'),
  globalTeardown: path.join(aqui, 'apoio/global-teardown.mjs'),
  timeout: 120_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: Number(process.env.RANKLY_E2E_WORKERS) || 2,
  retries: 0,
  forbidOnly: !!process.env.CI,
  reporter: [['list'], ['html', { outputFolder: path.join(aqui, '../../var/e2e/relatorio'), open: 'never' }]],
  use: {
    ...devices['Desktop Chrome'],
    viewport: { width: 1280, height: 900 },
    locale: 'pt-BR',
    timezoneId: 'America/Sao_Paulo',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    launchOptions: {
      // *.localhost → 127.0.0.1 (o Chromium já faz isso, mas sem depender do DNS do sistema).
      args: ['--host-resolver-rules=MAP *.localhost 127.0.0.1'],
    },
  },
});
