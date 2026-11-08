// Placeholders in label text: {date}, {time}, {datetime}, {n}, and batch columns {1} / {name}.

/** Block fields that may contain placeholders. */
export const FILLABLE = ['text', 'content', 'wifiSsid', 'wifiPassword', 'contactName', 'org', 'phone', 'email', 'url', 'subject'];

const pad = (v, width) => String(v).padStart(width, '0');

function date(d, format) {
  const y = d.getFullYear();
  const m = pad(d.getMonth() + 1, 2);
  const day = pad(d.getDate(), 2);
  if (format === 'dmy') return `${day}.${m}.${y}`;
  if (format === 'mdy') return `${m}/${day}/${y}`;
  return `${y}-${m}-${day}`;
}

const time = (d) => `${pad(d.getHours(), 2)}:${pad(d.getMinutes(), 2)}`;

/**
 * @param {{now?: Date, n?: number, row?: string[]|null, headers?: string[]}} ctx
 */
export function fill(text, ctx = {}) {
  const now = ctx.now || new Date();
  const n = ctx.n ?? 1;
  const row = ctx.row || null;
  const headers = ctx.headers || [];
  return String(text).replace(/\{([^{}\n]{1,40})\}/g, (match, key) => {
    const [name, arg = ''] = key.split(':');
    switch (name) {
      case 'date': return date(now, arg);
      case 'time': return time(now);
      case 'datetime': return `${date(now, arg)} ${time(now)}`;
      case 'n': return pad(n, Math.min(12, Number(arg) || 0));
      default:
        if (!row) return match;
        if (/^\d+$/.test(name)) return row[Number(name) - 1] ?? '';
        if (headers.includes(name)) return row[headers.indexOf(name)] ?? '';
        return match;
    }
  });
}

function strings(label) {
  return label.blocks.flatMap((b) => FILLABLE.map((k) => b[k]).filter((v) => typeof v === 'string'));
}

export const usesCounter = (label) => strings(label).some((s) => /\{n(:\d+)?\}/.test(s));

export const usesColumns = (label, headers = []) => strings(label).some((s) =>
  /\{\d+\}/.test(s) || headers.some((h) => s.includes(`{${h}}`)));

/** A copy of the label with every placeholder replaced. */
export function resolveLabel(label, ctx = {}) {
  return {
    ...label,
    blocks: label.blocks.map((b) => {
      const out = { ...b };
      for (const k of FILLABLE) if (typeof out[k] === 'string') out[k] = fill(out[k], ctx);
      return out;
    }),
  };
}
