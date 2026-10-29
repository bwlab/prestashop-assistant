const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const { buildContext, MAX_CHARS } = require('../plugins/prestashop-assistant/scripts/carica-contesto.js');

function setup() {
  const base = fs.mkdtempSync(path.join(os.tmpdir(), 'pa-'));
  const homeDir = path.join(base, 'home');
  const pluginRoot = path.join(base, 'plugin');
  fs.mkdirSync(homeDir, { recursive: true });
  fs.mkdirSync(path.join(pluginRoot, 'skills', 'regole-uso'), { recursive: true });
  fs.writeFileSync(
    path.join(pluginRoot, 'skills', 'regole-uso', 'SKILL.md'),
    '---\nname: regole-uso\ndescription: test\n---\n\nREGOLE-TEST\n'
  );
  return { base, homeDir, pluginRoot };
}

function workdir(base, rel, files) {
  const dir = path.join(base, rel);
  fs.mkdirSync(dir, { recursive: true });
  for (const [name, content] of Object.entries(files)) fs.writeFileSync(path.join(dir, name), content);
  return dir;
}

test('senza puntatore propone il setup e carica le regole', () => {
  const { homeDir, pluginRoot } = setup();
  const r = buildContext({ homeDir, pluginRoot });
  assert.match(r, /REGOLE-TEST/);
  assert.match(r, /non è ancora configurato/);
  assert.match(r, /\/prestashop-assistant:setup/);
});

test('puntatore valido con CRLF carica cartella, profilo e ambienti', () => {
  const { base, homeDir, pluginRoot } = setup();
  const dir = workdir(base, 'Shop', { 'assistente.md': 'LIVELLO-TEST', 'dati-accesso.md': 'AMBIENTI-TEST' });
  fs.writeFileSync(path.join(homeDir, '.prestashop-assistant'), dir + '\r\n');
  const r = buildContext({ homeDir, pluginRoot });
  for (const s of [dir, 'LIVELLO-TEST', 'AMBIENTI-TEST', 'REGOLE-TEST']) assert.ok(r.includes(s), s);
});

test('cartella non trovata: nessuna eccezione e invito a rilanciare il setup', () => {
  const { base, homeDir, pluginRoot } = setup();
  const missing = path.join(base, 'spostata');
  fs.writeFileSync(path.join(homeDir, '.prestashop-assistant'), missing);
  const r = buildContext({ homeDir, pluginRoot });
  assert.ok(r.includes(missing));
  assert.match(r, /REGOLE-TEST/);
  assert.match(r, /rilancia il setup/);
});

test('percorso con spazi stile OneDrive', () => {
  const { base, homeDir, pluginRoot } = setup();
  const dir = workdir(base, 'Mario Rossi/OneDrive/Documenti/Shop', { 'assistente.md': 'LIVELLO-TEST' });
  fs.writeFileSync(path.join(homeDir, '.prestashop-assistant'), dir);
  const r = buildContext({ homeDir, pluginRoot });
  assert.ok(r.includes(dir));
  assert.match(r, /LIVELLO-TEST/);
});

test('contesto troppo lungo: troncato sotto il limite, regole intere', () => {
  const { base, homeDir, pluginRoot } = setup();
  const dir = workdir(base, 'Shop', { 'assistente.md': 'LIVELLO-TEST', 'dati-accesso.md': 'x'.repeat(20000) });
  fs.writeFileSync(path.join(homeDir, '.prestashop-assistant'), dir);
  const r = buildContext({ homeDir, pluginRoot });
  assert.ok(r.length <= MAX_CHARS, `lunghezza ${r.length}`);
  assert.match(r, /REGOLE-TEST/);
  assert.match(r, /contenuto troncato, leggi il file/);
});

test('il frontmatter della skill non viene iniettato', () => {
  const { homeDir, pluginRoot } = setup();
  assert.doesNotMatch(buildContext({ homeDir, pluginRoot }), /name: regole-uso/);
});
