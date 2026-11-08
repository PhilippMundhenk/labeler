// Icon search and download through an Iconify API (https://iconify.design), by default the
// public api.iconify.design. Only the chosen icon's SVG is kept, inside the label.

export const COLLECTIONS = {
  mdi: { name: 'Material Design Icons', short: 'Material', license: 'Apache 2.0' },
  'simple-icons': { name: 'Simple Icons', short: 'Brands', license: 'CC0; brand logos are trademarks of their owners' },
};

const ID = /^([a-z0-9]+(?:-[a-z0-9]+)*):([a-z0-9]+(?:-[a-z0-9]+)*)$/;

export function parseId(id) {
  const m = ID.exec(id || '');
  return m && COLLECTIONS[m[1]] ? { prefix: m[1], name: m[2] } : null;
}

const base = (api) => api.replace(/\/+$/, '');

/** Small preview or full-size black SVG of an icon. */
export function iconUrl(api, id, height = 480) {
  const p = parseId(id);
  if (!p) throw new Error(`unknown icon ${id}`);
  return `${base(api)}/${p.prefix}/${p.name}.svg?height=${height}&color=%23000000`;
}

/**
 * @param {string} set "all" or a collection prefix
 * @returns {Promise<string[]>} icon ids like "mdi:router"
 */
export async function searchIcons(api, query, set = 'all', limit = 64, fetchFn = fetch) {
  const q = query.trim();
  if (q.length < 2) return [];
  const prefixes = set === 'all' ? Object.keys(COLLECTIONS).join(',') : set;
  const url = `${base(api)}/search?query=${encodeURIComponent(q)}&prefixes=${encodeURIComponent(prefixes)}&limit=${limit}`;
  let res;
  try {
    res = await fetchFn(url);
  } catch {
    throw new Error('the icon service cannot be reached');
  }
  if (!res.ok) throw new Error(`the icon service answered HTTP ${res.status}`);
  const data = await res.json();
  return (Array.isArray(data.icons) ? data.icons : []).filter((id) => parseId(id));
}

export function svgDataUrl(svg) {
  const bytes = new TextEncoder().encode(svg);
  let s = '';
  for (const b of bytes) s += String.fromCharCode(b);
  return `data:image/svg+xml;base64,${btoa(s)}`;
}

/** Downloads an icon as a data URL, so the label no longer needs the service. */
export async function fetchIcon(api, id, fetchFn = fetch) {
  let res;
  try {
    res = await fetchFn(iconUrl(api, id));
  } catch {
    throw new Error('the icon service cannot be reached');
  }
  const svg = res.ok ? await res.text() : '';
  if (!svg.trimStart().startsWith('<svg')) throw new Error(`icon ${id} not found`);
  return svgDataUrl(svg);
}
