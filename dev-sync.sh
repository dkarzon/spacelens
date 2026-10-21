#!/bin/bash
# Copies the plugin's web files straight onto a server for fast iteration (no package build).
# Usage: ./dev-sync.sh root@tower
# The emhttp folder is in RAM: a reboot or plugin update restores the packaged files.
set -euo pipefail
cd "$(dirname "$0")"

HOST="${1:?Usage: ./dev-sync.sh root@tower}"

tar -C source/usr/local/emhttp/plugins -cf - spacelens \
  | ssh "$HOST" 'tar -C /usr/local/emhttp/plugins -xf - && find /usr/local/emhttp/plugins/spacelens -type f \( -name "*.php" -o -name "*.page" \) -exec sed -i "s/\r$//" {} +'

echo "Synced to $HOST. Refresh the Spacelens page."
