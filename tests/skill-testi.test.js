const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const skill = (n) => fs.readFileSync(path.join(__dirname, `../plugins/prestashop-assistant/skills/${n}/SKILL.md`), 'utf8');

test('regole-uso: parameters.php mai stampato per intero', () => {
  const t = skill('regole-uso');
  assert.match(t, /mai `cat`, `grep`/);
  assert.match(t, /stampa solo/);
});

test('setup: le vecchie istruzioni del prompt vanno in backup', () => {
  const t = skill('setup');
  for (const f of ['CLAUDE.md', 'AGENTS.md', 'prompt-merchant.md']) assert.ok(t.includes(f), f);
});

test('setup: chiave con percorsi Windows e verifica dopo la creazione', () => {
  const t = skill('setup');
  assert.ok(t.includes('ssh-keygen -y -P \'""\' -f "$HOME\\.ssh\\prestashop_<slug>"'), 'controllo Windows con $HOME');
  assert.match(t, /solo se l'errore parla di passphrase/);
  assert.match(t, /subito dopo averla creata/);
});

test('setup: phpinfo ridotto, senza variabili d\'ambiente', () => {
  const t = skill('setup');
  assert.ok(t.includes('phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES)'));
  assert.ok(!t.includes('<?php phpinfo();'));
});
