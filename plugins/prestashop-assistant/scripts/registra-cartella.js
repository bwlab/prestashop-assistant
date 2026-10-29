#!/usr/bin/env node
// Registra la cartella di lavoro del merchant: puntatore ~/.prestashop-assistant
// e regole in ~/.claude/settings.json (cartella consentita, ssh libero, chiave privata vietata).
// Uso: node registra-cartella.js <percorso-assoluto-cartella>
'use strict';

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const RULES = {
  allow: ['Bash(ssh *)', 'PowerShell(ssh *)'],
  ask: ['Bash(ssh * sudo *)', 'PowerShell(ssh * sudo *)'],
  deny: ['Read(~/.ssh/prestashop_*)', 'Edit(~/.ssh/prestashop_*)'],
};

const union = (a = [], b = []) => [...a, ...b.filter((x) => !a.includes(x))];

function mergeSettings(settings, workdir) {
  const p = settings.permissions || {};
  return {
    ...settings,
    permissions: {
      ...p,
      allow: union(p.allow, RULES.allow),
      ask: union(p.ask, RULES.ask),
      deny: union(p.deny, RULES.deny),
      additionalDirectories: union(p.additionalDirectories, [workdir]),
    },
  };
}

function writeAtomic(file, text) {
  const tmp = `${file}.tmp-${process.pid}`;
  fs.writeFileSync(tmp, text);
  fs.renameSync(tmp, file);
}

function main(dir) {
  const home = os.homedir();
  if (!dir || !path.isAbsolute(dir) || !fs.existsSync(dir) || !fs.statSync(dir).isDirectory()) {
    throw new Error(`la cartella "${dir || ''}" non esiste o non è un percorso completo`);
  }
  const claudeDir = path.join(home, '.claude');
  const file = path.join(claudeDir, 'settings.json');
  const old = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : null;
  let settings = {};
  if (old !== null && old.trim()) {
    try {
      settings = JSON.parse(old);
    } catch {
      throw new Error(`${file} non è un JSON valido: non lo modifico. Va sistemato a mano o da chi ti supporta`);
    }
  }
  fs.mkdirSync(claudeDir, { recursive: true });
  if (old !== null) fs.writeFileSync(`${file}.bak-prestashop-assistant`, old);
  writeAtomic(file, JSON.stringify(mergeSettings(settings, dir), null, 2) + '\n');
  writeAtomic(path.join(home, '.prestashop-assistant'), dir);
  console.log(`Fatto: cartella di lavoro registrata (${dir}) e impostazioni di Claude aggiornate.`);
}

module.exports = { mergeSettings, RULES };

if (require.main === module) {
  try {
    main(process.argv[2]);
  } catch (e) {
    console.error(`Errore: ${e.message}`);
    process.exit(1);
  }
}
