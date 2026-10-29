#!/usr/bin/env bash
# Crea dist/prestashop-assistant.zip (riserva al marketplace) da caricare in Customize › Plugins › Upload plugin.
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
src=$root/plugins/prestashop-assistant
out=$root/dist/prestashop-assistant.zip

err() { echo "Errore: $*" >&2; exit 1; }

jq -e . "$src/.claude-plugin/plugin.json" > /dev/null
[[ ! -e $src/bin ]] || err "la cartella bin/ fa rifiutare il plugin da Claude Desktop"
[[ ! -e $src/.mcp.json ]] || err ".mcp.json non previsto in questo plugin"
! grep -rq 'user_config' "$src" || err "user_config non funziona in Claude Desktop"

mkdir -p "$root/dist"
rm -f "$out"
(cd "$src" && zip -qr "$out" .)
unzip -l "$out" | grep -q ' \.claude-plugin/plugin\.json$' || err "plugin.json non alla radice dello zip"

echo "$out"
