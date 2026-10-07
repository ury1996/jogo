// Encerra os servidores php -S do ambiente ponta a ponta e apaga a pasta temporária
// (defina RANKLY_E2E_MANTER=1 para guardá-la e investigar: logs, sites gerados, banco SQLite).
import { rmSync } from 'node:fs';
import { estado } from './ambiente.mjs';

export default async function globalTeardown() {
  let e;
  try {
    e = estado();
  } catch {
    return;
  }
  for (const pid of e.pids || []) {
    try {
      process.kill(-pid, 'SIGTERM'); // grupo inteiro (o php -S com workers cria filhos)
    } catch {
      try { process.kill(pid, 'SIGTERM'); } catch { /* já encerrado */ }
    }
  }
  if (process.env.RANKLY_E2E_MANTER === '1') {
    console.log(`[e2e] pasta mantida: ${e.dir}`);
  } else if (e.dir && e.dir.includes('rankly-e2e-')) {
    rmSync(e.dir, { recursive: true, force: true });
  }
}
