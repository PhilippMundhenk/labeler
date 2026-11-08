// Rows for batch printing: a plain list (one value per line), CSV or values pasted from a
// spreadsheet (tab separated). The separator is detected from the first line.

const SEPARATORS = ['\t', ';', ','];

function countOutsideQuotes(line, sep) {
  let n = 0;
  let quoted = false;
  for (const ch of line) {
    if (ch === '"') quoted = !quoted;
    else if (ch === sep && !quoted) n++;
  }
  return n;
}

export function detectSeparator(text) {
  const first = text.split(/\r?\n/).find((l) => l.trim()) || '';
  let best = null;
  let most = 0;
  for (const sep of SEPARATORS) {
    const n = countOutsideQuotes(first, sep);
    if (n > most) {
      most = n;
      best = sep;
    }
  }
  return best;
}

/** @returns {string[][]} rows of trimmed cells; empty lines are skipped */
export function parseRows(text, sep = detectSeparator(text)) {
  const rows = [];
  let row = [];
  let cell = '';
  let quoted = false;
  const push = () => {
    row.push(cell.trim());
    cell = '';
  };
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (quoted) {
      if (ch === '"' && text[i + 1] === '"') {
        cell += '"';
        i++;
      } else if (ch === '"') quoted = false;
      else cell += ch;
    } else if (ch === '"' && cell.trim() === '') {
      quoted = true;
      cell = '';
    } else if (sep !== null && ch === sep) push();
    else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && text[i + 1] === '\n') i++;
      push();
      if (row.some((c) => c !== '')) rows.push(row);
      row = [];
    } else cell += ch;
  }
  push();
  if (row.some((c) => c !== '')) rows.push(row);
  return rows;
}

/** @returns {{headers: string[], rows: string[][]}} */
export function parseBatch(text, header = false) {
  const rows = parseRows(text || '');
  if (!header || !rows.length) return { headers: [], rows };
  return { headers: rows[0], rows: rows.slice(1) };
}
