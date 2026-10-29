const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const SCRIPT = path.join(__dirname, '../plugins/prestashop-assistant/scripts/registra-cartella.js');
const { mergeSettings, RULES } = require(SCRIPT);

const W = '/home/mario/Shop';

test('settings vuoto: aggiunge cartella e regole', () => {
  const s = mergeSettings({}, W);
  assert.deepStrictEqual(s.permissions.additionalDirectories, [W]);
  assert.deepStrictEqual(s.permissions.allow, RULES.allow);
  assert.deepStrictEqual(s.permissions.ask, RULES.ask);
  assert.deepStrictEqual(s.permissions.deny, RULES.deny);
});

test('conserva le impostazioni esistenti', () => {
  const s = mergeSettings({ model: 'opus', hooks: { Stop: [] }, permissions: { allow: ['Bash(git *)'] } }, W);
  assert.strictEqual(s.model, 'opus');
  assert.deepStrictEqual(s.hooks, { Stop: [] });
  assert.ok(s.permissions.allow.includes('Bash(git *)'));
  for (const r of RULES.allow) assert.ok(s.permissions.allow.includes(r));
});

test('idempotente: nessun duplicato', () => {
  const once = mergeSettings({ permissions: { allow: ['Bash(ssh *)'] } }, W);
  assert.deepStrictEqual(mergeSettings(once, W), once);
  assert.strictEqual(once.permissions.allow.filter((r) => r === 'Bash(ssh *)').length, 1);
});

test('percorso Windows con spazi restato identico', () => {
  const win = 'C:\\Users\\Mario Rossi\\Documenti\\Shop';
  assert.deepStrictEqual(mergeSettings({}, win).permissions.additionalDirectories, [win]);
});

test('input non modificato', () => {
  const input = { permissions: { allow: ['Bash(git *)'] } };
  const copy = structuredClone(input);
  mergeSettings(input, W);
  assert.deepStrictEqual(input, copy);
});

function run(home, dir) {
  return spawnSync(process.execPath, [SCRIPT, dir], { env: { ...process.env, HOME: home, USERPROFILE: home }, encoding: 'utf8' });
}

test('CLI: settings malformato → exit 1, nulla scritto', () => {
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'pa-home-'));
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pa-shop-'));
  fs.mkdirSync(path.join(home, '.claude'));
  const settings = path.join(home, '.claude', 'settings.json');
  fs.writeFileSync(settings, '{ rotto');
  const r = run(home, dir);
  assert.strictEqual(r.status, 1);
  assert.strictEqual(fs.readFileSync(settings, 'utf8'), '{ rotto');
  assert.ok(!fs.existsSync(path.join(home, '.prestashop-assistant')));
});

test('CLI: settings assente → creato con regole e puntatore scritto', () => {
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'pa-home-'));
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pa-shop-'));
  const r = run(home, dir);
  assert.strictEqual(r.status, 0, r.stderr);
  const s = JSON.parse(fs.readFileSync(path.join(home, '.claude', 'settings.json'), 'utf8'));
  assert.deepStrictEqual(s.permissions.additionalDirectories, [dir]);
  assert.strictEqual(fs.readFileSync(path.join(home, '.prestashop-assistant'), 'utf8'), dir);
});

test('CLI: settings esistente → backup prima di scrivere', () => {
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'pa-home-'));
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pa-shop-'));
  fs.mkdirSync(path.join(home, '.claude'));
  fs.writeFileSync(path.join(home, '.claude', 'settings.json'), '{"model":"opus"}');
  assert.strictEqual(run(home, dir).status, 0);
  assert.strictEqual(fs.readFileSync(path.join(home, '.claude', 'settings.json.bak-prestashop-assistant'), 'utf8'), '{"model":"opus"}');
});

test('CLI: cartella inesistente o relativa → exit 1', () => {
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'pa-home-'));
  assert.strictEqual(run(home, path.join(home, 'non-esiste')).status, 1);
  assert.strictEqual(run(home, 'relativa').status, 1);
  assert.ok(!fs.existsSync(path.join(home, '.prestashop-assistant')));
});
