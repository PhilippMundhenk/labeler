// Labeler user interface.
import {
  getStatus, getFonts, getLog, getPrinters, setSerial, fontUrl, printLabel, login, logout, getLabels, saveLabel, deleteLabel,
} from './api.js';
import {
  BLOCK_NAMES, FONTS, TAPES, defaultLabel, newBlock, newId, normalizeLabel, pxToMm, tapePx,
} from './label.js';
import { renderLabel, renderHires, drawPreview, thumbnail } from './render.js';
import { encodePng, toBase64 } from './png.js';
import { COLLECTIONS, searchIcons, fetchIcon, iconUrl, parseId } from './icons.js';
import { resolveLabel, usesCounter, usesColumns } from './placeholders.js';
import { parseBatch } from './csv.js';
import { KINDS } from './qrcontent.js';
import { History } from './history.js';
import { TEMPLATES, findTemplate } from './templates.js';
import { LANGUAGES, detectLanguage, setLanguage, language, watch, misses } from './i18n.js';
import { FORMATS } from './barcodes.js';

const $ = (sel, root = document) => root.querySelector(sel);
const STORE_LABEL = 'labeler.label';
const STORE_SAVED = 'labeler.saved';
const STORE_TAPE = 'labeler.tape';
const STORE_BATCH = 'labeler.batch';
const STORE_HISTORY = 'labeler.history';
const STORE_SERIAL = 'labeler.serial';
const STORE_LANG = 'labeler.lang';
const HISTORY_MAX = 20;

const TAPE_COLORS = {
  White: '#fafaf7', 'Matte White': '#f3f3ef', Clear: '#e4ebf0', 'Matte Clear': '#e4ebf0', Red: '#d8342c',
  Blue: '#2160c4', Yellow: '#f5d327', Green: '#2e9d4f', Black: '#1d1d1d', 'Matte Silver': '#b9bdc3',
  'Satin Gold': '#c9a648', 'Satin Silver': '#c4c7cc', 'Fluorescent Orange': '#ff7a1a',
  'Fluorescent Yellow': '#e9f53b', 'Berry Pink': '#d6478a', 'Light Gray': '#cfd2d6', 'Lime Green': '#9ccc3c',
  Pink: '#f4a3c0',
};
const INK_COLORS = { Black: '#111111', White: '#ffffff', Red: '#c62828', Blue: '#1a4fa8', Gold: '#b8902e' };

const ICONS = {
  grip: '<path d="M9 5h2v2H9zm4 0h2v2h-2zM9 11h2v2H9zm4 0h2v2h-2zm-4 6h2v2H9zm4 0h2v2h-2z"/>',
  up: '<path d="M12 5l-7 7h4v7h6v-7h4z"/>',
  down: '<path d="M12 19l7-7h-4V5H9v7H5z"/>',
  copy: '<path d="M8 8h11v11H8zM5 5h11v2H7v9H5z"/>',
  trash: '<path d="M6 7h12l-1 13H7zM9 4h6l1 2H8z"/>',
};
const icon = (name) => `<svg viewBox="0 0 24 24" aria-hidden="true">${ICONS[name]}</svg>`;

const FIELDS = {
  text: [
    { k: 'text', type: 'textarea', label: 'Text', hint: 'Each line of the box is a line on the label. Placeholders: {date}, {time}, {n} (counter).' },
    { k: 'font', type: 'font', label: 'Font' },
    { k: 'size', type: 'number', label: 'Size (pt)', min: 0, max: 200, step: 1, auto: true, hint: 'Empty fits the text to the tape.' },
    { k: 'align', type: 'choice', label: 'Align', options: [['left', 'Left'], ['center', 'Center'], ['right', 'Right']] },
    { k: 'lineSizes', type: 'text', label: 'Line sizes (%)', hint: 'One value per line, e.g. 100, 60 for a smaller second line. Empty: all the same.' },
    { k: 'spacing', type: 'number', label: 'Letter spacing (%)', min: -10, max: 100, step: 5 },
    { type: 'toggles', label: 'Style', items: [['bold', 'Bold'], ['italic', 'Italic'], ['underline', 'Underline'], ['invert', 'White on black']] },
    { k: 'rotate', type: 'choice', label: 'Rotation', options: [[0, '0°'], [90, '90°'], [180, '180°'], [270, '270°']] },
  ],
  qr: [
    { k: 'kind', type: 'select', label: 'Content type', options: KINDS },
    { k: 'content', type: 'textarea', label: 'Content', hint: 'A link or any text.', when: (b) => b.kind === 'text' },
    { k: 'wifiSsid', type: 'text', label: 'Network name (SSID)', when: (b) => b.kind === 'wifi' },
    { k: 'wifiType', type: 'select', label: 'Security', options: [['WPA', 'WPA/WPA2/WPA3'], ['WEP', 'WEP'], ['nopass', 'Open, no password']], when: (b) => b.kind === 'wifi' },
    { k: 'wifiPassword', type: 'text', label: 'Password', when: (b) => b.kind === 'wifi' && b.wifiType !== 'nopass' },
    { type: 'toggles', label: 'Network', items: [['wifiHidden', 'Hidden network']], when: (b) => b.kind === 'wifi' },
    { k: 'contactName', type: 'text', label: 'Name', when: (b) => b.kind === 'contact' },
    { k: 'org', type: 'text', label: 'Organisation', when: (b) => b.kind === 'contact' },
    { k: 'phone', type: 'text', label: 'Phone', when: (b) => b.kind === 'contact' || b.kind === 'phone' },
    { k: 'email', type: 'text', label: 'E-mail', when: (b) => b.kind === 'contact' || b.kind === 'email' },
    { k: 'url', type: 'text', label: 'Website', when: (b) => b.kind === 'contact' },
    { k: 'subject', type: 'text', label: 'Subject', when: (b) => b.kind === 'email' },
    { k: 'level', type: 'choice', label: 'Error correction', options: [['L', 'L 7 %'], ['M', 'M 15 %'], ['Q', 'Q 25 %'], ['H', 'H 30 %']] },
    { k: 'scale', type: 'range', label: 'Height', min: 20, max: 100, unit: '%' },
    { k: 'quiet', type: 'number', label: 'Quiet zone (modules)', min: 0, max: 4, step: 1 },
  ],
  barcode: [
    { k: 'format', type: 'select', label: 'Type', options: FORMATS.map(([v, t]) => [v, t]) },
    { k: 'content', type: 'text', label: 'Content', hint: (b) => FORMATS.find(([v]) => v === b.format)[2] },
    { k: 'bar', type: 'number', label: 'Bar width (px)', min: 1, max: 6, step: 1 },
    { k: 'scale', type: 'range', label: 'Height', min: 20, max: 100, unit: '%' },
    { type: 'toggles', label: 'Options', items: [['text', 'Show text below']] },
    { k: 'rotate', type: 'choice', label: 'Rotation', options: [[0, '0°'], [90, '90°'], [180, '180°'], [270, '270°']] },
  ],
  image: [
    { k: 'src', type: 'file', label: 'Image', hint: 'PNG, JPEG, SVG or GIF. Logos work best in high contrast.' },
    { k: 'mode', type: 'choice', label: 'Conversion', options: [['threshold', 'Black and white'], ['dither', 'Dithered']] },
    { k: 'level', type: 'range', label: 'Threshold', min: 1, max: 254, unit: '' },
    { k: 'scale', type: 'range', label: 'Height', min: 10, max: 100, unit: '%' },
    { type: 'toggles', label: 'Options', items: [['invert', 'Invert']] },
    { k: 'rotate', type: 'choice', label: 'Rotation', options: [[0, '0°'], [90, '90°'], [180, '180°'], [270, '270°']] },
  ],
  icon: [
    { k: 'icon', type: 'icons', label: 'Icon' },
    { k: 'scale', type: 'range', label: 'Height', min: 10, max: 100, unit: '%' },
    { type: 'toggles', label: 'Options', items: [['invert', 'Invert']] },
    { k: 'rotate', type: 'choice', label: 'Rotation', options: [[0, '0°'], [90, '90°'], [180, '180°'], [270, '270°']] },
  ],
  spacer: [{ k: 'width', type: 'number', label: 'Width (mm)', min: 0, max: 500, step: 0.5 }],
};

const state = {
  label: defaultLabel(),
  status: null,
  tape: { mm: 12, px: 76, dpi: 180, tapeColor: 'White', inkColor: 'Black', live: false },
  fonts: { server: [], local: [] },
  bitmap: null,
  errors: {},
  rendering: null,
  dirty: false,
  printing: false,
  batch: { text: '', header: false, on: false, index: 0, rows: [], headers: [] },
};

function load(key, fallback) {
  try {
    const raw = localStorage.getItem(key);
    return raw ? JSON.parse(raw) : fallback;
  } catch {
    return fallback;
  }
}

function store(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value));
    return true;
  } catch {
    return false;
  }
}

/* ---------- printer status ---------- */

async function refreshStatus() {
  const chip = $('#status');
  chip.dataset.state = 'busy';
  $('#status-text').textContent = 'Checking printer…';
  const res = await getStatus();
  state.status = res.ok ? res.data : null;
  const p = state.status && state.status.printer;
  if (p && p.ready) {
    state.tape = {
      mm: p.media_width_mm,
      px: p.tape_px,
      dpi: p.dpi,
      tapeColor: (p.tape_color && p.tape_color.name) || 'White',
      inkColor: (p.text_color && p.text_color.name) || 'Black',
      live: true,
    };
    chip.dataset.state = 'ok';
    state.wasReady = true;
    $('#status-text').textContent = `${p.model || 'Printer'} · ${p.media_width_mm} mm`;
  } else {
    const manual = load(STORE_TAPE, 12);
    state.tape = { mm: manual, px: tapePx(manual, 180), dpi: 180, tapeColor: 'White', inkColor: 'Black', live: false };
    chip.dataset.state = p && p.connected ? 'warn' : 'bad';
    $('#status-text').textContent = p && p.connected ? 'Printer not ready' : 'No printer';
  }
  $('#logout').hidden = !(state.status && state.status.password);
  if (Boolean(state.status && state.status.shared_labels) !== sharedLabels()) savedLoading = refreshSaved();
  let message = res.ok ? (p && !p.ready ? p.message : '') : res.data.error;
  if (p && !p.connected && state.wasReady) {
    // the printer was there a moment ago: P-Touch printers switch themselves off after a while
    message = 'The printer was ready a moment ago and is gone now: it has probably switched itself off (auto power off) or was unplugged. Press its power button, then check again.';
  }
  const notice = $('#printer-notice');
  notice.hidden = !message;
  $('#printer-message').textContent = message || '';
  $('#manual-tape').hidden = state.tape.live;
  $('#tape-select').value = String(state.tape.mm);
  $('#add-icon').hidden = !iconApi();
  updateFeatures();
  scheduleRender();
}

const PATCHED = "needs a ptouch-print with Labeler's patches (see docs/PTOUCH-PRINT.md)";
// print options: element, feature, explanation, what is missing without the feature
const PRINT_OPTIONS = [
  ['chain', 'chain', '', 'needs ptouch-print 1.6 or newer'],
  ['precut', 'precut', 'Cuts the tape automatically; only printers with a cutter (e.g. PT-P700, PT-P750W, PT-P900)', 'needs ptouch-print 1.8 or newer'],
  ['halfcut', 'halfcut', 'Cuts through the tape but not its backing, so the labels stay together; only printers with a half cutter (e.g. PT-P900)', PATCHED],
  ['hires', 'hires', 'Twice the resolution along the tape; only some printers (e.g. PT-P750W, PT-P900)', PATCHED],
  ['cut-every', 'cut_every', 'With several copies: cut only after every n labels, the ones between stay joined', PATCHED],
  ['feed', 'feed', 'Blank tape before and after the label; empty: the printer default (2 mm)', PATCHED],
];

function updateFeatures() {
  const f = (state.status && state.status.features) || {};
  for (const [id, key, help, missing] of PRINT_OPTIONS) {
    const input = $(`#${id}`);
    input.disabled = !f[key];
    input.closest('label').title = f[key] ? help : missing;
    if (!f[key]) {
      input.checked = false;
      if (input.type === 'number') input.value = '';
    }
  }
  const max = (state.status && state.status.max_copies) || 50;
  $('#copies').max = String(max);
  updatePrintButton();
}

/** The options of the patched ptouch-print, as the API expects them; empty fields are left out. */
function extraOptions() {
  const on = (id) => !$(`#${id}`).disabled && $(`#${id}`).checked;
  const number = (id) => ($(`#${id}`).disabled || $(`#${id}`).value === '' ? null : Number($(`#${id}`).value));
  const extra = {};
  if (on('halfcut')) extra.halfcut = true;
  if (on('hires')) extra.hires = true;
  const every = number('cut-every');
  if (every !== null && every >= 1) extra.cut_every = Math.min(99, Math.round(every));
  const mm = number('feed');
  if (mm !== null && mm >= 0) extra.feed = Math.min(1000, Math.round((mm / 25.4) * state.tape.dpi));
  return extra;
}

function updatePrintButton() {
  const btn = $('#print');
  const errors = Object.keys(state.errors).length;
  const empty = !state.bitmap || state.bitmap.countBlack() === 0;
  btn.disabled = state.printing || !state.tape.live || errors > 0 || empty;
  const batch = batchActive() ? `${state.batch.rows.length} labels from the batch` : '';
  const reason = !state.tape.live ? 'No printer ready' : errors ? 'Fix the marked blocks first' : empty ? 'The label is empty' : '';
  btn.title = reason || 'Print (Ctrl+Enter)';
  $('#print-hint').textContent = state.printing ? 'Printing…' : reason || batch;
}

/* ---------- fonts ---------- */

async function loadServerFonts() {
  const res = await getFonts();
  if (!res.ok) return;
  for (const font of res.data.fonts) {
    try {
      const face = new FontFace(font.name, `url("${fontUrl(font.file)}")`);
      document.fonts.add(face);
      face.load().then(() => scheduleRender(), () => {});
      state.fonts.server.push(font.name);
    } catch {
      // an unusable file is skipped
    }
  }
  renderBlocks();
}

async function loadLocalFonts() {
  try {
    const fonts = await window.queryLocalFonts();
    state.fonts.local = [...new Set(fonts.map((f) => f.family))].sort();
    $('#local-fonts').hidden = true;
    renderBlocks();
  } catch {
    $('#local-fonts').textContent = 'Fonts of this device are not available';
    $('#local-fonts').disabled = true;
  }
}

function fontOptions(current) {
  const groups = [
    ['Server', state.fonts.server],
    ['Common', FONTS],
    ['This device', state.fonts.local],
  ];
  const known = groups.some(([, list]) => list.includes(current));
  let html = known ? '' : `<option>${esc(current)}</option>`;
  for (const [name, list] of groups) {
    if (!list.length) continue;
    html += `<optgroup label="${name}">${list.map((f) => `<option data-no-i18n${f === current ? ' selected' : ''}>${esc(f)}</option>`).join('')}</optgroup>`;
  }
  return html;
}

/* ---------- editor ---------- */

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

function fieldHtml(block, f) {
  const id = `${block.id}-${f.k}`;
  const v = block[f.k];
  const hintText = typeof f.hint === 'function' ? f.hint(block) : f.hint;
  const hint = hintText ? `<small class="hint">${esc(hintText)}</small>` : '';
  switch (f.type) {
    case 'textarea':
      return `<label class="field wide" for="${id}">${f.label}<textarea id="${id}" data-k="${f.k}" rows="${f.k === 'text' ? 2 : 2}" spellcheck="false">${esc(v)}</textarea>${hint}</label>`;
    case 'text':
      return `<label class="field wide" for="${id}">${f.label}<input id="${id}" data-k="${f.k}" type="text" value="${esc(v)}" spellcheck="false">${hint}</label>`;
    case 'number': {
      const auto = f.auto ? ' data-auto placeholder="Auto"' : '';
      const value = f.auto && v === 0 ? '' : v;
      return `<label class="field" for="${id}">${f.label}<input id="${id}" data-k="${f.k}" type="number" inputmode="decimal" min="${f.min}" max="${f.max}" step="${f.step}" value="${value}"${auto}>${hint}</label>`;
    }
    case 'range':
      return `<label class="field" for="${id}">${f.label} <output>${v}${f.unit}</output><input id="${id}" data-k="${f.k}" data-unit="${f.unit}" type="range" min="${f.min}" max="${f.max}" value="${v}"></label>`;
    case 'toggles':
      return `<div class="field wide toggles" role="group" aria-label="${f.label}">${f.items.map(([k, text]) =>
        `<label class="toggle"><input type="checkbox" data-k="${k}"${block[k] ? ' checked' : ''}><span>${text}</span></label>`).join('')}</div>`;
    case 'choice':
      return `<fieldset class="field segmented"><legend>${f.label}</legend>${f.options.map(([val, text]) =>
        `<label><input type="radio" name="${id}" data-k="${f.k}" value="${val}"${String(val) === String(v) ? ' checked' : ''}><span>${text}</span></label>`).join('')}</fieldset>`;
    case 'select':
      // label beside, not around, the select: otherwise its name includes every option
      return `<div class="field"><label for="${id}">${f.label}</label><select id="${id}" data-k="${f.k}" data-rerender>${f.options.map(([val, text]) =>
        `<option value="${val}"${val === v ? ' selected' : ''}>${esc(text)}</option>`).join('')}</select></div>`;
    case 'font':
      return `<div class="field"><label for="${id}">${f.label}</label><select id="${id}" data-k="${f.k}">${fontOptions(v)}</select></div>`;
    case 'icons':
      return pickerHtml(block);
    default: // file
      return `<label class="field wide" for="${id}">${f.label}<input id="${id}" data-k="${f.k}" type="file" accept="image/*">${block.name ? `<small class="hint">${esc(block.name)}</small>` : hint}</label>`;
  }
}

/* ---------- icon picker ---------- */

const pickers = new Map(); // block id => { query, set, results, message }
let searchTimer = null;

const iconApi = () => (state.status && state.status.icon_api) || '';

function picker(id) {
  if (!pickers.has(id)) pickers.set(id, { query: '', set: 'all', results: [], message: '' });
  return pickers.get(id);
}

function resultsHtml(block) {
  const p = picker(block.id);
  const api = iconApi();
  const buttons = p.results.map((id) => `<button type="button" class="icon-result${id === block.icon ? ' selected' : ''}" data-icon-pick="${esc(id)}" title="${esc(id)}" aria-label="${esc(id)}"><img src="${esc(iconUrl(api, id, 32))}" alt="" width="32" height="32" loading="lazy"></button>`).join('');
  return `${buttons}${p.message ? `<p class="muted small">${esc(p.message)}</p>` : ''}`;
}

function pickerHtml(block) {
  const p = picker(block.id);
  const sets = [['all', 'All'], ...Object.entries(COLLECTIONS).map(([k, c]) => [k, c.short])];
  const chosen = parseId(block.icon);
  return `<div class="field wide icon-picker">
    <input type="search" id="${block.id}-icon-query" data-icon-query value="${esc(p.query)}" placeholder="Search icons: wifi, kitchen, github…" aria-label="Search icons" autocomplete="off">
    <fieldset class="segmented"><legend class="sr-only">Icon collection</legend>${sets.map(([v, t]) =>
      `<label><input type="radio" name="${block.id}-icon-set" data-icon-set value="${v}"${v === p.set ? ' checked' : ''}><span>${esc(t)}</span></label>`).join('')}</fieldset>
    <div class="icon-results" data-icon-results>${resultsHtml(block)}</div>
    <small class="hint">${chosen ? `${esc(block.icon)} · ${esc(COLLECTIONS[chosen.prefix].name)}, ${esc(COLLECTIONS[chosen.prefix].license)}` : 'Icons come from the Iconify API and are stored in the label.'}</small>
  </div>`;
}

function updateResults(block) {
  const box = $(`.block[data-id="${block.id}"] [data-icon-results]`);
  if (box) box.innerHTML = resultsHtml(block);
}

async function runSearch(block) {
  const p = picker(block.id);
  const query = p.query;
  try {
    const results = await searchIcons(iconApi(), query, p.set);
    if (query !== p.query) return; // a newer search is on its way
    p.results = results;
    p.message = query.trim().length < 2 ? '' : results.length ? '' : 'No icons found.';
  } catch (err) {
    p.results = [];
    p.message = err.message;
  }
  updateResults(block);
}

function scheduleSearch(block) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => runSearch(block), 250);
}

async function pickIcon(block, id) {
  persist();
  try {
    block.src = await fetchIcon(iconApi(), id);
    block.icon = id;
    delete state.errors[block.id];
  } catch (err) {
    picker(block.id).message = err.message;
  }
  renderBlocks();
  commit();
}

function renderBlocks() {
  const list = $('#blocks');
  const focused = document.activeElement && document.activeElement.id;
  list.innerHTML = state.label.blocks.map((b, i) => `
    <li class="block" data-id="${b.id}" data-type="${b.type}">
      <div class="block-head">
        <span class="grip" draggable="true" title="Drag to move" aria-hidden="true">${icon('grip')}</span>
        <span class="block-title">${BLOCK_NAMES[b.type]}</span>
        <span class="tools">
          <button type="button" class="icon" data-act="up" aria-label="Move ${BLOCK_NAMES[b.type]} up"${i === 0 ? ' disabled' : ''}>${icon('up')}</button>
          <button type="button" class="icon" data-act="down" aria-label="Move ${BLOCK_NAMES[b.type]} down"${i === state.label.blocks.length - 1 ? ' disabled' : ''}>${icon('down')}</button>
          <button type="button" class="icon" data-act="copy" aria-label="Duplicate ${BLOCK_NAMES[b.type]}">${icon('copy')}</button>
          <button type="button" class="icon" data-act="delete" aria-label="Remove ${BLOCK_NAMES[b.type]}">${icon('trash')}</button>
        </span>
      </div>
      <p class="block-error" role="alert"></p>
      <div class="fields">${FIELDS[b.type].filter((f) => !f.when || f.when(b)).map((f) => fieldHtml(b, f)).join('')}</div>
    </li>`).join('');
  $('#empty-hint').hidden = state.label.blocks.length > 0;
  if (focused && document.getElementById(focused)) document.getElementById(focused).focus();
  showErrors();
}

function blockOf(el) {
  const li = el.closest('.block');
  return li && state.label.blocks.find((b) => b.id === li.dataset.id);
}

function onBlockInput(e) {
  const el = e.target;
  const block = blockOf(el);
  if (block && 'iconQuery' in el.dataset) {
    picker(block.id).query = el.value;
    scheduleSearch(block);
    return;
  }
  if (!block || !el.dataset.k) return;
  const k = el.dataset.k;
  if (el.type === 'file') return;
  if (el.type === 'checkbox') block[k] = el.checked;
  else if (el.type === 'number' || el.type === 'range') {
    const auto = 'auto' in el.dataset;
    const n = el.value === '' && auto ? 0 : Number(el.value);
    if ((el.value === '' && !auto) || !Number.isFinite(n)) return;
    block[k] = n;
    const out = el.parentElement.querySelector('output');
    if (out) out.textContent = `${n}${el.dataset.unit}`;
  } else block[k] = el.value;
  changed();
}

function readImage(file, maxHeight) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error('the file cannot be read'));
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error('this is not an image the browser can show'));
      img.onload = () => {
        // keep at most twice the tallest tape, so the label still fits into browser storage
        const scale = Math.min(1, maxHeight / (img.naturalHeight || maxHeight));
        const w = Math.max(1, Math.round((img.naturalWidth || maxHeight) * scale));
        const h = Math.max(1, Math.round((img.naturalHeight || maxHeight) * scale));
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
        resolve(canvas.toDataURL('image/png'));
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  });
}

async function onBlockChange(e) {
  const el = e.target;
  const block = blockOf(el);
  if (!block) return;
  if (el.type === 'file' && el.files[0]) {
    const file = el.files[0];
    try {
      block.src = await readImage(file, 908);
      block.name = file.name;
      delete state.errors[block.id];
    } catch (err) {
      state.errors[block.id] = err.message;
    }
    renderBlocks();
    commit();
    return;
  }
  if ('iconSet' in el.dataset) {
    picker(block.id).set = el.value;
    runSearch(block);
    return;
  }
  if (el.type === 'radio' || el.tagName === 'SELECT') {
    block[el.dataset.k] = el.dataset.k === 'rotate' ? Number(el.value) : el.value;
    if ('rerender' in el.dataset) renderBlocks();
    commit();
  }
}

function onBlockClick(e) {
  const pick = e.target.closest('[data-icon-pick]');
  if (pick) {
    pickIcon(blockOf(pick), pick.dataset.iconPick);
    return;
  }
  const btn = e.target.closest('button[data-act]');
  if (!btn) return;
  persist();
  const blocks = state.label.blocks;
  const i = blocks.findIndex((b) => b.id === btn.closest('.block').dataset.id);
  const act = btn.dataset.act;
  let focusId = blocks[i].id;
  if (act === 'delete') blocks.splice(i, 1);
  if (act === 'copy') {
    focusId = newId();
    blocks.splice(i + 1, 0, { ...blocks[i], id: focusId });
  }
  if (act === 'up' && i > 0) [blocks[i - 1], blocks[i]] = [blocks[i], blocks[i - 1]];
  if (act === 'down' && i < blocks.length - 1) [blocks[i + 1], blocks[i]] = [blocks[i], blocks[i + 1]];
  renderBlocks();
  commit();
  // keep the keyboard focus on the block that was acted on
  const target = act === 'delete' ? $('#add-text') : $(`.block[data-id="${focusId}"] [data-act="${act}"]`);
  if (target && !target.disabled) target.focus();
  else if (target) $(`.block[data-id="${focusId}"] [data-act="copy"]`).focus();
}

function addBlock(type) {
  persist(); // pending typing becomes its own undo step
  const block = newBlock(type);
  state.label.blocks.push(block);
  renderBlocks();
  commit();
  const first = document.querySelector(`.block[data-id="${block.id}"] textarea, .block[data-id="${block.id}"] input`);
  if (first) first.focus();
}

/* ---------- drag and drop ---------- */

let dragged = null;

function onDragStart(e) {
  const li = e.target.closest && e.target.closest('.grip') && e.target.closest('.block');
  if (!li) return;
  dragged = li.dataset.id;
  li.classList.add('dragging');
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', dragged);
  e.dataTransfer.setDragImage(li, 20, 20);
}

function onDragOver(e) {
  const li = dragged && e.target.closest('.block');
  if (!li) return;
  e.preventDefault();
  for (const other of document.querySelectorAll('.block.drop-target')) other.classList.remove('drop-target');
  if (li.dataset.id !== dragged) li.classList.add('drop-target');
}

function onDrop(e) {
  const li = dragged && e.target.closest('.block');
  if (!li) return;
  e.preventDefault();
  const blocks = state.label.blocks;
  const from = blocks.findIndex((b) => b.id === dragged);
  const to = blocks.findIndex((b) => b.id === li.dataset.id);
  dragged = null;
  if (from === to) {
    renderBlocks();
    return;
  }
  persist();
  const [moved] = blocks.splice(from, 1);
  blocks.splice(to, 0, moved);
  renderBlocks();
  commit();
}

function onDragEnd() {
  dragged = null;
  for (const el of document.querySelectorAll('.block.dragging, .block.drop-target')) el.classList.remove('dragging', 'drop-target');
}

const hasFiles = (e) => e.dataTransfer && [...e.dataTransfer.types].includes('Files');

/** Image files dropped anywhere on the page become image blocks. */
async function onFileDrop(e) {
  if (!hasFiles(e)) return;
  e.preventDefault();
  $('#drop-overlay').hidden = true;
  const files = [...e.dataTransfer.files].filter((f) => f.type.startsWith('image/'));
  if (!files.length) {
    toast('Only image files can be dropped here', 'warn');
    return;
  }
  persist();
  for (const file of files) {
    const block = newBlock('image');
    try {
      block.src = await readImage(file, 908);
      block.name = file.name;
      state.label.blocks.push(block);
    } catch (err) {
      toast(`${file.name}: ${err.message}`, 'bad');
    }
  }
  renderBlocks();
  commit();
}

/* ---------- layout settings ---------- */

function renderSettings() {
  const l = state.label;
  $('#margin').value = l.margin;
  $('#gap').value = l.gap;
  $('#length').value = l.length || '';
  $('#frame').checked = l.frame;
  $('#frame-width').value = l.frameWidth;
  $('#frame-width').disabled = !l.frame;
  $('#mirror').checked = l.mirror;
  $('#cut-marks').checked = l.cutMarks;
  $('#flag').checked = l.flag;
  $('#flag-diameter').value = l.flagDiameter;
  $('#flag-diameter').disabled = !l.flag;
  $('#counter').value = l.counter;
  for (const r of document.querySelectorAll('input[name="label-align"]')) r.checked = r.value === l.align;
}

function onSettingsInput(e) {
  const el = e.target;
  const l = state.label;
  if (el.name === 'label-align') l.align = el.value;
  else if (el.id === 'frame') {
    l.frame = el.checked;
    $('#frame-width').disabled = !l.frame;
  }
  else if (el.id === 'frame-width') l.frameWidth = Number(el.value) || 1;
  else if (el.id === 'mirror') l.mirror = el.checked;
  else if (el.id === 'cut-marks') l.cutMarks = el.checked;
  else if (el.id === 'flag') {
    l.flag = el.checked;
    $('#flag-diameter').disabled = !l.flag;
  } else if (el.id === 'flag-diameter') {
    if (!(Number(el.value) > 0)) return;
    l.flagDiameter = Number(el.value);
  }
  else if (el.id === 'length' && el.value === '') l.length = 0;
  else if (['margin', 'gap', 'length'].includes(el.id)) {
    if (el.value === '' || !Number.isFinite(Number(el.value))) return;
    l[el.id] = Number(el.value);
  } else return;
  changed();
}

/* ---------- saved labels ---------- */

/* ---------- print history ---------- */

function recordPrint(labels) {
  const entry = {
    time: new Date().toISOString(),
    labels,
    label: JSON.parse(JSON.stringify(state.label)),
    thumb: state.bitmap ? thumbnail(state.bitmap) : '',
  };
  let list = [entry, ...load(STORE_HISTORY, [])].slice(0, HISTORY_MAX);
  // labels with big images may not all fit into the browser storage: drop the oldest
  while (list.length && !store(STORE_HISTORY, list)) list = list.slice(0, -1);
  renderHistory();
}

function renderHistory() {
  const list = load(STORE_HISTORY, []);
  $('#history-list').innerHTML = list.length
    ? list.map((e, i) => `<li>
        ${e.thumb ? `<img src="${esc(e.thumb)}" alt="">` : ''}
        <span class="when">${esc(new Date(e.time).toLocaleString())} · ${e.labels} ${e.labels === 1 ? 'label' : 'labels'}</span>
        <span class="actions">
          <button type="button" class="link" data-history-load="${i}">Load</button>
          <button type="button" class="link" data-history-print="${i}">Print again</button>
        </span></li>`).join('')
    : '<li class="muted">Nothing printed in this browser yet.</li>';
}

function onHistoryClick(e) {
  const btn = e.target.closest('[data-history-load], [data-history-print]');
  if (!btn) return;
  const entry = load(STORE_HISTORY, [])[Number(btn.dataset.historyLoad ?? btn.dataset.historyPrint)];
  if (!entry) return;
  persist();
  state.label = normalizeLabel(entry.label);
  renderSettings();
  renderBlocks();
  commit();
  if ('historyPrint' in btn.dataset) {
    render().then(() => onPrint());
  }
}

// Saved labels live on the server when its data folder allows it (shared by every browser),
// otherwise in this browser.
let serverLabels = null;
// the first status and the server's list: until both are known, saving would pick the wrong place
let statusLoading = Promise.resolve();
let savedLoading = Promise.resolve();
const sharedLabels = () => serverLabels !== null;

function savedLabels() {
  if (!sharedLabels()) return load(STORE_SAVED, {});
  return Object.fromEntries(Object.entries(serverLabels).map(([name, entry]) => [name, entry.label]));
}

async function refreshSaved() {
  serverLabels = null;
  if (state.status && state.status.shared_labels) {
    const res = await getLabels();
    if (res.ok && res.data.enabled) serverLabels = res.data.labels;
  }
  renderSaved();
}

function renderSaved() {
  const saved = savedLabels();
  const local = Object.keys(load(STORE_SAVED, {}));
  $('#saved-where').textContent = sharedLabels() ? 'Saved on the server, for every browser.' : 'Saved in this browser.';
  $('#saved-local').hidden = !(sharedLabels() && local.length);
  $('#saved-local span').textContent = `${local.length} ${local.length === 1 ? 'label is' : 'labels are'} saved only in this browser.`;
  const names = Object.keys(saved).sort((a, b) => a.localeCompare(b));
  $('#saved-list').innerHTML = names.length
    ? names.map((n) => `<li><button type="button" class="link" data-load="${esc(n)}" data-no-i18n>${esc(n)}</button><button type="button" class="icon" data-remove="${esc(n)}" aria-label="Delete saved label ${esc(n)}">${icon('trash')}</button></li>`).join('')
    : '<li class="muted">No saved labels yet.</li>';
}

async function onSave(e) {
  e.preventDefault();
  const name = $('#save-name').value.trim();
  if (!name) return;
  await statusLoading;
  await savedLoading;
  if (sharedLabels()) {
    const res = await saveLabel(name, state.label);
    if (!res.ok) {
      toast(`Not saved: ${res.data.error}`, 'bad');
      return;
    }
    $('#save-name').value = '';
    await refreshSaved();
    toast(`Saved "${name}"`, 'ok');
    return;
  }
  const saved = load(STORE_SAVED, {});
  saved[name] = state.label;
  if (!store(STORE_SAVED, saved)) {
    toast('Not saved: the browser storage is full (large images?)', 'bad');
    return;
  }
  $('#save-name').value = '';
  renderSaved();
  toast(`Saved "${name}"`, 'ok');
}

async function copyLocalLabels() {
  const local = load(STORE_SAVED, {});
  for (const [name, label] of Object.entries(local)) {
    const res = await saveLabel(name, label);
    if (!res.ok) {
      toast(`"${name}" not copied: ${res.data.error}`, 'bad');
      return;
    }
    delete local[name];
    store(STORE_SAVED, local);
  }
  await refreshSaved();
  toast('Copied to the server', 'ok');
}

async function onSavedClick(e) {
  const saved = savedLabels();
  const loadName = e.target.closest('[data-load]')?.dataset.load;
  const removeName = e.target.closest('[data-remove]')?.dataset.remove;
  if (loadName && saved[loadName]) {
    persist();
    state.label = normalizeLabel(saved[loadName]);
    renderSettings();
    renderBlocks();
    commit();
    toast(`Loaded "${loadName}"`, 'ok');
  }
  if (removeName && sharedLabels()) {
    await deleteLabel(removeName);
    await refreshSaved();
  } else if (removeName) {
    delete saved[removeName];
    store(STORE_SAVED, saved);
    renderSaved();
  }
}

/* ---------- preview and print ---------- */

let renderTimer = null;
let persistTimer = null;

function changed() {
  if (state.batch.on) updateBatch(false);
  scheduleRender();
  clearTimeout(persistTimer);
  persistTimer = setTimeout(persist, 300);
}

function persist() {
  clearTimeout(persistTimer);
  if (history.push(JSON.stringify(state.label))) updateUndo();
  if (!store(STORE_LABEL, state.label)) toast('The label is too large to be kept in this browser', 'warn');
}

/** A change that is one step in the history right away (not merged with typing). */
function commit() {
  changed();
  persist();
}

/* ---------- undo and redo ---------- */

const history = new History(100);

function updateUndo() {
  $('#undo').disabled = !history.canUndo;
  $('#redo').disabled = !history.canRedo;
}

function applySnapshot(snapshot) {
  state.label = normalizeLabel(JSON.parse(snapshot));
  renderSettings();
  renderBlocks();
  scheduleRender();
  store(STORE_LABEL, state.label);
  updateUndo();
}

function undo() {
  persist(); // typing that is not saved yet becomes a step first
  const snapshot = history.undo();
  if (snapshot) applySnapshot(snapshot);
}

function redo() {
  const snapshot = history.redo();
  if (snapshot) applySnapshot(snapshot);
}

function scheduleRender() {
  clearTimeout(renderTimer);
  renderTimer = setTimeout(render, 40);
}

async function render() {
  if (state.rendering) {
    state.dirty = true;
    return;
  }
  state.rendering = (async () => {
    const { bitmap, errors } = await renderLabel(resolveLabel(state.label, previewContext()), state.tape);
    state.bitmap = bitmap;
    state.errors = errors;
    const colors = { tape: TAPE_COLORS[state.tape.tapeColor] || TAPE_COLORS.White, ink: INK_COLORS[state.tape.inkColor] || INK_COLORS.Black };
    drawPreview($('#preview'), bitmap, state.tape, colors);
    $('#preview').dataset.width = bitmap.width;
    $('#preview').dataset.height = bitmap.height;
    $('#tape-info').textContent = `${state.tape.mm} mm tape · ${state.tape.px} px at ${state.tape.dpi} dpi`;
    $('#length-info').textContent = `${pxToMm(bitmap.width, state.tape.dpi)} mm long`;
    showErrors();
    updatePrintButton();
  })();
  try {
    await state.rendering;
  } finally {
    state.rendering = null;
  }
  if (state.dirty) {
    state.dirty = false;
    render();
  }
}

function showErrors() {
  for (const li of document.querySelectorAll('.block')) {
    const message = state.errors[li.dataset.id] || '';
    li.classList.toggle('has-error', Boolean(message));
    $('.block-error', li).textContent = message;
  }
}

async function labelPng() {
  return encodePng(state.bitmap, undefined, state.tape.dpi);
}

/* ---------- batch ---------- */

const batchActive = () => state.batch.on && state.batch.rows.length > 0;

function updateBatch(persist = true) {
  const b = state.batch;
  const parsed = parseBatch(b.text, b.header);
  b.rows = parsed.rows;
  b.headers = parsed.headers;
  b.index = Math.min(b.index, Math.max(0, b.rows.length - 1));
  const cols = b.headers.length ? b.headers.map((h) => `{${h}}`).join(', ')
    : b.rows.length ? Array.from({ length: Math.max(...b.rows.map((r) => r.length)) }, (_, i) => `{${i + 1}}`).join(', ') : '';
  let info = b.rows.length ? `${b.rows.length} ${b.rows.length === 1 ? 'row' : 'rows'} · ${cols}` : 'No rows yet.';
  if (b.on && b.rows.length && !usesColumns(state.label, b.headers)) info += ' · No block uses these placeholders yet, so all labels would look the same.';
  $('#batch-info').textContent = info;
  $('#batch-nav').hidden = !batchActive();
  $('#batch-pos').textContent = `Row ${b.index + 1} of ${b.rows.length}`;
  $('#batch-prev').disabled = b.index === 0;
  $('#batch-next').disabled = b.index >= b.rows.length - 1;
  if (persist) store(STORE_BATCH, { text: b.text, header: b.header, on: b.on });
  scheduleRender();
}

function initBatch() {
  const saved = load(STORE_BATCH, {}) || {};
  Object.assign(state.batch, {
    text: typeof saved.text === 'string' ? saved.text : '',
    header: Boolean(saved.header),
    on: Boolean(saved.on),
  });
  $('#batch-data').value = state.batch.text;
  $('#batch-header').checked = state.batch.header;
  $('#batch-on').checked = state.batch.on;
  $('#batch-data').addEventListener('input', (e) => {
    state.batch.text = e.target.value;
    updateBatch();
  });
  $('#batch-header').addEventListener('change', (e) => {
    state.batch.header = e.target.checked;
    updateBatch();
  });
  $('#batch-on').addEventListener('change', (e) => {
    state.batch.on = e.target.checked;
    updateBatch();
  });
  $('#batch-file').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    state.batch.text = await file.text();
    $('#batch-data').value = state.batch.text;
    state.batch.on = true;
    $('#batch-on').checked = true;
    updateBatch();
  });
  $('#batch-prev').addEventListener('click', () => {
    state.batch.index -= 1;
    updateBatch(false);
  });
  $('#batch-next').addEventListener('click', () => {
    state.batch.index += 1;
    updateBatch(false);
  });
  updateBatch(false);
}

function previewContext() {
  const b = state.batch;
  return batchActive() ? { n: state.label.counter, row: b.rows[b.index], headers: b.headers } : { n: state.label.counter };
}

/**
 * The print jobs for the current label: one job with n copies, or one job per copy when the
 * label contains the counter {n}, so every copy gets its own number.
 */
function printJobs(copies) {
  const label = state.label;
  const b = state.batch;
  const rows = batchActive() ? b.rows : [null];
  const counter = usesCounter(label);
  const jobs = [];
  let n = label.counter;
  for (const row of rows) {
    const base = row ? { row, headers: b.headers } : {};
    if (!counter) jobs.push({ ctx: { ...base, n }, copies });
    else for (let c = 0; c < copies; c++) jobs.push({ ctx: { ...base, n: n++ }, copies: 1, counts: true });
  }
  return jobs;
}

async function onPrint() {
  if ($('#print').disabled) return;
  state.printing = true;
  updatePrintButton();
  $('#print').classList.add('busy');
  try {
    await (state.rendering || Promise.resolve());
    const jobs = printJobs(Number($('#copies').value) || 1);
    let labels = 0;
    let mm = 0;
    for (let i = 0; i < jobs.length; i++) {
      const job = jobs[i];
      if (jobs.length > 1) $('#print-hint').textContent = `Printing ${i + 1} of ${jobs.length}…`;
      const extra = extraOptions();
      const draw = extra.hires ? renderHires : renderLabel;
      const { bitmap, errors } = await draw(resolveLabel(state.label, job.ctx), state.tape);
      const problem = Object.values(errors)[0];
      if (problem || !bitmap.countBlack()) {
        toast(`Label ${i + 1} cannot be printed: ${problem || 'it is empty'}`, 'bad');
        break;
      }
      const res = await printLabel(toBase64(await encodePng(bitmap, undefined, state.tape.dpi)), {
        copies: job.copies,
        chain: $('#chain').checked || i < jobs.length - 1,
        precut: $('#precut').checked,
        ...extra,
      });
      if (!res.ok) {
        toast(res.data.error || 'Printing failed', 'bad', res.data.output);
        if (res.status === 503) refreshStatus();
        break;
      }
      labels += res.data.copies;
      mm += res.data.length_mm * res.data.copies;
      if (job.counts) state.label.counter += 1;
      if (i === jobs.length - 1) for (const note of res.data.notes || []) toast(note, 'warn');
    }
    if (labels) {
      toast(`${labels > 1 ? `${labels} labels` : 'Label'} printed · ${Math.round(mm * 10) / 10} mm`, 'ok');
      recordPrint(labels);
      renderSettings();
      changed();
    }
  } finally {
    state.printing = false;
    $('#print').classList.remove('busy');
    updatePrintButton();
  }
}

async function onDownload() {
  const png = await labelPng();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([png], { type: 'image/png' }));
  a.download = 'label.png';
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

function toast(message, kind = 'ok', details = '') {
  const box = $('#toasts');
  const el = document.createElement('div');
  el.className = `toast ${kind}`;
  el.textContent = message;
  el.title = 'Click to close';
  el.addEventListener('click', () => el.remove());
  if (details) {
    const pre = document.createElement('pre');
    pre.textContent = details;
    el.append(pre);
  }
  box.append(el);
  setTimeout(() => el.remove(), kind === 'bad' ? 12000 : 4000);
}

/* ---------- details dialog ---------- */

async function renderPrinterChoice() {
  const choice = $('#printer-choice');
  const res = await getPrinters();
  const printers = res.ok ? res.data.printers || [] : [];
  choice.hidden = printers.length < 2;
  if (choice.hidden) return;
  const current = load(STORE_SERIAL, '');
  $('#printer-select').innerHTML = printers.map((p) =>
    `<option value="${esc(p.serial)}"${p.serial === current ? ' selected' : ''}${p.usable ? '' : ' disabled'}>${esc(p.model)} · ${esc(p.serial)}${p.usable ? '' : ' (not usable)'}</option>`).join('');
  if (!printers.some((p) => p.serial === current)) $('#printer-select').selectedIndex = -1;
}

async function openDetails() {
  const dialog = $('#details');
  renderPrinterChoice();
  const s = state.status;
  const p = s && s.printer;
  const rows = [
    ['Labeler', s ? s.app_version : '?'],
    ['ptouch-print', s && s.ptouch_version ? s.ptouch_version : 'not found'],
    ['Printer agent', s && s.agent ? `${s.agent.url}${s.agent.reachable ? '' : ' (not reachable)'}` : 'none, this computer prints'],
    ['Printer', p && p.model ? p.model : 'none'],
    ['Serial', p && p.serial ? p.serial : '–'],
    ['Tape', p && p.media_width_mm ? `${p.media_width_mm} mm, ${p.tape_px} px, ${p.dpi} dpi` : '–'],
    ['Colours', p && p.tape_color ? `${p.text_color ? p.text_color.name : '?'} on ${p.tape_color.name}` : '–'],
    ['Status', p ? (p.ready ? 'ready' : p.message) : 'server not reachable'],
  ];
  $('#details-info').innerHTML = rows.map(([k, v]) => `<dt>${k}</dt><dd>${esc(v)}</dd>`).join('');
  const res = await getLog();
  const entries = res.ok ? res.data.entries : [];
  $('#details-log').innerHTML = entries.length
    ? entries.map((e) => `<li class="${e.code === 0 ? '' : 'failed'}"><div><time>${esc(new Date(e.time).toLocaleString())}</time> · exit ${e.code} · ${e.ms} ms</div><code>ptouch-print ${esc(e.args.join(' '))}</code><pre>${esc(e.output)}</pre></li>`).join('')
    : `<li class="muted">${res.ok && !res.data.enabled ? 'The print log is off: the data folder is not writable.' : 'Nothing printed yet.'}</li>`;
  dialog.showModal();
}

/* ---------- start ---------- */

function initLanguage() {
  setLanguage(detectLanguage(load(STORE_LANG, ''), navigator.languages || [navigator.language]));
  $('#lang').innerHTML = LANGUAGES.map(([code, name]) => `<option value="${code}"${code === language() ? ' selected' : ''}>${name}</option>`).join('');
  $('#lang').addEventListener('change', (e) => {
    store(STORE_LANG, e.target.value);
    location.reload();
  });
  watch(document.body);
}

function init() {
  initLanguage();
  state.label = normalizeLabel(load(STORE_LABEL, null) || defaultLabel());

  const select = $('#tape-select');
  select.innerHTML = Object.keys(TAPES).map((mm) => `<option value="${mm}">${mm} mm</option>`).join('');
  select.addEventListener('change', () => {
    store(STORE_TAPE, Number(select.value));
    state.tape = { ...state.tape, mm: Number(select.value), px: tapePx(Number(select.value), 180) };
    scheduleRender();
  });

  const blocks = $('#blocks');
  blocks.addEventListener('input', onBlockInput);
  blocks.addEventListener('change', onBlockChange);
  blocks.addEventListener('click', onBlockClick);
  blocks.addEventListener('dragstart', onDragStart);
  blocks.addEventListener('dragover', onDragOver);
  blocks.addEventListener('drop', onDrop);
  blocks.addEventListener('dragend', onDragEnd);
  document.addEventListener('dragover', (e) => {
    if (!hasFiles(e)) return;
    e.preventDefault();
    $('#drop-overlay').hidden = false;
  });
  document.addEventListener('dragleave', (e) => {
    if (e.relatedTarget === null) $('#drop-overlay').hidden = true;
  });
  document.addEventListener('drop', onFileDrop);
  for (const btn of document.querySelectorAll('[data-add]')) btn.addEventListener('click', () => addBlock(btn.dataset.add));
  $('#settings').addEventListener('input', onSettingsInput);
  $('#counter').addEventListener('input', (e) => {
    const n = Number(e.target.value);
    if (e.target.value === '' || !Number.isInteger(n) || n < 0) return;
    state.label.counter = n;
    changed();
  });
  $('#save-form').addEventListener('submit', onSave);
  $('#saved-list').addEventListener('click', onSavedClick);
  $('#history-list').addEventListener('click', onHistoryClick);
  $('#copy-local').addEventListener('click', copyLocalLabels);
  $('#print').addEventListener('click', onPrint);
  $('#download').addEventListener('click', onDownload);
  $('#status').addEventListener('click', openDetails);
  $('#retry').addEventListener('click', refreshStatus);
  $('#details-refresh').addEventListener('click', async () => {
    await refreshStatus();
    $('#details').close();
    openDetails();
  });
  $('#details-close').addEventListener('click', () => $('#details').close());
  window.addEventListener('labeler:login', () => {
    if (!$('#login-dialog').open) $('#login-dialog').showModal();
  });
  $('#login-dialog').addEventListener('cancel', (e) => e.preventDefault());
  $('#login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const res = await login($('#login-password').value);
    if (!res.ok) {
      $('#login-error').textContent = res.data.error;
      return;
    }
    $('#login-error').textContent = '';
    $('#login-password').value = '';
    $('#login-dialog').close();
    await refreshStatus();
    loadServerFonts();
  });
  $('#logout').addEventListener('click', async () => {
    await logout();
    location.reload();
  });
  setSerial(load(STORE_SERIAL, ''));
  $('#printer-select').addEventListener('change', async (e) => {
    store(STORE_SERIAL, e.target.value);
    setSerial(e.target.value);
    await refreshStatus();
    $('#details').close();
    openDetails();
  });
  $('#clear').addEventListener('click', () => {
    persist();
    state.label = defaultLabel();
    renderSettings();
    renderBlocks();
    commit();
  });
  if ('queryLocalFonts' in window) {
    $('#local-fonts').hidden = false;
    $('#local-fonts').addEventListener('click', loadLocalFonts);
  }
  $('#template-list').innerHTML = TEMPLATES.map((t) =>
    `<li><button type="button" data-template="${t.id}"><strong>${esc(t.name)}</strong><span>${esc(t.description)}</span></button></li>`).join('');
  $('#templates').addEventListener('click', () => $('#template-dialog').showModal());
  $('#template-close').addEventListener('click', () => $('#template-dialog').close());
  $('#template-list').addEventListener('click', (e) => {
    const t = findTemplate(e.target.closest('[data-template]')?.dataset.template);
    if (!t) return;
    persist();
    state.label = normalizeLabel(JSON.parse(JSON.stringify(t.label)));
    $('#template-dialog').close();
    renderSettings();
    renderBlocks();
    commit();
    toast(`Template "${t.name}"`, 'ok');
  });
  $('#undo').addEventListener('click', undo);
  $('#redo').addEventListener('click', redo);
  document.addEventListener('keydown', (e) => {
    // inside text fields Ctrl+Z stays the browser's own text undo
    const typing = e.target.closest && e.target.closest('input[type="text"], input[type="search"], input[type="number"], textarea');
    const key = e.key.toLowerCase();
    if ((e.ctrlKey || e.metaKey) && !typing && (key === 'z' || key === 'y')) {
      e.preventDefault();
      if (key === 'y' || e.shiftKey) redo();
      else undo();
    }
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      onPrint();
    }
  });
  window.addEventListener('resize', scheduleRender);
  // quick edits keep postponing the delayed save; save at once when the page goes away
  window.addEventListener('pagehide', persist);
  // keep focused and scrolled-to elements out from under the sticky preview
  const card = $('.preview-card');
  new ResizeObserver(() => {
    const sticky = getComputedStyle(card).position === 'sticky';
    document.documentElement.style.scrollPaddingTop = sticky ? `${Math.round(card.getBoundingClientRect().height + 72)}px` : '';
  }).observe(card);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refreshStatus();
  });

  history.reset(JSON.stringify(state.label));
  updateUndo();
  // offline app shell; browsers only allow service workers on HTTPS and localhost
  if ('serviceWorker' in navigator && window.isSecureContext) navigator.serviceWorker.register('sw.js').catch(() => {});
  renderSettings();
  renderBlocks();
  renderSaved();
  renderHistory();
  initBatch();
  statusLoading = refreshStatus();
  loadServerFonts();
}

init();

// for the browser tests
window.labeler = { state, render: () => render(), labelPng, i18nMisses: misses };
