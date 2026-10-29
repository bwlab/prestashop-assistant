const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const ROOT = path.join(__dirname, '../plugins/prestashop-assistant');
const SCRIPT = path.join(ROOT, 'scripts/registra-cartella.js');

test('hook in forma di comando unico (Codex non legge args)', () => {
  const h = JSON.parse(fs.readFileSync(path.join(ROOT, 'hooks/hooks.json'), 'utf8')).hooks.SessionStart[0].hooks[0];
  assert.strictEqual(h.args, undefined);
  assert.strictEqual(h.command, 'node "${CLAUDE_PLUGIN_ROOT}/scripts/carica-contesto.js"');
});

test('regole Codex: ssh consentito, ssh -o / -F con richiesta', () => {
  const r = fs.readFileSync(path.join(ROOT, 'codex/prestashop-assistant.rules'), 'utf8');
  assert.match(r, /pattern = \["ssh"\],\s*decision = "allow"/);
  assert.match(r, /pattern = \["ssh", \["-o", "-F"\]\],\s*decision = "prompt"/);
});

function run(home, args) {
  return spawnSync(process.execPath, [SCRIPT, ...args], {
    env: { ...process.env, HOME: home, USERPROFILE: home, CODEX_HOME: path.join(home, '.codex') },
    encoding: 'utf8',
  });
}

test('CLI --codex: puntatore e regole Codex, impostazioni Claude non toccate', () => {
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'pa-home-'));
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pa-shop-'));
  const r = run(home, ['--codex', dir]);
  assert.strictEqual(r.status, 0, r.stderr);
  assert.strictEqual(fs.readFileSync(path.join(home, '.prestashop-assistant'), 'utf8'), dir);
  assert.ok(fs.existsSync(path.join(home, '.codex/rules/prestashop-assistant.rules')));
  assert.ok(!fs.existsSync(path.join(home, '.claude/settings.json')));
});

test('CLI --codex: cartella non valida → exit 1, nulla scritto', () => {
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'pa-home-'));
  assert.strictEqual(run(home, ['--codex', 'relativa']).status, 1);
  assert.ok(!fs.existsSync(path.join(home, '.prestashop-assistant')));
  assert.ok(!fs.existsSync(path.join(home, '.codex')));
});
