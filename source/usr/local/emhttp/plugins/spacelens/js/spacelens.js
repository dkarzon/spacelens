// Spacelens UI: pick a target, scan it in the background, then drill down folder by folder
// with a treemap and a table that stay in sync.
(function ($) {
  'use strict';

  const cfg = window.Spacelens;
  const $root = $('#spacelens');
  const POLL_MS = 1500;
  const FILE_COLOR = '#8c939d';
  const OTHER_COLOR = '#6c727b';

  const state = {
    targets: {},      // path -> target from the picker
    target: null,     // selected target
    dir: '',          // current folder, relative to target.path
    folder: null,     // last folder response
    folderLoading: false,
    largest: null,    // last largest-files response
    growth: null,     // last growth response
    history: [],      // earlier scans kept, newest first
    compare: 'prev',  // earlier scan to compare with: 'prev', a finished timestamp, or 'none'
    colourBy: 'folder',
    scheduleShownFor: null,
    tab: 'contents',
    sort: { key: 'bytes', desc: true },
    chart: null,
    pollTimer: null,
    wasScanning: false,
  };

  // ---- helpers ------------------------------------------------------------

  function esc(value) {
    return $('<div>').text(value == null ? '' : String(value)).html();
  }

  function formatBytes(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let n = Number(bytes) || 0;
    let i = 0;
    while (n >= 1024 && i < units.length - 1) {
      n /= 1024;
      i++;
    }
    return `${n.toFixed(n >= 100 || i === 0 ? 0 : 1)} ${units[i]}`;
  }

  function formatDuration(seconds) {
    const s = Math.max(0, Math.round(seconds));
    if (s < 60) return `${s}s`;
    const m = Math.floor(s / 60);
    if (m < 60) return `${m}m ${s % 60}s`;
    return `${Math.floor(m / 60)}h ${m % 60}m`;
  }

  function formatTime(ts) {
    return ts ? new Date(ts * 1000).toLocaleString() : '';
  }

  function joinDir(parent, name) {
    return parent ? `${parent}/${name}` : name;
  }

  function api(method, action, data) {
    const payload = Object.assign({ action }, data);
    if (method === 'POST') payload.csrf_token = cfg.csrf;
    return $.ajax({ url: cfg.api, method, data: payload, dataType: 'json' });
  }

  function errorText(xhr) {
    return (xhr.responseJSON && xhr.responseJSON.error) || `${xhr.status} ${xhr.statusText}`;
  }

  function usageBar(t) {
    if (t.total == null) return '';
    const pct = t.total > 0 ? Math.round((t.used / t.total) * 100) : 0;
    return `
      <div class="sl-bar" title="${pct}% used"><div class="sl-bar-fill" style="width:${pct}%"></div></div>
      <div class="sl-muted">${formatBytes(t.used)} used of ${formatBytes(t.total)} · ${formatBytes(t.free)} free</div>`;
  }

  // Whether the current scan covers more than one disk (a user share), so per-disk detail is worth showing.
  function multiDisk() {
    return !!(state.folder && state.folder.disks && state.folder.disks.length > 1);
  }

  // {disk1: bytes, cache: bytes} -> "disk1 5 GB · cache 1 GB", largest first.
  function diskChips(where, max = 3) {
    const entries = Object.entries(where || {}).filter(([, b]) => b > 0).sort((a, b) => b[1] - a[1]);
    const chips = entries.slice(0, max).map(([disk, bytes]) =>
      `<span class="sl-disk">${esc(disk)} <b>${formatBytes(bytes)}</b></span>`);
    if (entries.length > max) chips.push(`<span class="sl-muted">+${entries.length - max} more</span>`);
    return chips.join(' ');
  }

  // ---- change since an earlier scan ----------------------------------------

  // Whether the current folder view is being compared with an earlier scan.
  function comparing() {
    return !!(state.folder && state.folder.compareAt);
  }

  // Change for a row, or null when there is nothing to compare (files, "more" rows).
  function rowDelta(row) {
    return row.before == null ? null : row.bytes - row.before;
  }

  function formatDelta(delta) {
    if (delta === 0) return 'no change';
    return `${delta > 0 ? '+' : '−'}${formatBytes(Math.abs(delta))}`;
  }

  function changeCell(row) {
    const delta = rowDelta(row);
    if (delta === null) return '<td class="sl-num sl-change"></td>';
    let text = formatDelta(delta);
    if (row.type === 'gone') text += ' <span class="sl-muted">(deleted)</span>';
    else if (row.before === 0 && row.bytes > 0) text += ' <span class="sl-muted">(new)</span>';
    const cls = delta > 0 ? 'sl-up' : delta < 0 ? 'sl-down' : 'sl-muted';
    return `<td class="sl-num sl-change ${cls}">${text}</td>`;
  }

  // Treemap colours for "colour by growth": grey for no change, towards red for growth and green
  // for shrinking. Square root so moderate changes still show next to one huge one.
  const NEUTRAL = [138, 143, 152];
  const GROW = [224, 49, 49];
  const SHRINK = [47, 158, 68];

  function growthColour(delta, maxAbs) {
    if (delta == null) return OTHER_COLOR;
    const t = maxAbs > 0 ? Math.sqrt(Math.min(1, Math.abs(delta) / maxAbs)) : 0;
    const to = delta >= 0 ? GROW : SHRINK;
    const rgb = NEUTRAL.map((c, i) => Math.round(c + (to[i] - c) * t));
    return `rgb(${rgb.join(',')})`;
  }

  function stopPolling() {
    clearTimeout(state.pollTimer);
    state.pollTimer = null;
  }

  function showMessage(html) {
    $('#sl-message').html(html || '');
  }

  // ---- picker -------------------------------------------------------------

  function renderDisks(disks) {
    return disks.map((d) => `
      <button type="button" class="sl-card" data-path="${esc(d.path)}">
        <div class="sl-card-title">${esc(d.name)} <span class="sl-badge">${esc(d.kind)}</span>${d.spundown ? ' <span class="sl-badge sl-asleep" title="Spun down. Scanning it will spin it up.">spun down</span>' : ''}</div>
        <div class="sl-muted">${esc(d.path)}${d.fsType ? ' · ' + esc(d.fsType) : ''}</div>
        ${usageBar(d)}
      </button>`).join('');
  }

  function renderShares(shares) {
    if (!shares.length) return '<span class="sl-muted">No user shares found.</span>';
    return shares.map((s) => `
      <button type="button" class="sl-row" data-path="${esc(s.path)}">
        <span class="sl-row-name">${esc(s.name)}</span>
        <span class="sl-muted">${esc(s.comment)}</span>
        <span class="sl-badge">${s.cachePool ? esc(s.cachePool) + ': ' : ''}${esc(s.useCache || 'no')}</span>
      </button>`).join('');
  }

  function loadTargets() {
    api('GET', 'targets')
      .done((res) => {
        res.disks.concat(res.shares).forEach((t) => { state.targets[t.path] = t; });
        // Cache, other pools and array disks each get their own section, hidden when empty.
        ['cache', 'pool', 'disk'].forEach((kind) => {
          const id = kind === 'pool' ? 'pools' : kind === 'disk' ? 'disks' : 'cache';
          const items = res.disks.filter((d) => d.kind === kind);
          $(`#sl-${id}`).html(renderDisks(items));
          $(`#sl-${id}-section`).prop('hidden', !items.length);
        });
        $('#sl-shares').html(renderShares(res.shares));
      })
      .fail((xhr) => {
        const msg = `<span class="sl-error">Could not load disks and shares: ${esc(errorText(xhr))}</span>`;
        $('#sl-cache, #sl-pools, #sl-disks, #sl-shares').html(msg);
      });
  }

  function selectTarget(path) {
    stopPolling();
    state.target = state.targets[path] || { path, name: path };
    state.dir = '';
    state.folder = null;
    state.largest = null;
    state.growth = null;
    state.history = [];
    state.compare = 'prev';
    state.scheduleShownFor = null;
    state.wasScanning = false;
    $('#sl-schedule').empty().prop('hidden', true);
    $('#sl-picker').prop('hidden', true);
    $('#sl-view').prop('hidden', false);
    $('#sl-browser').prop('hidden', true);
    showMessage('');
    $('#sl-scanbar').html('<span class="sl-muted">Checking…</span>');

    api('POST', 'select', { path })
      .done((res) => {
        Object.assign(state.target, res.target);
        loadStatus();
      })
      .fail((xhr) => $('#sl-scanbar').html(`<span class="sl-error">${esc(errorText(xhr))}</span>`));
  }

  function backToPicker() {
    stopPolling();
    state.target = null;
    $('#sl-view').prop('hidden', true);
    $('#sl-picker').prop('hidden', false);
  }

  // ---- scanning -----------------------------------------------------------

  function renderScanBar(status) {
    const t = state.target;
    const head = `
      <div class="sl-scan-head">
        <div>
          <div class="sl-card-title">${esc(t.name)}</div>
          <div class="sl-muted">${esc(t.path)}</div>
        </div>
        <div class="sl-actions">${scanButtons(status)}</div>
      </div>`;
    return head + usageBar(t) + diskList(t) + scanDetails(status);
  }

  // For a share: which disks and pools hold its folders.
  function diskList(t) {
    if (t.kind !== 'share' || !t.disks) return '';
    if (!t.disks.length) return '<div class="sl-muted">This share has no folders on any disk or pool.</div>';
    const names = t.disks.map((d) => `<span class="sl-disk">${esc(d.name)}${d.spundown ? ' <span class="sl-asleep">(spun down)</span>' : ''}</span>`);
    return `<div class="sl-muted sl-disks">Stored on ${names.join(' ')}</div>`;
  }

  function scanButtons(status) {
    if (status.state === 'running') return '<button type="button" data-action="cancel">Cancel scan</button>';
    if (status.state === 'starting') return '<button type="button" disabled>Starting…</button>';
    return `<button type="button" data-action="scan">${status.last ? 'Rescan' : 'Scan'}</button>`;
  }

  function scanDetails(status) {
    const parts = [];
    if (status.state === 'running' && status.progress) {
      const p = status.progress;
      const pct = p.estimate != null ? Math.round(p.estimate * 100) : null;
      const step = p.phase === 'files' ? 'Finding the largest files' : 'Measuring folders';
      parts.push(`
        <div class="sl-bar sl-progress${pct == null ? ' sl-indeterminate' : ''}">
          <div class="sl-bar-fill" style="width:${pct == null ? 30 : pct}%"></div>
        </div>
        <div class="sl-muted">${step} · ${formatDuration(p.elapsed)}${pct != null ? ` · about ${pct}% (based on the last scan)` : ''}</div>
        ${p.current ? `<div class="sl-muted sl-ellipsis" title="${esc(p.current)}">Last folder done: ${esc(p.current)}</div>` : ''}`);
    }
    if (status.failed) {
      parts.push(`<div class="sl-error">Last scan failed: ${esc(status.failed.join(' '))}</div>`);
    }
    if (status.last) {
      const l = status.last;
      const took = l.finished && l.started ? ` in ${formatDuration(l.finished - l.started)}` : '';
      parts.push(`<div class="sl-muted">Last scan ${esc(formatTime(l.finished))}${took} · ${l.dirs.toLocaleString()} folders · ${formatBytes(l.totalBytes)}</div>`);
      if (l.errors) {
        parts.push(`<details class="sl-muted"><summary>${l.errors.toLocaleString()} folder${l.errors === 1 ? '' : 's'} could not be read</summary>
          <pre class="sl-pre">${esc(l.errorSample.join('\n'))}</pre></details>`);
      }
    } else if (status.state === 'idle' && !status.failed) {
      parts.push('<p class="sl-muted">Not scanned yet. Scanning reads every folder at low priority. It can take a few minutes on a large disk.</p>');
    }
    return parts.join('');
  }

  function loadStatus() {
    stopPolling();
    const target = state.target;
    api('GET', 'status', { path: target.path })
      .done((status) => {
        if (state.target !== target) return;
        $('#sl-scanbar').html(renderScanBar(status));
        if (state.scheduleShownFor !== target) {
          state.scheduleShownFor = target;
          renderSchedule(status.schedule);
        }
        state.history = status.history || [];
        renderCompareSelect();

        const scanning = status.state === 'running' || status.state === 'starting';
        if (scanning) {
          state.wasScanning = true;
          state.pollTimer = setTimeout(loadStatus, POLL_MS);
        }
        // Show the index on first load, and refresh it when a scan finishes.
        const finished = state.wasScanning && !scanning;
        if (status.last && ((!state.folder && !state.folderLoading) || finished)) {
          state.largest = null;
          state.growth = null;
          loadFolder(state.dir);
        }
        if (!scanning) state.wasScanning = false;
      })
      .fail((xhr) => {
        if (state.target !== target) return;
        $('#sl-scanbar').html(`<span class="sl-error">${esc(errorText(xhr))}</span>`);
      });
  }

  function startScan() {
    const t = state.target;
    // Only ask when the scan will actually wake a disk.
    const asleep = (t.disks || []).filter((d) => d.spundown).map((d) => d.name);
    if (asleep.length && !window.confirm(
      `Scanning ${t.path} will spin up ${asleep.join(', ')}, which ${asleep.length === 1 ? 'is' : 'are'} spun down. Continue?`)) {
      return;
    }
    api('POST', 'scan', { path: t.path })
      .done(loadStatus)
      .fail((xhr) => window.alert(`Could not start the scan: ${errorText(xhr)}`));
  }

  function cancelScan() {
    api('POST', 'cancel', { path: state.target.path })
      .always(loadStatus)
      .fail((xhr) => window.alert(`Could not cancel the scan: ${errorText(xhr)}`));
  }

  // ---- browsing -----------------------------------------------------------

  function loadFolder(dir) {
    const target = state.target;
    state.folderLoading = true;
    $('#sl-browser').addClass('sl-loading');
    api('GET', 'folder', { path: target.path, dir, compare: state.compare })
      .done((folder) => {
        if (state.target !== target) return;
        state.dir = folder.dir;
        state.folder = folder;
        showMessage('');
        $('#sl-browser').prop('hidden', false);
        $('#sl-colour-wrap').prop('hidden', !comparing());
        renderFolder();
        renderTreemap();
        if (state.tab === 'largest') loadLargest();
        if (state.tab === 'growth') loadGrowth();
      })
      .fail((xhr) => {
        if (state.target !== target) return;
        // The folder may have gone since the last scan: fall back to the top.
        if (xhr.status === 404 && dir !== '') {
          loadFolder('');
          return;
        }
        $('#sl-browser').prop('hidden', true);
        showMessage(`<span class="sl-error">${esc(errorText(xhr))}</span>`);
      })
      .always(() => {
        state.folderLoading = false;
        $('#sl-browser').removeClass('sl-loading');
      });
  }

  function breadcrumbs() {
    const parts = state.dir ? state.dir.split('/') : [];
    const crumbs = [`<button type="button" class="sl-link" data-dir="">${esc(state.target.name)}</button>`];
    parts.forEach((name, i) => {
      const dir = parts.slice(0, i + 1).join('/');
      crumbs.push(i === parts.length - 1
        ? `<span>${esc(name)}</span>`
        : `<button type="button" class="sl-link" data-dir="${esc(dir)}">${esc(name)}</button>`);
    });
    return crumbs.join('<span class="sl-sep">/</span>');
  }

  function sortedRows(rows) {
    const { key, desc } = state.sort;
    const dir = desc ? -1 : 1;
    // "N more…" rows always stay at the bottom.
    return rows.slice().sort((a, b) => {
      if ((a.type === 'other') !== (b.type === 'other')) return a.type === 'other' ? 1 : -1;
      let cmp;
      if (key === 'name') cmp = String(a.name).localeCompare(String(b.name), undefined, { numeric: true, sensitivity: 'base' });
      else if (key === 'delta') cmp = (rowDelta(a) || 0) - (rowDelta(b) || 0);
      else cmp = a.bytes - b.bytes;
      return cmp * dir;
    });
  }

  function sortHeader(key, label) {
    const active = state.sort.key === key;
    const arrow = active ? (state.sort.desc ? ' ▼' : ' ▲') : '';
    return `<th class="sl-sortable${key !== 'name' ? ' sl-num' : ''}" data-sort="${key}">${label}${arrow}</th>`;
  }

  function shareBar(bytes, total) {
    const pct = total > 0 ? (bytes / total) * 100 : 0;
    return `
      <td class="sl-pct"><div class="sl-bar"><div class="sl-bar-fill" style="width:${pct.toFixed(1)}%"></div></div></td>
      <td class="sl-num sl-muted">${pct.toFixed(1)}%</td>`;
  }

  function renderRow(row, total) {
    const icon = { dir: 'fa-folder', file: 'fa-file-o', other: 'fa-ellipsis-h', gone: 'fa-folder-o' }[row.type];
    const name = row.type === 'dir'
      ? `<button type="button" class="sl-link" data-dir="${esc(joinDir(state.dir, row.name))}">${esc(row.name)}</button>`
      : `<span class="${row.type === 'other' || row.type === 'gone' ? 'sl-muted' : ''}">${esc(row.name)}</span>`;
    // The scan counts a hard-linked file's space once, wherever it found it first,
    // so these rows can add up to more than the folder total.
    const link = row.links > 1
      ? ` <span class="sl-badge" title="This file has ${row.links} names (hard links). Its space is counted once, so it may already be included in another folder's total.">hard link</span>`
      : '';
    const copies = row.copies > 1
      ? ` <span class="sl-badge sl-warn" title="A file with this name is in this folder on ${row.copies} disks. The share only shows one of them; the others take up space but can't be seen through the share.">on ${row.copies} disks</span>`
      : '';
    const disks = multiDisk()
      ? `<td class="sl-where">${row.disk ? `<span class="sl-disk">${esc(row.disk)}</span>` : diskChips(row.where)}</td>`
      : '';
    return `
      <tr class="sl-${row.type}">
        <td class="sl-name"><i class="fa ${icon}"></i> ${name}${link}${copies}</td>
        ${disks}
        <td class="sl-num">${row.type === 'gone' ? '' : formatBytes(row.bytes)}</td>
        ${comparing() ? changeCell(row) : ''}
        ${shareBar(row.bytes, total)}
      </tr>`;
  }

  function renderFolder() {
    const f = state.folder;
    const rows = sortedRows(f.rows);
    const multi = multiDisk();
    const cmp = comparing();
    const body = rows.length
      ? rows.map((r) => renderRow(r, f.total)).join('')
      : `<tr><td colspan="${4 + multi + cmp}" class="sl-muted">This folder is empty.</td></tr>`;
    let summary = `${formatBytes(f.total)} in this folder`;
    if (cmp) {
      const delta = f.total - f.before;
      const cls = delta > 0 ? 'sl-up' : delta < 0 ? 'sl-down' : '';
      summary += ` · <span class="${cls}">${formatDelta(delta)}</span> since ${esc(formatTime(f.compareAt))}`;
    }
    if (multi) summary += ` · ${diskChips(f.where, 8)}`;
    $('#sl-crumbs').html(breadcrumbs());
    $('#sl-folder-total').html(summary);
    $('#sl-contents').html(`
      <table class="sl-table">
        <thead><tr>${sortHeader('name', 'Name')}${multi ? '<th class="sl-where">Disks</th>' : ''}${sortHeader('bytes', 'Size')}${cmp ? sortHeader('delta', 'Change') : ''}<th class="sl-pct">Share of folder</th><th></th></tr></thead>
        <tbody>${body}</tbody>
      </table>`);
  }

  // Options: the previous scan, each earlier scan kept, or nothing.
  function renderCompareSelect() {
    const history = state.history;
    if (state.compare !== 'prev' && state.compare !== 'none' && !history.some((h) => String(h.finished) === state.compare)) {
      state.compare = 'prev';
    }
    $('#sl-compare-wrap').prop('hidden', !history.length);
    if (!history.length) return;
    const options = [`<option value="prev">previous scan (${esc(formatTime(history[0].finished))})</option>`]
      .concat(history.slice(1).map((h) => `<option value="${h.finished}">${esc(formatTime(h.finished))}</option>`))
      .concat(['<option value="none">nothing</option>']);
    $('#sl-compare').html(options.join('')).val(state.compare);
  }

  // ---- treemap ------------------------------------------------------------

  // Unraid has light and dark themes; read the page background to match the chart to it.
  function pageBackground() {
    for (const el of [document.body, document.documentElement]) {
      const rgb = (getComputedStyle(el).backgroundColor.match(/[\d.]+/g) || []).map(Number);
      if (rgb.length >= 3 && (rgb.length < 4 || rgb[3] > 0)) {
        return { color: `rgb(${rgb[0]},${rgb[1]},${rgb[2]})`, dark: 0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2] < 128 };
      }
    }
    return { color: '#ffffff', dark: false };
  }

  function treeNode(row, parentDir, maxAbs) {
    const dir = row.type === 'dir' ? joinDir(parentDir, row.name) : null;
    const delta = rowDelta(row);
    const node = { name: row.name, value: row.bytes, dir, kind: row.type, where: row.where, disk: row.disk, delta };
    if (maxAbs != null) node.itemStyle = { color: growthColour(delta, maxAbs) };
    else if (row.type !== 'dir') node.itemStyle = { color: row.type === 'file' ? FILE_COLOR : OTHER_COLOR };
    const children = (row.children || []).filter((c) => c.bytes > 0);
    if (children.length) node.children = children.map((c) => treeNode(c, dir, maxAbs));
    return node;
  }

  function renderTreemap() {
    const el = document.getElementById('sl-treemap');
    const f = state.folder;
    // Colouring by growth scales every block against the biggest change on screen.
    const byGrowth = state.colourBy === 'growth' && comparing();
    let maxAbs = null;
    if (byGrowth) {
      maxAbs = 0;
      f.rows.forEach((r) => [r].concat(r.children || []).forEach((n) => {
        const d = rowDelta(n);
        if (d !== null) maxAbs = Math.max(maxAbs, Math.abs(d));
      }));
    }
    const data = f.rows.filter((r) => r.bytes > 0).map((r) => treeNode(r, state.dir, maxAbs));
    if (!window.echarts || !data.length) {
      el.hidden = true;
      return;
    }
    el.hidden = false;
    const bg = pageBackground();
    if (!state.chart) state.chart = window.echarts.init(el);
    state.chart.resize();

    state.chart.setOption({
      tooltip: {
        formatter: (info) => {
          const d = info.data;
          const pct = f.total > 0 ? ((d.value / f.total) * 100).toFixed(1) : '0';
          const path = d.dir ? `<br><span style="opacity:.7">${esc(d.dir)}</span>` : '';
          let disks = '';
          if (multiDisk()) {
            const where = d.disk ? { [d.disk]: d.value } : d.where;
            disks = Object.entries(where || {}).filter(([, b]) => b > 0).sort((a, b) => b[1] - a[1])
              .map(([disk, b]) => `<br>${esc(disk)}: ${formatBytes(b)}`).join('');
          }
          const change = d.delta != null ? `<br>${formatDelta(d.delta)} since ${esc(formatTime(f.compareAt))}` : '';
          return `<b>${esc(d.name)}</b>${path}<br>${formatBytes(d.value)} · ${pct}% of this folder${change}${disks}`;
        },
      },
      series: [{
        type: 'treemap',
        data,
        left: 0, right: 0, top: 0, bottom: 0,
        roam: false,
        nodeClick: false,
        breadcrumb: { show: false },
        animationDurationUpdate: 300,
        label: {
          show: true,
          formatter: (p) => `${p.name}\n${formatBytes(p.value)}`,
          overflow: 'truncate',
          fontSize: 11,
        },
        upperLabel: {
          show: true,
          height: 20,
          formatter: (p) => `${p.name}  ${formatBytes(p.value)}`,
          overflow: 'truncate',
          color: '#fff',
        },
        levels: [
          { itemStyle: { borderWidth: 0, gapWidth: 3, borderColor: bg.color }, upperLabel: { show: false } },
          { itemStyle: { borderWidth: 3, gapWidth: 1, borderColor: bg.dark ? '#444' : '#999' } },
          byGrowth
            ? { itemStyle: { borderWidth: 1, gapWidth: 1, borderColor: bg.dark ? '#333' : '#bbb' } }
            : { colorSaturation: [0.35, 0.55], itemStyle: { borderWidth: 1, gapWidth: 1, borderColorSaturation: 0.6 } },
        ],
      }],
    }, true);
  }

  // ---- largest files ------------------------------------------------------

  function loadLargest() {
    const target = state.target;
    const dir = state.dir;
    if (state.largest && state.largest.dir === dir) return;
    $('#sl-largest').html('<span class="sl-muted">Loading…</span>');
    api('GET', 'largest', { path: target.path, dir })
      .done((res) => {
        if (state.target !== target || state.dir !== dir) return;
        state.largest = res;
        renderLargest();
      })
      .fail((xhr) => {
        if (state.target !== target) return;
        $('#sl-largest').html(`<span class="sl-muted">${esc(errorText(xhr))}</span>`);
      });
  }

  function renderLargest() {
    const res = state.largest;
    const total = state.folder ? state.folder.total : 0;
    const note = `From the ${res.listed.toLocaleString()} largest files found by the last scan.`;
    if (!res.files.length) {
      $('#sl-largest').html(`<p class="sl-muted">None of the largest files are in this folder. ${note}</p>`);
      return;
    }
    const prefix = state.dir ? `${state.dir}/` : '';
    const rows = res.files.map((f) => {
      const inside = f.path.slice(prefix.length);
      const slash = inside.lastIndexOf('/');
      const name = slash < 0 ? inside : inside.slice(slash + 1);
      const folder = slash < 0 ? '' : inside.slice(0, slash);
      const where = folder
        ? `<button type="button" class="sl-link sl-muted" data-dir="${esc(joinDir(state.dir, folder))}">${esc(folder)}</button>`
        : '';
      return `
        <tr class="sl-file">
          <td class="sl-name"><i class="fa fa-file-o"></i> ${esc(name)}<div class="sl-path">${where}</div></td>
          ${multiDisk() ? `<td class="sl-where"><span class="sl-disk">${esc(f.disk)}</span></td>` : ''}
          <td class="sl-num">${formatBytes(f.bytes)}</td>
          ${shareBar(f.bytes, total)}
          <td class="sl-num sl-muted sl-date">${esc(new Date(f.mtime * 1000).toLocaleDateString())}</td>
        </tr>`;
    }).join('');
    $('#sl-largest').html(`
      <table class="sl-table">
        <thead><tr><th>File</th>${multiDisk() ? '<th class="sl-where">Disk</th>' : ''}<th class="sl-num">Size</th><th class="sl-pct">Share of folder</th><th></th><th class="sl-num sl-date">Modified</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <p class="sl-muted">${note}</p>`);
  }

  // ---- growth -------------------------------------------------------------

  function loadGrowth() {
    const target = state.target;
    const dir = state.dir;
    const compare = state.compare === 'none' ? 'prev' : state.compare;
    if (state.growth && state.growth.dir === dir && state.growth.compare === compare) return;
    $('#sl-growth').html('<span class="sl-muted">Comparing scans…</span>');
    api('GET', 'growth', { path: target.path, dir, compare })
      .done((res) => {
        if (state.target !== target || state.dir !== dir) return;
        state.growth = Object.assign(res, { compare });
        renderGrowth();
      })
      .fail((xhr) => {
        if (state.target !== target) return;
        $('#sl-growth').html(`<p class="sl-muted">${esc(errorText(xhr))}</p>`);
      });
  }

  function renderGrowth() {
    const g = state.growth;
    const since = esc(formatTime(g.compareAt));
    const delta = g.total - g.before;
    const head = `<p>This folder: ${formatBytes(g.total)}, <span class="${delta > 0 ? 'sl-up' : delta < 0 ? 'sl-down' : ''}">${formatDelta(delta)}</span> since ${since}.</p>`;
    if (!g.spots.length) {
      $('#sl-growth').html(`${head}<p class="sl-muted">Nothing under this folder grew by ${formatBytes(g.minDelta)} or more since then.</p>`);
      return;
    }
    const maxDelta = g.spots[0].delta;
    const prefix = state.dir ? `${state.dir}/` : '';
    const rows = g.spots.map((s) => {
      const inside = s.dir === state.dir ? '' : s.dir.slice(prefix.length);
      const name = inside
        ? `<button type="button" class="sl-link" data-dir="${esc(s.dir)}">${esc(inside)}</button>`
        : '<span class="sl-muted">This folder itself (its own files, or many small subfolders)</span>';
      const pct = maxDelta > 0 ? (s.delta / maxDelta) * 100 : 0;
      return `
        <tr>
          <td class="sl-name"><i class="fa fa-folder"></i> ${name}${s.before === 0 ? ' <span class="sl-badge sl-warn">new</span>' : ''}</td>
          <td class="sl-num">${formatBytes(s.bytes)}</td>
          <td class="sl-num sl-muted">${formatBytes(s.before)}</td>
          <td class="sl-num sl-up">${formatDelta(s.delta)}</td>
          <td class="sl-pct"><div class="sl-bar"><div class="sl-bar-fill sl-bar-up" style="width:${pct.toFixed(1)}%"></div></div></td>
        </tr>`;
    }).join('');
    $('#sl-growth').html(`
      ${head}
      <table class="sl-table">
        <thead><tr><th>Grew the most</th><th class="sl-num">Now</th><th class="sl-num">Then</th><th class="sl-num">Change</th><th class="sl-pct"></th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <p class="sl-muted">The folders anywhere below this one where the growth actually happened: each grew by ${formatBytes(g.minDelta)} or more
        that isn't explained by a subfolder already listed. Growth deep inside a folder is listed at the deepest folder, not at each parent.</p>`);
  }

  function showTab(tab) {
    state.tab = tab;
    $root.find('[data-tab]').each(function () {
      $(this).toggleClass('sl-tab-active', $(this).attr('data-tab') === tab);
    });
    $('#sl-contents').prop('hidden', tab !== 'contents');
    $('#sl-largest').prop('hidden', tab !== 'largest');
    $('#sl-growth').prop('hidden', tab !== 'growth');
    if (tab === 'largest') loadLargest();
    if (tab === 'growth') loadGrowth();
  }

  // ---- scheduled scans ----------------------------------------------------

  const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

  function scheduleSummary(s) {
    if (!s) return 'Scheduled scans: off';
    const hour = `${String(s.hour).padStart(2, '0')}:00`;
    const when = s.frequency === 'weekly' ? `every ${WEEKDAYS[s.weekday]} at ${hour}` : `every day at ${hour}`;
    return `Scheduled scans: ${when}${s.alertGb > 0 ? ` · notify if it grows by more than ${s.alertGb} GB` : ''}`;
  }

  function renderSchedule(s) {
    const freq = s ? s.frequency : 'off';
    const hours = Array.from({ length: 24 }, (_, h) =>
      `<option value="${h}"${(s ? s.hour : 3) === h ? ' selected' : ''}>${String(h).padStart(2, '0')}:00</option>`).join('');
    const days = WEEKDAYS.map((d, i) => `<option value="${i}"${(s ? s.weekday : 0) === i ? ' selected' : ''}>${d}</option>`).join('');
    $('#sl-schedule').prop('hidden', false).html(`
      <summary id="sl-schedule-summary">${esc(scheduleSummary(s))}</summary>
      <form class="sl-schedule-form" data-schedule-form>
        <label>Scan
          <select name="frequency">
            <option value="off"${freq === 'off' ? ' selected' : ''}>only when I click Scan</option>
            <option value="daily"${freq === 'daily' ? ' selected' : ''}>every day</option>
            <option value="weekly"${freq === 'weekly' ? ' selected' : ''}>every week</option>
          </select>
        </label>
        <label data-when="weekly">on <select name="weekday">${days}</select></label>
        <label data-when="daily weekly">at <select name="hour">${hours}</select></label>
        <label data-when="daily weekly">Notify me if it grows by more than
          <input type="number" name="alertGb" min="0" step="1" value="${s ? s.alertGb : 0}" class="sl-gb"> GB
          <span class="sl-muted">(0 = never)</span>
        </label>
        <div>
          <button type="submit">Save</button>
          <span class="sl-muted" id="sl-schedule-msg"></span>
        </div>
        <p class="sl-muted">Notifications use Unraid's own notification settings. A scheduled scan of an array disk, or of a share stored on one, spins that disk up.</p>
      </form>`);
    updateScheduleFields();
  }

  function updateScheduleFields() {
    const freq = $('#sl-schedule [name=frequency]').val();
    $('#sl-schedule [data-when]').each(function () {
      $(this).prop('hidden', !$(this).attr('data-when').split(' ').includes(freq));
    });
  }

  function saveSchedule(form) {
    const data = Object.fromEntries(new FormData(form).entries());
    $('#sl-schedule-msg').text('Saving…');
    api('POST', 'schedule', Object.assign({ path: state.target.path }, data))
      .done((res) => {
        $('#sl-schedule-summary').text(scheduleSummary(res.schedule));
        $('#sl-schedule-msg').text('Saved.');
      })
      .fail((xhr) => $('#sl-schedule-msg').text(`Could not save: ${errorText(xhr)}`));
  }

  // ---- events -------------------------------------------------------------

  $root.on('click', '[data-path]', function () {
    selectTarget($(this).attr('data-path'));
  });
  $root.on('click', '[data-dir]', function () {
    loadFolder($(this).attr('data-dir'));
  });
  $root.on('click', '[data-tab]', function () {
    showTab($(this).attr('data-tab'));
  });
  $root.on('click', '[data-sort]', function () {
    const key = $(this).attr('data-sort');
    state.sort = state.sort.key === key
      ? { key, desc: !state.sort.desc }
      : { key, desc: key !== 'name' };
    renderFolder();
  });
  $root.on('change', '#sl-compare', function () {
    state.compare = $(this).val();
    state.growth = null;
    loadFolder(state.dir);
  });
  $root.on('change', '#sl-colour', function () {
    state.colourBy = $(this).val();
    renderTreemap();
  });
  $root.on('change', '#sl-schedule [name=frequency]', updateScheduleFields);
  $root.on('submit', '[data-schedule-form]', function (e) {
    e.preventDefault();
    saveSchedule(this);
  });
  $root.on('click', '[data-action]', function () {
    const action = $(this).attr('data-action');
    if (action === 'back') backToPicker();
    if (action === 'scan') startScan();
    if (action === 'cancel') cancelScan();
  });
  $(window).on('resize', () => state.chart && state.chart.resize());
  $(window).on('beforeunload', stopPolling);

  if (window.echarts) {
    // Clicking a folder in the treemap opens it, just like the table.
    state.chart = window.echarts.init(document.getElementById('sl-treemap'));
    state.chart.on('click', (p) => {
      if (p.data && p.data.dir != null) loadFolder(p.data.dir);
    });
  }

  loadTargets();
})(jQuery);
