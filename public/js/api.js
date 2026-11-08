// Calls to api.php. Every call resolves to {ok, status, data}; network errors included.

async function call(action, options = {}) {
  try {
    const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, { cache: 'no-store', ...options });
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
export const fontUrl = (file) => `api.php?action=font&file=${encodeURIComponent(file)}`;

export function printLabel(png, options) {
  return call('print', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ png, ...options }),
  });
}
