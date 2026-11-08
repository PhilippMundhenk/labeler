// Calls to api.php. Every call resolves to {ok, status, data}; network errors included.

// USB serial number of the chosen printer when several are attached; empty: the default one
let serial = '';
export const setSerial = (value) => {
  serial = value || '';
};

async function call(action, options = {}) {
  const query = `action=${encodeURIComponent(action)}${serial ? `&serial=${encodeURIComponent(serial)}` : ''}`;
  try {
    const res = await fetch(`api.php?${query}`, { cache: 'no-store', ...options });
    let data = {};
    try {
      data = await res.json();
    } catch {
      data = { error: `unexpected answer from the server (HTTP ${res.status})` };
    }
    return { ok: res.ok, status: res.status, data };
  } catch (e) {
    return { ok: false, status: 0, data: { error: 'the server cannot be reached' } };
  }
}

export const getStatus = () => call('status');
export const getFonts = () => call('fonts');
export const getLog = () => call('log');
export const getPrinters = () => call('printers');
export const fontUrl = (file) => `api.php?action=font&file=${encodeURIComponent(file)}`;

export function printLabel(png, options) {
  return call('print', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ png, ...options }),
  });
}
