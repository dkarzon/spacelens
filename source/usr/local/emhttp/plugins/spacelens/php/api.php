<?php
// Spacelens API, called from Spacelens.page.
//   GET  ?action=targets                    disks, pools and shares to choose from
//   POST action=select&path=...             validates a target and describes it
//   GET  ?action=status&path=...            scan state, progress and last scan summary
//   POST action=scan&path=...               starts a background scan
//   POST action=cancel&path=...             stops a running scan
//   GET  ?action=folder&path=...&dir=...    subfolders and files of dir (relative to path)
//        &compare=prev|<finished>|none      adds sizes from an earlier scan, and deleted folders
//   GET  ?action=largest&path=...&dir=...   largest files under dir
//   GET  ?action=growth&path=...&dir=...&compare=...   where dir grew most since an earlier scan
//   POST action=schedule&path=...&frequency=off|daily|weekly&hour=&weekday=&alertGb=
// POSTs need the webGui csrf_token.

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json');

try {
    switch ($_REQUEST['action'] ?? '') {
        case 'targets':
            sl_json(['disks' => sl_disk_targets(), 'shares' => sl_share_targets()]);
            break;

        case 'select':
            sl_require_post_csrf();
            $path = sl_safe_path($_POST['path'] ?? '');
            sl_json(['target' => sl_describe_path($path) + ['disks' => sl_branches($path)]]);
            break;

        case 'status':
            sl_json(sl_scan_status(sl_safe_path($_GET['path'] ?? '')));
            break;

        case 'scan':
            sl_require_post_csrf();
            sl_start_scan(sl_safe_path($_POST['path'] ?? ''));
            sl_json(['ok' => true]);
            break;

        case 'cancel':
            sl_require_post_csrf();
            sl_cancel_scan(sl_safe_path($_POST['path'] ?? ''));
            sl_json(['ok' => true]);
            break;

        case 'folder':
            $path = sl_safe_path($_GET['path'] ?? '');
            sl_json(sl_folder($path, sl_safe_rel($_GET['dir'] ?? ''), (string)($_GET['compare'] ?? 'none')));
            break;

        case 'growth':
            $path = sl_safe_path($_GET['path'] ?? '');
            sl_json(sl_growth($path, sl_safe_rel($_GET['dir'] ?? ''), (string)($_GET['compare'] ?? 'prev')));
            break;

        case 'schedule':
            sl_require_post_csrf();
            $path = sl_safe_path($_POST['path'] ?? '');
            sl_json(['schedule' => sl_save_schedule($path, $_POST)]);
            break;

        case 'largest':
            $path = sl_safe_path($_GET['path'] ?? '');
            sl_json(sl_largest($path, sl_safe_rel($_GET['dir'] ?? '')));
            break;

        default:
            sl_fail(400, 'Unknown action');
    }
} catch (SpacelensError $e) {
    sl_fail($e->status, $e->getMessage());
} catch (Throwable $e) {
    error_log('spacelens: ' . $e->getMessage());
    sl_fail(500, 'Internal error');
}
