<?php
// Shared helpers for the Spacelens API.

const SL_MNT = '/mnt';
const SL_STATE = '/var/local/emhttp';
const SL_PLUGIN_DIR = '/usr/local/emhttp/plugins/spacelens';
const SL_USER_CFG = '/boot/config/plugins/spacelens/spacelens.cfg';
const SL_MAX_ROWS = 500;          // folders or files returned per folder before the rest are grouped
const SL_MAX_FILE_STATS = 5000;   // live file listing gives up beyond this many entries
const SL_TREE_FOLDERS = 40;       // largest subfolders that also get their own children, for the treemap
const SL_TREE_CHILDREN = 15;      // children listed inside each of those
const SL_LARGEST_LIMIT = 100;     // largest files returned per request

class SpacelensError extends Exception
{
    public int $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function sl_json(array $data): void
{
    echo json_encode($data);
    exit;
}

function sl_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

// Every state-changing request must be a POST carrying the webGui's csrf_token.
function sl_require_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new SpacelensError(405, 'POST required');
    }
    // On Unraid, local_prepend.php (which defines csrf_terminate) has already checked the token
    // on every POST, exiting if it was wrong, and then removed it from $_POST.
    if (function_exists('csrf_terminate')) {
        return;
    }
    $var = @parse_ini_file(SL_STATE . '/var.ini');
    $token = $_POST['csrf_token'] ?? '';
    if (!$var || empty($var['csrf_token']) || !is_string($token) || !hash_equals($var['csrf_token'], $token)) {
        throw new SpacelensError(403, 'Security token validation failed');
    }
}

// Resolves a user-supplied path and refuses anything outside /mnt/<something>.
function sl_safe_path($raw): string
{
    if (!is_string($raw) || $raw === '') {
        throw new SpacelensError(400, 'Path required');
    }
    $real = realpath($raw);
    if ($real === false || !is_dir($real)) {
        throw new SpacelensError(404, 'Folder not found');
    }
    if (!preg_match('#^/mnt/[^/]+(/|$)#', $real)) {
        throw new SpacelensError(403, 'Path must be inside /mnt');
    }
    return $real;
}

// A mounted filesystem sits on a different device from its parent folder.
function sl_is_mountpoint(string $path): bool
{
    $self = @stat($path);
    $parent = @stat(dirname($path));
    return $self && $parent && $self['dev'] !== $parent['dev'];
}

function sl_describe_path(string $path): array
{
    $info = [
        'name' => basename($path),
        'path' => $path,
        'mountpoint' => sl_is_mountpoint($path),
    ];
    if ($info['mountpoint']) {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total !== false && $free !== false) {
            $info += ['total' => $total, 'free' => $free, 'used' => $total - $free];
        }
    }
    return $info;
}

// Array disks and pools from emhttp's disks.ini, kind 'disk', 'cache' (the pool named cache)
// or 'pool'. Parity, flash, unassigned slots and secondary pool members (which have no
// mount of their own) are skipped.
function sl_disk_targets(): array
{
    $disks = @parse_ini_file(SL_STATE . '/disks.ini', true) ?: [];
    $targets = [];
    foreach ($disks as $disk) {
        $name = $disk['name'] ?? '';
        $type = $disk['type'] ?? '';
        if (!in_array($type, ['Data', 'Cache'], true) || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            continue;
        }
        $path = SL_MNT . "/$name";
        if (!sl_is_mountpoint($path)) {
            continue;
        }
        $targets[] = sl_describe_path($path) + [
            'kind' => $type === 'Data' ? 'disk' : ($name === 'cache' ? 'cache' : 'pool'),
            'fsType' => $disk['fsType'] ?? '',
            // emhttp sets spundown=1 for a sleeping disk; its status colour also blinks.
            'spundown' => ($disk['spundown'] ?? '0') === '1' || str_contains($disk['color'] ?? '', 'blink'),
        ];
    }
    return $targets;
}

// Where a target's data physically lives: [['name' => 'disk1', 'root' => '/mnt/disk1/media', ...], ...].
// A user share path (/mnt/user/<share>/...) maps to the same folder on every disk and pool that
// has it. Any other target is a single disk or pool, or a folder on one.
function sl_branches(string $target): array
{
    $disks = sl_disk_targets();
    $describe = fn(array $disk, string $root) => [
        'name' => $disk['name'],
        'root' => $root,
        'kind' => $disk['kind'],
        'spundown' => $disk['spundown'],
    ];

    if ($target === SL_MNT . '/user' || str_starts_with($target, SL_MNT . '/user/')) {
        $rel = substr($target, strlen(SL_MNT . '/user'));
        $branches = [];
        foreach ($disks as $disk) {
            if (is_dir($disk['path'] . $rel)) {
                $branches[] = $describe($disk, $disk['path'] . $rel);
            }
        }
        return $branches;
    }

    $name = explode('/', $target)[2] ?? '';
    foreach ($disks as $disk) {
        if ($disk['name'] === $name) {
            return [$describe($disk, $target)];
        }
    }
    // Not a known disk or pool (e.g. an unassigned device): scan it as it is.
    return [['name' => preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?: 'disk', 'root' => $target, 'kind' => 'other', 'spundown' => false]];
}

// The disks a scan covered, as saved when it started: name => root.
function sl_scan_branches(string $dir): array
{
    $branches = [];
    foreach (@file("$dir/disks", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [$name, $root] = explode('=', $line, 2) + [1 => ''];
        $branches[$name] = $root;
    }
    return $branches;
}

// "cache:120,disk1:40" (KiB) -> ['cache' => bytes, 'disk1' => bytes]
function sl_parse_where(?string $where): array
{
    $bytes = [];
    foreach (explode(',', (string)$where) as $part) {
        if ($part !== '' && ($colon = strrpos($part, ':')) !== false) {
            $bytes[substr($part, 0, $colon)] = (int)substr($part, $colon + 1) * 1024;
        }
    }
    arsort($bytes);
    return $bytes;
}

// User shares from emhttp's shares.ini.
function sl_share_targets(): array
{
    $shares = @parse_ini_file(SL_STATE . '/shares.ini', true) ?: [];
    $targets = [];
    foreach ($shares as $share) {
        $name = $share['name'] ?? '';
        $path = SL_MNT . "/user/$name";
        if ($name === '' || str_contains($name, '/') || !is_dir($path)) {
            continue;
        }
        $targets[] = [
            'kind' => 'share',
            'name' => $name,
            'path' => $path,
            'comment' => $share['comment'] ?? '',
            'useCache' => $share['useCache'] ?? '',
            'cachePool' => $share['cachePool'] ?? '',
        ];
    }
    usort($targets, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $targets;
}

// ---------------------------------------------------------------------------
// Scans

function sl_config(): array
{
    $defaults = @parse_ini_file(SL_PLUGIN_DIR . '/default.cfg') ?: [];
    $user = @parse_ini_file(SL_USER_CFG) ?: [];
    return array_merge($defaults, $user);
}

// Each target gets its own folder under INDEX_DIR, named from a hash of its path.
function sl_scan_dir(string $target, bool $create = false): string
{
    $root = rtrim(sl_config()['INDEX_DIR'] ?? '/tmp/spacelens', '/');
    $dir = $root . '/' . substr(sha1($target), 0, 12);
    if ($create) {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new SpacelensError(500, "Cannot create index folder $dir");
        }
        file_put_contents("$dir/target", $target);
    }
    return $dir;
}

function sl_running_pid(string $dir): ?int
{
    $pid = (int)@file_get_contents("$dir/pid");
    if ($pid <= 1) {
        return null;
    }
    $cmdline = @file_get_contents("/proc/$pid/cmdline");
    return ($cmdline !== false && str_contains($cmdline, 'scan.sh')) ? $pid : null;
}

function sl_scan_status(string $target): array
{
    $dir = sl_scan_dir($target);
    clearstatcache();
    $meta = is_file("$dir/meta") ? (@parse_ini_file("$dir/meta") ?: null) : null;

    $state = 'idle';
    if (sl_running_pid($dir)) {
        $state = 'running';
    } elseif (($requested = @filemtime("$dir/requested")) && time() - $requested < 15) {
        $state = 'starting';
    }

    $status = [
        'state' => $state,
        'last' => $meta ? sl_scan_summary($dir, $meta) : null,
        'history' => sl_history($dir),
        'schedule' => sl_schedule_for($target),
    ];
    if ($state === 'running') {
        $status['progress'] = sl_scan_progress($dir, $meta);
    }
    if ($state === 'idle' && is_file("$dir/failed")) {
        $status['failed'] = sl_error_sample($dir) ?: ['The scan failed.'];
    }
    return $status;
}

function sl_scan_summary(string $dir, array $meta): array
{
    return [
        'started' => (int)($meta['started'] ?? 0),
        'finished' => (int)($meta['finished'] ?? 0),
        'dirs' => (int)($meta['dirs'] ?? 0),
        'skipped' => (int)($meta['skipped'] ?? 0),
        'errors' => (int)($meta['errors'] ?? 0),
        'totalBytes' => (int)($meta['totalKb'] ?? 0) * 1024,
        'disks' => array_values(array_filter(explode(',', $meta['disks'] ?? ''))),
        'errorSample' => sl_error_sample($dir),
    ];
}

function sl_error_sample(string $dir): array
{
    $lines = @file("$dir/scan.err", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_slice($lines, 0, 5);
}

// While du runs, progress is the size of its output so far (one file per disk), compared with
// the last scan's.
function sl_scan_progress(string $dir, ?array $meta): array
{
    $bytes = 0;
    $latest = null;
    foreach (glob("$dir/raw.*.du0") ?: [] as $raw) {
        $bytes += (int)@filesize($raw);
        if (!$latest || @filemtime($raw) >= @filemtime($latest)) {
            $latest = $raw;
        }
    }
    $started = (int)@file_get_contents("$dir/started");
    $phase = trim((string)@file_get_contents("$dir/phase")) ?: 'folders';
    $progress = [
        'phase' => $phase,
        'elapsed' => $started ? time() - $started : 0,
        'current' => $phase === 'folders' && $latest ? sl_last_scanned_folder($latest, (int)@filesize($latest)) : null,
    ];
    if ($phase === 'folders' && $meta && !empty($meta['rawBytes'])) {
        $progress['estimate'] = min(0.99, $bytes / (int)$meta['rawBytes']);
    }
    return $progress;
}

// du prints a folder once everything inside it is counted, so the last record is the last finished folder.
function sl_last_scanned_folder(string $raw, int $size): ?string
{
    if ($size === 0 || !($fh = @fopen($raw, 'rb'))) {
        return null;
    }
    fseek($fh, max(0, $size - 8192));
    $tail = fread($fh, 8192);
    fclose($fh);
    $records = explode("\0", rtrim($tail, "\0"));
    $record = count($records) > 1 ? end($records) : $records[0];
    $tab = strpos($record, "\t");
    return $tab === false ? null : substr($record, $tab + 1);
}

function sl_start_scan(string $target): void
{
    if (sl_scan_status($target)['state'] !== 'idle') {
        throw new SpacelensError(409, 'A scan is already running for this folder');
    }
    $branches = sl_branches($target);
    if (!$branches) {
        throw new SpacelensError(404, 'This share has no folders on any disk or pool');
    }
    $dir = sl_scan_dir($target, true);
    $specs = array_map(fn($b) => "{$b['name']}={$b['root']}", $branches);
    file_put_contents("$dir/disks", implode("\n", $specs) . "\n");
    touch("$dir/requested");
    exec(sprintf(
        'HISTORY_KEEP=%d setsid bash %s %s %s %s > /dev/null 2>&1 < /dev/null &',
        max(0, (int)(sl_config()['HISTORY_KEEP'] ?? 4)),
        escapeshellarg(SL_PLUGIN_DIR . '/scripts/scan.sh'),
        escapeshellarg($target),
        escapeshellarg($dir),
        implode(' ', array_map('escapeshellarg', $specs))
    ));
}

// scan.sh runs in its own session, so killing its process group also stops du and sort.
// Run through bash: not every /bin/sh kill accepts a negative (process group) pid.
function sl_cancel_scan(string $target): void
{
    $pid = sl_running_pid(sl_scan_dir($target));
    if (!$pid) {
        throw new SpacelensError(409, 'No scan is running');
    }
    exec('bash -c ' . escapeshellarg("kill -TERM -- -$pid"), $output, $status);
    if ($status !== 0) {
        throw new SpacelensError(500, 'Could not stop the scan');
    }
}

// ---------------------------------------------------------------------------
// Browsing an index

// A folder path relative to the scan target, e.g. "appdata/plex". '' is the target itself.
function sl_safe_rel($raw): string
{
    if (!is_string($raw)) {
        throw new SpacelensError(400, 'Invalid folder');
    }
    $rel = trim($raw, '/');
    if ($rel === '') {
        return '';
    }
    foreach (explode('/', $rel) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || strpbrk($segment, "\t\n\0") !== false) {
            throw new SpacelensError(400, 'Invalid folder');
        }
    }
    return $rel;
}

// Byte offset of the first line that starts at or after $pos.
function sl_line_start($fh, int $pos): int
{
    if ($pos === 0) {
        return 0;
    }
    fseek($fh, $pos - 1);
    fgets($fh);
    return ftell($fh);
}

// Binary search for the first line that sorts at or after $prefix.
function sl_lower_bound($fh, int $size, string $prefix): int
{
    $lo = 0;
    $hi = $size;
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi, 2);
        $start = sl_line_start($fh, $mid);
        $line = $start < $size ? (fseek($fh, $start) === 0 ? fgets($fh) : false) : false;
        if ($line === false || strcmp(rtrim($line, "\n"), $prefix) >= 0) {
            $hi = $mid;
        } else {
            $lo = $mid + 1;
        }
    }
    return sl_line_start($fh, $lo);
}

// Calls $fn(relPath, kib, where) for every index line that starts with $prefix.
// where is the per-disk breakdown, e.g. "cache:120,disk1:40" (absent in older indexes).
function sl_index_each(string $index, string $prefix, callable $fn): void
{
    $fh = fopen($index, 'rb');
    $size = filesize($index);
    fseek($fh, sl_lower_bound($fh, $size, $prefix));
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\n");
        if (!str_starts_with($line, $prefix)) {
            break;
        }
        [, $path, $kib, $where] = explode("\t", $line) + [3 => null];
        $fn($path, (int)$kib, $where);
    }
    fclose($fh);
}

function sl_depth(string $rel): int
{
    return $rel === '' ? 0 : substr_count($rel, '/') + 1;
}

// Direct subfolders of $rel from the index.
function sl_index_children(string $index, string $rel): array
{
    $base = $rel === '' ? '' : "$rel/";
    $folders = [];
    sl_index_each($index, sprintf('%04d', sl_depth($rel) + 1) . "\t$base", function ($path, $kib, $where) use (&$folders, $base) {
        $folders[] = [
            'type' => 'dir',
            'name' => substr($path, strlen($base)),
            'bytes' => $kib * 1024,
            'where' => sl_parse_where($where),
        ];
    });
    return $folders;
}

// Size and per-disk breakdown of one folder in an index, or null if it isn't there.
function sl_index_folder(string $index, string $rel): ?array
{
    $found = null;
    sl_index_each($index, sprintf('%04d', sl_depth($rel)) . "\t$rel\t", function ($path, $kib, $where) use (&$found) {
        $found = ['bytes' => $kib * 1024, 'where' => sl_parse_where($where)];
    });
    return $found;
}

// Subfolder name => bytes, from an index. Names are cast back to strings by callers, since PHP
// turns numeric keys such as "2024" into integers.
function sl_index_child_sizes(string $index, string $rel): array
{
    $sizes = [];
    foreach (sl_index_children($index, $rel) as $child) {
        $sizes[$child['name']] = $child['bytes'];
    }
    return $sizes;
}

// ---------------------------------------------------------------------------
// History: rescans keep earlier indexes in history/<finished>.tsv

// Past scans, newest first.
function sl_history(string $dir): array
{
    $scans = [];
    foreach (glob("$dir/history/*.tsv") ?: [] as $file) {
        $finished = (int)basename($file, '.tsv');
        $meta = @parse_ini_file("$dir/history/$finished.meta") ?: [];
        $scans[] = [
            'finished' => $finished,
            'totalBytes' => (int)($meta['totalKb'] ?? 0) * 1024,
            'dirs' => (int)($meta['dirs'] ?? 0),
        ];
    }
    usort($scans, fn($a, $b) => $b['finished'] <=> $a['finished']);
    return $scans;
}

// The past scan to compare with: 'prev' (the latest one kept), a finished timestamp, or 'none'.
// Returns ['index' => path, 'finished' => ts], or null when there is nothing to compare with.
function sl_compare_index(string $dir, string $compare): ?array
{
    if ($compare === '' || $compare === 'none') {
        return null;
    }
    $history = sl_history($dir);
    if ($compare === 'prev') {
        $entry = $history[0] ?? null;
    } else {
        if (!ctype_digit($compare)) {
            throw new SpacelensError(400, 'Invalid comparison');
        }
        $matches = array_values(array_filter($history, fn($h) => $h['finished'] === (int)$compare));
        if (!$matches) {
            throw new SpacelensError(404, 'That scan is no longer kept');
        }
        $entry = $matches[0];
    }
    return $entry ? ['index' => "$dir/history/{$entry['finished']}.tsv", 'finished' => $entry['finished']] : null;
}

// Yields path => KiB for each index line starting with $prefix (one depth band of a subtree).
function sl_index_block(string $index, string $prefix): Generator
{
    $fh = fopen($index, 'rb');
    try {
        fseek($fh, sl_lower_bound($fh, filesize($index), $prefix));
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\n");
            if (!str_starts_with($line, $prefix)) {
                break;
            }
            [, $path, $kib] = explode("\t", $line);
            yield $path => (int)$kib;
        }
    } finally {
        fclose($fh);
    }
}

// Where the growth under $rel is concentrated, compared with an earlier scan.
// Walks both indexes one depth band at a time (each band of a subtree is one sorted block), so
// the cost is the size of the subtree. Every folder that grew by at least minDelta is a
// candidate; it is reported only if its own growth, what is left after subtracting the
// candidates directly below it, is still at least minDelta. So deep/p/q/r growing reports r
// alone, not each of its parents, while a folder that gained files itself is reported.
function sl_growth(string $target, string $rel, string $compare): array
{
    $dir = sl_scan_dir($target);
    $index = "$dir/index.tsv";
    if (!is_file($index)) {
        throw new SpacelensError(404, 'This folder has not been scanned yet');
    }
    $old = sl_compare_index($dir, $compare);
    if (!$old) {
        throw new SpacelensError(404, 'There is no earlier scan to compare with yet. Rescan later to see what grew.');
    }
    $self = sl_index_folder($index, $rel);
    if (!$self) {
        throw new SpacelensError(404, 'Folder not found in the last scan');
    }
    $selfBefore = sl_index_folder($old['index'], $rel)['bytes'] ?? 0;
    $minDelta = max(1 << 20, (int)($self['bytes'] * 0.002));

    // "p" prefix keeps numeric folder names as string keys.
    $grew = [];
    if ($self['bytes'] - $selfBefore >= $minDelta) {
        $grew["p$rel"] = [$self['bytes'], $selfBefore];
    }
    $base = $rel === '' ? '' : "$rel/";
    for ($depth = sl_depth($rel) + 1; ; $depth++) {
        $prefix = sprintf('%04d', $depth) . "\t$base";
        $now = sl_index_block($index, $prefix);
        $was = sl_index_block($old['index'], $prefix);
        $any = false;
        // Merge join. Compare "path\t" so the order matches how the lines are sorted.
        while ($now->valid() || $was->valid()) {
            $any = true;
            $cmp = !$was->valid() ? -1 : (!$now->valid() ? 1 : strcmp($now->key() . "\t", $was->key() . "\t"));
            if ($cmp < 0) {
                [$path, $a, $b] = [$now->key(), $now->current(), 0];
                $now->next();
            } elseif ($cmp > 0) {
                [$path, $a, $b] = [$was->key(), 0, $was->current()];
                $was->next();
            } else {
                [$path, $a, $b] = [$now->key(), $now->current(), $was->current()];
                $now->next();
                $was->next();
            }
            if (($a - $b) * 1024 >= $minDelta) {
                $grew["p$path"] = [$a * 1024, $b * 1024];
            }
        }
        if (!$any) {
            break;
        }
    }

    $explained = [];
    foreach ($grew as $key => [$a, $b]) {
        $path = substr($key, 1);
        if ($path !== $rel) {
            $slash = strrpos($path, '/');
            $parent = 'p' . ($slash === false ? '' : substr($path, 0, $slash));
            $explained[$parent] = ($explained[$parent] ?? 0) + ($a - $b);
        }
    }
    $spots = [];
    foreach ($grew as $key => [$a, $b]) {
        $own = ($a - $b) - ($explained[$key] ?? 0);
        if ($own < $minDelta) {
            continue;
        }
        $spots[] = ['dir' => (string)substr($key, 1), 'bytes' => $a, 'before' => $b, 'delta' => $a - $b, 'own' => $own];
    }
    usort($spots, fn($x, $y) => $y['delta'] <=> $x['delta']);

    return [
        'dir' => $rel,
        'compareAt' => $old['finished'],
        'total' => $self['bytes'],
        'before' => $selfBefore,
        'minDelta' => $minDelta,
        'spots' => array_slice($spots, 0, 50),
    ];
}

// ---------------------------------------------------------------------------
// Browsing an index (continued)

// The rows for one folder: its subfolders from the index, plus its own files listed live.
// The largest subfolders also carry their own top children, for the treemap's second level.
// With an earlier scan to compare with ($compare, see sl_compare_index), folders also carry
// 'before' (bytes then, 0 if new) and folders deleted since are added as 'gone' rows.
function sl_folder(string $target, string $rel, string $compare = 'none'): array
{
    $dir = sl_scan_dir($target);
    $index = "$dir/index.tsv";
    if (!is_file($index)) {
        throw new SpacelensError(404, 'This folder has not been scanned yet');
    }

    $self = sl_index_folder($index, $rel);
    if ($self === null) {
        throw new SpacelensError(404, 'Folder not found in the last scan');
    }
    $total = $self['bytes'];
    $where = $self['where'];
    $old = sl_compare_index($dir, $compare);

    $base = $rel === '' ? '' : "$rel/";
    $folders = sl_index_children($index, $rel);
    $folderBytes = array_sum(array_column($folders, 'bytes'));
    $gone = [];
    if ($old) {
        $was = sl_index_child_sizes($old['index'], $rel);
        foreach ($folders as &$folder) {
            $folder['before'] = $was[$folder['name']] ?? 0;
            unset($was[$folder['name']]);
        }
        unset($folder);
        arsort($was);
        foreach (array_slice($was, 0, 50, true) as $name => $bytes) {
            $gone[] = ['type' => 'gone', 'name' => (string)$name, 'bytes' => 0, 'before' => $bytes];
        }
    }
    $rows = sl_top_rows($folders, 'folders');

    foreach (array_slice(array_keys($rows), 0, SL_TREE_FOLDERS) as $i) {
        if ($rows[$i]['type'] === 'dir') {
            $rows[$i]['children'] = sl_tree_children($index, $base . $rows[$i]['name'], $rows[$i]['bytes'], $old['index'] ?? null, $rows[$i]['before'] ?? null);
        }
    }

    // Whatever the folder holds beyond its subfolders is its own files.
    $fileBytes = max(0, $total - $folderBytes);
    $branches = sl_scan_branches($dir);
    $files = sl_folder_files($branches ?: ['' => $target], $rel);
    if ($files === null) {
        if ($fileBytes > 0) {
            $rows[] = ['type' => 'other', 'name' => 'Files (too many to list)', 'bytes' => $fileBytes];
        }
    } else {
        $rows = array_merge($rows, sl_top_rows($files, 'files'));
    }

    $meta = @parse_ini_file("$dir/meta") ?: [];
    $folder = [
        'dir' => $rel,
        'total' => $total,
        'where' => $where,
        'disks' => array_keys($branches),
        'scannedAt' => (int)($meta['finished'] ?? 0),
        'rows' => array_merge($rows, $gone),
    ];
    if ($old) {
        $folder['compareAt'] = $old['finished'];
        $folder['before'] = sl_index_folder($old['index'], $rel)['bytes'] ?? 0;
    }
    return $folder;
}

// Files directly inside $rel, listed from each disk the scan covered (name => root), so a share's
// files are read from the disks themselves rather than through /mnt/user. A name found on more
// than one disk is flagged: the share only shows one of them, the others are hidden duplicates.
function sl_folder_files(array $branches, string $rel): ?array
{
    $files = [];
    $disksPerName = [];
    foreach ($branches as $disk => $root) {
        $path = $rel === '' ? $root : "$root/$rel";
        if (!is_dir($path)) {
            continue;
        }
        $listed = sl_list_files($path);
        if ($listed === null) {
            return null;
        }
        foreach ($listed as $file) {
            if ($disk !== '') {
                $file['disk'] = $disk;
            }
            $disksPerName[$file['name']] = ($disksPerName[$file['name']] ?? 0) + 1;
            $files[] = $file;
        }
    }
    foreach ($files as &$file) {
        if ($disksPerName[$file['name']] > 1) {
            $file['copies'] = $disksPerName[$file['name']];
        }
    }
    unset($file);
    return $files;
}

// A subfolder's largest children, plus one row for everything else (its files and smaller folders).
// With an earlier index, each also carries 'before'.
function sl_tree_children(string $index, string $rel, int $bytes, ?string $oldIndex = null, ?int $before = null): array
{
    $children = sl_index_children($index, $rel);
    usort($children, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    $shown = array_slice($children, 0, SL_TREE_CHILDREN);
    if ($oldIndex) {
        $was = sl_index_child_sizes($oldIndex, $rel);
        foreach ($shown as &$child) {
            $child['before'] = $was[$child['name']] ?? 0;
        }
        unset($child);
    }
    $rest = $bytes - array_sum(array_column($shown, 'bytes'));
    if ($shown && $rest > 0) {
        $other = [
            'type' => 'other',
            'name' => count($children) > SL_TREE_CHILDREN ? 'Other folders and files' : 'Files',
            'bytes' => $rest,
        ];
        if ($oldIndex && $before !== null) {
            $other['before'] = max(0, $before - array_sum(array_column($shown, 'before')));
        }
        $shown[] = $other;
    }
    return $shown;
}

// The largest files under $rel, from the scan's list of the largest files on the whole target.
function sl_largest(string $target, string $rel): array
{
    // largest2: the format with a disk field. Lists from older scans are ignored until a rescan.
    $list = sl_scan_dir($target) . '/largest2.tsv0';
    if (!is_file($list)) {
        throw new SpacelensError(404, 'Rescan to see the largest files');
    }
    $prefix = $rel === '' ? '' : "$rel/";
    $files = [];
    $records = explode("\0", rtrim((string)file_get_contents($list), "\0"));
    foreach ($records as $record) {
        $fields = explode("\t", $record, 5);
        if (count($fields) < 5 || ($prefix !== '' && !str_starts_with($fields[4], $prefix))) {
            continue;
        }
        $files[] = [
            'path' => $fields[4],
            'disk' => $fields[3],
            'bytes' => (int)$fields[0] * 512,
            'size' => (int)$fields[1],
            'mtime' => (int)$fields[2],
        ];
        if (count($files) >= SL_LARGEST_LIMIT) {
            break;
        }
    }
    return ['dir' => $rel, 'files' => $files, 'listed' => count($records)];
}

// Keeps the largest SL_MAX_ROWS rows and folds the rest into one "N more" row.
function sl_top_rows(array $rows, string $noun): array
{
    usort($rows, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    if (count($rows) <= SL_MAX_ROWS) {
        return $rows;
    }
    $rest = array_slice($rows, SL_MAX_ROWS);
    $top = array_slice($rows, 0, SL_MAX_ROWS);
    $more = [
        'type' => 'other',
        'name' => number_format(count($rest)) . " more $noun",
        'bytes' => array_sum(array_column($rest, 'bytes')),
    ];
    if (isset($rest[0]['before'])) {
        $more['before'] = array_sum(array_column($rest, 'before'));
    }
    $top[] = $more;
    return $top;
}

// Files directly inside $path, sized by allocated blocks to match du. Null if there are too many to stat.
function sl_list_files(string $path): ?array
{
    if (realpath($path) !== $path || !($handle = @opendir($path))) {
        return null;
    }
    $files = [];
    $seen = 0;
    while (($name = readdir($handle)) !== false) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (++$seen > SL_MAX_FILE_STATS) {
            closedir($handle);
            return null;
        }
        $st = @lstat("$path/$name");
        if (!$st || ($st['mode'] & 0170000) === 0040000) {
            continue;
        }
        $files[] = [
            'type' => 'file',
            'name' => $name,
            'bytes' => $st['blocks'] * 512,
            'mtime' => $st['mtime'],
            'links' => $st['nlink'],
        ];
    }
    closedir($handle);
    return $files;
}

// ---------------------------------------------------------------------------
// Scheduled scans and growth alerts. Schedules live on the flash drive; while any exist, a cron
// file runs scripts/scheduled.php every hour (Unraid's update_cron merges plugin .cron files).

const SL_SCHEDULES = '/boot/config/plugins/spacelens/schedules.json';
const SL_CRON = '/boot/config/plugins/spacelens/spacelens.cron';
const SL_NOTIFY = '/usr/local/emhttp/webGui/scripts/notify';

function sl_schedules(): array
{
    $schedules = json_decode((string)@file_get_contents(SL_SCHEDULES), true);
    return is_array($schedules) ? $schedules : [];
}

function sl_schedule_for(string $target): ?array
{
    foreach (sl_schedules() as $schedule) {
        if (($schedule['path'] ?? '') === $target) {
            return $schedule;
        }
    }
    return null;
}

// Saves (or with frequency 'off', removes) the schedule for one target.
function sl_save_schedule(string $target, array $input): ?array
{
    $frequency = $input['frequency'] ?? 'off';
    $hour = filter_var($input['hour'] ?? 3, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 23]]);
    $weekday = filter_var($input['weekday'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
    $alertGb = filter_var($input['alertGb'] ?? 0, FILTER_VALIDATE_FLOAT);
    if (!in_array($frequency, ['off', 'daily', 'weekly'], true) || $hour === false || $weekday === false || $alertGb === false || $alertGb < 0) {
        throw new SpacelensError(400, 'Invalid schedule');
    }

    $schedules = array_values(array_filter(sl_schedules(), fn($s) => ($s['path'] ?? '') !== $target));
    $entry = null;
    if ($frequency !== 'off') {
        $entry = ['path' => $target, 'frequency' => $frequency, 'hour' => $hour, 'weekday' => $weekday, 'alertGb' => $alertGb];
        $schedules[] = $entry;
    }
    @mkdir(dirname(SL_SCHEDULES), 0777, true);
    if (file_put_contents(SL_SCHEDULES, json_encode($schedules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        throw new SpacelensError(500, 'Could not save the schedule');
    }
    sl_sync_cron(count($schedules) > 0);
    return $entry;
}

function sl_sync_cron(bool $enabled): void
{
    if ($enabled) {
        file_put_contents(SL_CRON, "# Spacelens: start due scheduled scans and send growth alerts\n"
            . '0 * * * * /usr/bin/php ' . SL_PLUGIN_DIR . "/scripts/scheduled.php > /dev/null 2>&1\n");
    } else {
        @unlink(SL_CRON);
    }
    if (is_executable('/usr/local/sbin/update_cron')) {
        exec('/usr/local/sbin/update_cron');
    }
}

function sl_schedule_due(array $schedule, array $now): bool
{
    if ((int)$schedule['hour'] !== $now['hours']) {
        return false;
    }
    return $schedule['frequency'] === 'daily'
        || ($schedule['frequency'] === 'weekly' && (int)$schedule['weekday'] === $now['wday']);
}

function sl_format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $n = (float)abs($bytes);
    $i = 0;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    return ($bytes < 0 ? '-' : '') . number_format($n, $n >= 100 || $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

// Once per finished scan: if the target grew by at least the schedule's alertGb since the scan
// before it, send an Unraid notification naming where it grew. Returns the subject when sent.
function sl_check_alert(array $schedule): ?string
{
    $target = $schedule['path'];
    $dir = sl_scan_dir($target);
    $meta = @parse_ini_file("$dir/meta") ?: [];
    $finished = (int)($meta['finished'] ?? 0);
    if (!$finished || $finished <= (int)@file_get_contents("$dir/alerted") || empty($schedule['alertGb'])) {
        return null;
    }
    file_put_contents("$dir/alerted", (string)$finished);

    $previous = sl_history($dir)[0] ?? null;
    if (!$previous) {
        return null;
    }
    $delta = (int)($meta['totalKb'] ?? 0) * 1024 - $previous['totalBytes'];
    if ($delta < $schedule['alertGb'] * 1024 ** 3) {
        return null;
    }

    $spots = array_slice(sl_growth($target, '', (string)$previous['finished'])['spots'], 0, 3);
    $where = implode(', ', array_map(fn($s) => ($s['dir'] === '' ? '(top level)' : $s['dir']) . ' +' . sl_format_bytes($s['delta']), $spots));
    $subject = "Spacelens: $target grew " . sl_format_bytes($delta);
    $description = 'Since ' . date('j M H:i', $previous['finished']) . ($where ? ". Most growth: $where" : '');
    if (is_executable(SL_NOTIFY)) {
        exec(implode(' ', array_map('escapeshellarg', [SL_NOTIFY, '-e', 'Spacelens', '-s', $subject, '-d', $description, '-i', 'warning'])));
    } else {
        error_log("$subject. $description");
    }
    return $subject;
}
