Title: [Plugin] Spacelens

# Spacelens

See what is using the space on your disks, pools and shares, TreeSize style, and drill down folder by folder.

Spacelens scans a disk, pool or share once in the background and saves an index. After that, opening any folder is instant, with no rescan. You can keep earlier scans and compare them to see where the space went since last week.

[screenshot: resources/home.png]
[screenshot: resources/share.png]

## Features

- **Pick a target:** array disks, the cache and other pools, and user shares are listed separately.
- **Background scans** at low CPU and I/O priority, with progress, cancel and rescan. The previous scan stays browsable until the new one finishes.
- **Folder drill-down:** subfolders and files sorted by size, with breadcrumbs.
- **Treemap** of the current folder (two levels deep), kept in sync with the table. Click a folder to open it.
- **Largest files** tab: the biggest files anywhere under the current folder.
- **Shares across disks:** a user share is scanned on each disk and pool directly, in parallel, rather than through `/mnt/user`, and the results are merged. Every folder shows how much of it sits on each disk.
- **Duplicate names flagged:** files with the same name in the same folder on more than one disk (the share only shows one of them).
- **Scan history and growth:** the last few scans are kept. Compare with any of them to get a Change column, a list of deleted folders, and a treemap coloured by growth. The Growth tab shows the folders where growth actually happened, anywhere below the current folder.
- **Scheduled scans** (daily or weekly), with an optional Unraid notification when a target grows by more than a set amount.
- **Spin-up aware:** spun-down disks are marked, and a scan only asks you to confirm when it would spin one up.
- Hard-linked files are labelled.

## Install

Search for **Spacelens** in the **Apps** tab, or go to **Plugins → Install Plugin** and paste:

`https://raw.githubusercontent.com/dkarzon/spacelens/main/spacelens.plg`

Then open **Settings → User Utilities → Spacelens**, pick a disk, pool or share, and start a scan.

**Requirements:** Unraid 6.12 or later (x86_64).

## Good to know

- **Spin-ups:** scanning reads directory metadata, so it wakes any spun-down disk it covers. Spacelens warns you first; schedule scans to suit.
- **Where indexes live:** by default in `/tmp/spacelens`, which is in RAM, so indexes and scan history are lost on reboot. To keep them, add a line like this to `/boot/config/plugins/spacelens/spacelens.cfg`:
  `INDEX_DIR="/mnt/cache/appdata/spacelens"`
- **History:** the newest 4 scans per target are kept by default. Change it with `HISTORY_KEEP` in the same file.
- **Uninstalling** removes the plugin, its settings, its schedules and anything in `/tmp/spacelens`. If you moved `INDEX_DIR` somewhere else, delete that folder yourself.

## Getting help

Post in this thread or open an issue on GitHub: https://github.com/dkarzon/spacelens/issues

Please include:
- your Unraid version
- the target you were scanning (disk, pool or share)
- any error shown on the Spacelens page
- a screenshot, if it's a display problem

## Source and credits

- Spacelens is open source under the MIT licence: https://github.com/dkarzon/spacelens
- Charts use Apache ECharts 5.5.1 (Apache 2.0 licence).

Feedback and feature requests are welcome.
