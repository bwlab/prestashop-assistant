#!/usr/bin/env node
// Hook SessionStart: inietta regole d'uso e dati dello shop, qualunque cartella sia aperta.
// Non lancia mai eccezioni ed esce sempre con 0: un problema qui non deve bloccare la sessione.
'use strict';

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const MAX_CHARS = 9500; // Claude Code sposta in un file ciò che supera 10.000 caratteri
const POINTER = '.prestashop-assistant';

function read(file) {
  try {
    return fs.readFileSync(file, 'utf8');
  } catch {
    return null;
  }
}

function stripFrontmatter(text) {
  return text.replace(/^---\r?\n[\s\S]*?\r?\n---\r?\n/, '').trim();
}

function rules(pluginRoot) {
  const text = read(path.join(pluginRoot, 'skills', 'regole-uso', 'SKILL.md'));
  return text ? stripFrontmatter(text) : 'Regole d\'uso non trovate nel plugin: prima di qualsiasi modifica chiedi conferma al merchant.';
}

function section(title, body) {
  return `## ${title}\n\n${body.trim()}`;
}

// Tronca le sezioni dati, dall'ultima alla prima, finché il totale sta nel limite.
function fit(fixed, parts) {
  const join = (ps) => [...fixed, ...ps.map((p) => section(p.title, p.body))].join('\n\n');
  const out = parts.map((p) => ({ ...p }));
  for (let i = out.length - 1; i >= 0 && join(out).length > MAX_CHARS; i--) {
    const note = `\n\n[contenuto troncato, leggi il file ${out[i].file}]`;
    const room = MAX_CHARS - join(out.map((p, j) => (j === i ? { ...p, body: '' } : p))).length - note.length;
    out[i].body = out[i].body.slice(0, Math.max(0, room)) + note;
  }
  return join(out).slice(0, MAX_CHARS);
}

function buildContext({ homeDir, pluginRoot }) {
  const fixed = [section('Regole d\'uso del plugin prestashop-assistant', rules(pluginRoot))];
  const pointer = read(path.join(homeDir, POINTER));
  const dir = pointer && pointer.trim();

  if (!dir) {
    fixed.push(section('Configurazione', 'Il plugin prestashop-assistant non è ancora configurato su questo computer. Proponi al merchant di lanciare la skill setup del plugin (in Claude: /prestashop-assistant:setup).'));
    return fit(fixed, []);
  }
  if (!fs.existsSync(dir)) {
    fixed.push(section('Configurazione', `La cartella di lavoro registrata (${dir}) non esiste più. Chiedi al merchant se l'ha spostata e proponi di rilanciare la skill setup del plugin (in Claude: /prestashop-assistant:setup): rilancia il setup per registrare quella nuova.`));
    return fit(fixed, []);
  }

  fixed.push(section('Configurazione', `La cartella di lavoro del merchant è ${dir}. Lì ci sono assistente.md, dati-accesso.md, ricognizione/, log/, backup/, report/ ed export/.`));
  const parts = ['assistente.md', 'dati-accesso.md']
    .map((name) => ({ title: name, file: path.join(dir, name), body: read(path.join(dir, name)) }))
    .filter((p) => p.body !== null);
  return fit(fixed, parts);
}

module.exports = { buildContext, MAX_CHARS };

if (require.main === module) {
  let context;
  try {
    context = buildContext({
      homeDir: os.homedir(),
      pluginRoot: process.env.CLAUDE_PLUGIN_ROOT || path.join(__dirname, '..'),
    });
  } catch (e) {
    context = `prestashop-assistant: errore nel caricamento del contesto (${e.message}).`;
  }
  process.stdout.write(JSON.stringify({ hookSpecificOutput: { hookEventName: 'SessionStart', additionalContext: context } }));
}
