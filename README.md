# Spacelens

An Unraid plugin that shows what is using the space on a disk, pool or share, TreeSize style, with drill-down.

## Layout

```
spacelens.plg                         plugin installer (version + SHA256 stamped by build.sh)
build.sh                              builds dist/spacelens-<version>-x86_64-1.txz
dev-sync.sh                           pushes web files to a server for quick testing
source/usr/local/emhttp/plugins/spacelens/
  Spacelens.page                      the Spacelens page (Settings > User Utilities)
  php/api.php                         JSON API used by the page
  php/lib.php                         helpers: CSRF check, path validation, target discovery
  js/spacelens.js                     page UI
  js/vendor/echarts.min.js            ECharts 5.5.1 (Apache 2.0, see echarts.LICENSE)
  scripts/scan.sh                     background scanner (du + find)
  scripts/scheduled.php               hourly cron job: scheduled scans and growth alerts
  css/spacelens.css
```

## Develop

Fast loop (no package): `./dev-sync.sh root@tower`, then refresh the page.
Changes made this way are lost on reboot. Install the plugin once first, so the page is registered.

## Build and test-install

```bash
./build.sh                  # or ./build.sh 2026.10.21a for a second build on the same day
scp dist/spacelens-*.txz root@tower:/boot/config/plugins/spacelens/
scp dist/spacelens.plg root@tower:/tmp/
ssh root@tower plugin install /tmp/spacelens.plg
```

The package is already in `/boot/config/plugins/spacelens/` with a matching SHA256, so the install doesn't try to download it.
Create that folder on the server first if this is the first install.

### Local install (no GitHub)

`spacelens-local.plg` never downloads anything. It installs the package from `/boot/config/plugins/spacelens/` or `/tmp/`, after checking its SHA256:

```bash
./build.sh
scp dist/spacelens-*.txz dist/spacelens-local.plg root@tower:/tmp/
ssh root@tower plugin install /tmp/spacelens-local.plg
```

It has no `pluginURL`, so Unraid won't check it for updates. To update, rebuild and run the same steps again.

## Release

Run **Actions > Release > Run workflow** on `main`. It picks the version (`yyyy.MM.dd`, with
`a`, `b`, ... added for later builds on the same day), runs `build.sh`, commits the stamped
`.plg` files, tags the commit and publishes a GitHub release with the `.txz` attached.

## How scanning works

`scripts/scan.sh` runs `du -x -k -0` on the target at low priority (`nice`, `ionice -c3`) and turns its
output into `index.tsv`: one line per folder, `depth<TAB>relative path<TAB>KiB`, sorted bytewise.
That puts every folder's direct children in one contiguous block, which `lib.php` finds with a
binary search, so drilling into any folder costs a few file seeks, not a rescan.
Files inside the folder you are viewing are listed live.

A user share (`/mnt/user/<share>`) is scanned on each disk and pool that has a `<share>` folder,
in parallel, instead of through the slower `/mnt/user` layer. The per-disk results are merged,
and every index line also records how much of that folder is on each disk.

Indexes live in `INDEX_DIR` (default `/tmp/spacelens`, in RAM, so they are lost on reboot).
To keep them, put e.g. `INDEX_DIR="/mnt/cache/appdata/spacelens"` in `/boot/config/plugins/spacelens/spacelens.cfg`.
A rescan keeps the previous index browsable until it finishes, and a cancelled rescan leaves it untouched.

Each finished rescan moves the previous index to `history/<finished>.tsv`; the newest `HISTORY_KEEP`
(default 4) are kept. Comparing walks the current and an earlier index together one depth band at
a time, so the Growth tab only reads the subtree it is asked about. With the default `INDEX_DIR`
in RAM, history is lost on reboot; set `INDEX_DIR` to a folder on a pool to keep it.

Scheduled scans are saved in `/boot/config/plugins/spacelens/schedules.json`. While any exist,
`spacelens.cron` (merged by Unraid's `update_cron`) runs `scripts/scheduled.php` hourly: it starts
scans that are due and sends an Unraid notification when a finished scan grew by more than the
schedule's threshold.

## Roadmap

1. Skeleton: page, target picker, API with CSRF *(done)*
2. Background scan (`du`), progress, index, folder drill-down table *(done)*
3. Treemap (ECharts), largest files *(done)*
4. Shares merged across cache and array disks, disk spin-up warning *(done)*
5. Scan history, growth since last scan, scheduled scans *(done)*
6. Community Applications submission
