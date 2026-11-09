// Translations. The English text is the key: the page is written in English and a
// MutationObserver translates text nodes and a few attributes as they appear, so new strings
// only need a dictionary entry. Keys with {placeholders} match texts with numbers or names in
// them. Elements inside [data-no-i18n] (label names, fonts, user content) are left alone.
// Each language is a file i18n-<code>.js, loaded only when it is chosen (loadLanguage).

// the 15 most spoken languages (Ethnologue, total speakers), in that order
export const LANGUAGES = [
  ['en', 'English'], ['zh', '中文（简体）'], ['hi', 'हिन्दी'], ['es', 'Español'], ['ar', 'العربية'],
  ['fr', 'Français'], ['bn', 'বাংলা'], ['pt', 'Português'], ['ru', 'Русский'], ['id', 'Bahasa Indonesia'],
  ['ur', 'اردو'], ['de', 'Deutsch'], ['ja', '日本語'], ['mr', 'मराठी'], ['te', 'తెలుగు'],
];
const CODES = new Set(LANGUAGES.map(([code]) => code));
const RTL = new Set(['ar', 'ur']);
const DICTIONARIES = { en: {} };
const ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'label'];

let lang = 'en';
let exact = new Map();
let patterns = [];
const produced = new Set();
/** Texts that looked like interface text but had no translation (checked by a test). */
export const misses = new Set();

const normalize = (s) => s.replace(/\s+/g, ' ').trim();

function compile(dictionary) {
  exact = new Map();
  patterns = [];
  for (const [key, value] of Object.entries(dictionary)) {
    if (!/\{[\w ]+\}/.test(key)) {
      exact.set(normalize(key), value);
      continue;
    }
    const names = [];
    const source = normalize(key).split(/(\{[\w ]+\})/).map((part) => {
      const m = /^\{([\w ]+)\}$/.exec(part);
      if (!m) return part.replace(/[.*+?^$()|[\]\\]/g, '\\$&');
      names.push(m[1]);
      return '(.+?)';
    }).join('');
    patterns.push({ re: new RegExp(`^${source}$`), names, value });
  }
}

export function language() {
  return lang;
}

/** Switches to a language whose dictionary is loaded (else English). */
export function setLanguage(code) {
  lang = DICTIONARIES[code] ? code : 'en';
  compile(DICTIONARIES[lang]);
  produced.clear();
  misses.clear();
  if (typeof document !== 'undefined') {
    document.documentElement.lang = lang;
    document.documentElement.dir = RTL.has(lang) ? 'rtl' : 'ltr';
  }
}

/** Loads the dictionary of a language (once) and switches to it; English when it cannot be loaded. */
export async function loadLanguage(code) {
  if (CODES.has(code) && !DICTIONARIES[code]) {
    try {
      DICTIONARIES[code] = (await import(`./i18n-${code}.js`)).default;
    } catch {
      code = 'en';
    }
  }
  setLanguage(code);
  return lang;
}

/** Picks the language: saved choice, else the browser's. */
export function detectLanguage(saved, navigatorLanguages = []) {
  if (saved && CODES.has(saved)) return saved;
  for (const l of navigatorLanguages) {
    const code = String(l).slice(0, 2).toLowerCase();
    if (CODES.has(code)) return code;
  }
  return 'en';
}

/** Translation of a text, or null when there is none. */
export function translate(text) {
  if (lang === 'en') return null;
  const key = normalize(text);
  if (!key || produced.has(key)) return null;
  if (exact.has(key)) return exact.get(key);
  for (const p of patterns) {
    const m = p.re.exec(key);
    if (!m) continue;
    let out = p.value;
    p.names.forEach((name, i) => {
      const part = m[i + 1];
      out = out.replace(`{${name}}`, exact.get(part) ?? part);
    });
    return out;
  }
  return null;
}

/** Translates for use in code (falls back to the English text). */
export function t(text) {
  return translate(text) ?? text;
}

// interface text: has a word of three lower-case letters; not numbers, models or paths
// (single tokens with a digit or a colon are ids, versions or file names)
const looksLikeText = (s) => /[a-z]{3}/.test(s) && !(/^\S+$/.test(s) && /[\d:]/.test(s)) && !/^(https?:|data:)/i.test(s);

function apply(value, set) {
  const key = normalize(value);
  if (!key || produced.has(key)) return;
  const out = translate(key);
  if (out === null) {
    if (looksLikeText(key)) misses.add(key);
    return;
  }
  produced.add(normalize(out));
  set(value.replace(value.trim(), out));
}

export function translateTree(root) {
  if (lang === 'en' || !root) return;
  const skip = (el) => el && el.closest && el.closest('[data-no-i18n], script, style, textarea, code, pre');
  if (root.nodeType === Node.TEXT_NODE) {
    if (!skip(root.parentElement)) apply(root.nodeValue, (v) => { root.nodeValue = v; });
    return;
  }
  if (root.nodeType !== Node.ELEMENT_NODE || skip(root)) return;
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    if (!skip(node.parentElement)) apply(node.nodeValue, (v) => { node.nodeValue = v; });
  }
  for (const el of [root, ...root.querySelectorAll('*')]) {
    if (skip(el)) continue;
    for (const name of ATTRIBUTES) {
      if (el.hasAttribute(name)) apply(el.getAttribute(name), (v) => el.setAttribute(name, v));
    }
  }
}

/** Keeps the page translated while the interface changes it. */
export function watch(root) {
  translateTree(root);
  if (lang === 'en') return;
  new MutationObserver((records) => {
    for (const r of records) {
      if (r.type === 'characterData') translateTree(r.target);
      else if (r.type === 'attributes') translateTree(r.target);
      else r.addedNodes.forEach((n) => translateTree(n));
    }
  }).observe(root, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ATTRIBUTES });
}
