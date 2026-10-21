#!/bin/bash
# Scans a target with du and find, and writes the Spacelens index for it.
# Usage: scan.sh <target path> <scan dir> <name=root>...    (started in the background by api.php)
#
# Each name=root is one place the target's data lives: a disk or pool and the folder on it.
# A disk or pool target has one (cache=/mnt/cache); a user share has one per disk it is on
# (cache=/mnt/cache/media disk1=/mnt/disk1/media ...). They are scanned in parallel and merged.
#
# <scan dir>/index.tsv has one line per folder:
#   depth<TAB>path relative to target<TAB>KiB used<TAB>KiB per disk, e.g. cache:120,disk1:40
# Lines are sorted bytewise, so the direct children of any folder are one contiguous block
# that lib.php finds with a binary search.
# <scan dir>/history/<finished>.tsv and .meta keep the previous HISTORY_KEEP scans (default 4).
# <scan dir>/largest2.tsv0 holds the largest files, NUL-separated, biggest first:
#   512-byte blocks<TAB>size in bytes<TAB>mtime<TAB>disk<TAB>path relative to target.
set -u
TARGET=$1
OUT=$2
shift 2

exec 9>"$OUT/lock"
flock -n 9 || exit 1

echo $$ > "$OUT/pid"
date +%s > "$OUT/started"
rm -f "$OUT/requested" "$OUT/failed"

LARGEST_FILES=1000

cleanup() {
  rm -f "$OUT/pid" "$OUT/phase" "$OUT/index.tmp" "$OUT/largest.tmp" "$OUT/counts" "$OUT/meta.tmp" \
    "$OUT"/raw.*.du0 "$OUT"/err.* "$OUT"/status.* "$OUT"/counts.* "$OUT"/files.*
}
trap cleanup EXIT
trap 'exit 143' TERM INT

LOW_PRIORITY="nice -n 19"
command -v ionice > /dev/null && LOW_PRIORITY="$LOW_PRIORITY ionice -c 3"

NAMES=()
ROOTS=()
for spec in "$@"; do
  NAMES+=("${spec%%=*}")
  ROOTS+=("${spec#*=}")
done

# Pass 1: du on every disk at once. -x: stay on this filesystem. -k: allocated KiB.
# -0: NUL-separated, so odd names can't break parsing.
echo folders > "$OUT/phase"
for i in "${!NAMES[@]}"; do
  (
    $LOW_PRIORITY du -x -k -0 "${ROOTS[$i]}" > "$OUT/raw.${NAMES[$i]}.du0" 2> "$OUT/err.${NAMES[$i]}"
    echo $? > "$OUT/status.${NAMES[$i]}"
  ) &
done
wait

cat "$OUT"/err.* > "$OUT/scan.err" 2> /dev/null
RAW_BYTES=$(cat "$OUT"/raw.*.du0 | wc -c)

# du exits 1 when some folders couldn't be read; that disk's results are still usable.
USABLE=0
for i in "${!NAMES[@]}"; do
  [ "$(cat "$OUT/status.${NAMES[$i]}" 2> /dev/null || echo 99)" -le 1 ] && USABLE=1
done

# Turn each disk's du output into index lines tagged with the disk, sort them all together so
# the same folder from different disks sits on adjacent lines, then add those up.
for i in "${!NAMES[@]}"; do
  LC_ALL=C awk -v root="${ROOTS[$i]}" -v disk="${NAMES[$i]}" -v counts="$OUT/counts.${NAMES[$i]}" '
    BEGIN { RS = "\0"; n = length(root); skipped = 0 }
    {
      tab = index($0, "\t")
      kb = substr($0, 1, tab - 1)
      p = substr($0, tab + 1)
      if (p == root) rel = ""
      else if (substr(p, 1, n + 1) == root "/") rel = substr(p, n + 2)
      else { skipped++; next }
      if (rel ~ /[\t\n]/) { skipped++; next }
      if (rel == "") depth = 0
      else { tmp = rel; depth = gsub("/", "", tmp) + 1 }
      printf "%04d\t%s\t%s\t%s\n", depth, rel, kb, disk
    }
    END { printf "%d\n", skipped > counts }
  ' "$OUT/raw.${NAMES[$i]}.du0"
done | LC_ALL=C sort -T "$OUT" | LC_ALL=C awk -F '\t' -v counts="$OUT/counts" '
  function flush() {
    if (!have) return
    printf "%s\t%s\t%d\t%s\n", depth, rel, kb, where
    dirs++
    if (rel == "") total = kb
  }
  {
    key = $1 "\t" $2
    if (!have || key != prev) {
      flush()
      have = 1; prev = key; depth = $1; rel = $2; kb = 0; where = ""
    }
    kb += $3
    where = where (where == "" ? "" : ",") $4 ":" $3
  }
  END { flush(); printf "dirs=%d\ntotalKb=%d\n", dirs, total > counts }
' > "$OUT/index.tmp"

if [ "$USABLE" = 0 ] || [ ! -s "$OUT/index.tmp" ]; then
  echo "failed=1" > "$OUT/failed"
  exit 1
fi

# Pass 2: the largest files on each disk, at once. du has just read the same metadata, so most
# of it is cached. Unreadable folders were already reported by du, so find's errors are dropped.
echo files > "$OUT/phase"
for i in "${!NAMES[@]}"; do
  (
    $LOW_PRIORITY find "${ROOTS[$i]}" -xdev -type f -printf "%b\t%s\t%T@\t${NAMES[$i]}\t%P\0" 2> /dev/null \
      | LC_ALL=C sort -z -T "$OUT" -t $'\t' -k1,1nr \
      | head -z -n "$LARGEST_FILES" > "$OUT/files.${NAMES[$i]}"
  ) &
done
wait
cat "$OUT"/files.* | LC_ALL=C sort -z -T "$OUT" -t $'\t' -k1,1nr | head -z -n "$LARGEST_FILES" > "$OUT/largest.tmp"

# Keep the previous scan in history/<finished>.tsv for comparisons, and only the newest few.
if [ -s "$OUT/index.tsv" ] && [ -f "$OUT/meta" ]; then
  PREV=$(sed -n 's/^finished=//p' "$OUT/meta")
  if [ -n "$PREV" ]; then
    mkdir -p "$OUT/history"
    mv -f "$OUT/index.tsv" "$OUT/history/$PREV.tsv"
    cp -f "$OUT/meta" "$OUT/history/$PREV.meta"
  fi
fi
KEEP=${HISTORY_KEEP:-4}
for OLD in $(ls -1 "$OUT"/history/*.tsv 2> /dev/null | sort -r | tail -n +$((KEEP + 1))); do
  rm -f "$OLD" "${OLD%.tsv}.meta"
done

mv -f "$OUT/index.tmp" "$OUT/index.tsv"
mv -f "$OUT/largest.tmp" "$OUT/largest2.tsv0"
rm -f "$OUT/largest.tsv0"
SKIPPED=$(cat "$OUT"/counts.* 2> /dev/null | awk '{ s += $1 } END { print s + 0 }')
{
  echo "started=$(cat "$OUT/started")"
  echo "finished=$(date +%s)"
  cat "$OUT/counts"
  echo "skipped=$SKIPPED"
  echo "errors=$(wc -l < "$OUT/scan.err")"
  echo "rawBytes=$RAW_BYTES"
  echo "disks=\"$(IFS=,; echo "${NAMES[*]}")\""
} > "$OUT/meta.tmp" && mv -f "$OUT/meta.tmp" "$OUT/meta"
