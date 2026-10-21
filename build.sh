#!/bin/bash
# Builds the Spacelens package into dist/ and stamps its version and SHA256 into spacelens.plg.
# Usage: ./build.sh [version]     version defaults to today's date (yyyy.MM.dd)
# Runs in Git Bash, WSL or on Linux (needs GNU tar, xz and sha256sum).
set -euo pipefail
cd "$(dirname "$0")"

NAME=spacelens
VERSION="${1:-$(date +%Y.%m.%d)}"
PKG="$NAME-$VERSION-x86_64-1.txz"

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

cp -r source/. "$STAGE/"

# Shell and PHP files must have Unix line endings on the server.
find "$STAGE" -type f \( -name '*.sh' -o -name '*.php' -o -name '*.page' \) -exec sed -i 's/\r$//' {} +

mkdir -p "$STAGE/install"
cat > "$STAGE/install/slack-desc" <<EOF
$NAME: Spacelens
$NAME:
$NAME: Disk usage explorer for Unraid. Shows which folders use the most
$NAME: space on a disk, pool or share, with drill-down.
EOF

mkdir -p dist
rm -f "dist/$PKG"
tar -C "$STAGE" --owner=0 --group=0 --mode='u+rwX,go+rX,go-w' -cJf "dist/$PKG" .

SHA=$(sha256sum "dist/$PKG" | cut -d' ' -f1)
for PLG in "$NAME.plg" "$NAME-local.plg"; do
  sed -i -E \
    -e "s|(<!ENTITY version +\")[^\"]*|\1$VERSION|" \
    -e "s|(<!ENTITY packageSHA +\")[^\"]*|\1$SHA|" \
    "$PLG"
  cp "$PLG" dist/
done

echo "Built dist/$PKG"
echo "SHA256 $SHA"
