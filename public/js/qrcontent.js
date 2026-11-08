// QR code content from form fields: Wi-Fi, contact (vCard), e-mail, phone, or plain text/link.

export const KINDS = [['text', 'Text or link'], ['wifi', 'Wi-Fi'], ['contact', 'Contact'], ['email', 'E-mail'], ['phone', 'Phone']];

/** Wi-Fi QR strings escape \ ; , : and " with a backslash. */
const wifiEscape = (s) => s.replace(/([\\;,:"])/g, '\\$1');

/** vCard text values escape backslash, comma, semicolon and line breaks. */
const vcardEscape = (s) => s.replace(/\\/g, '\\\\').replace(/([,;])/g, '\\$1').replace(/\r?\n/g, '\\n');

const tel = (s) => s.replace(/[^\d+]/g, '');

export function buildQrContent(b) {
  const v = (k) => (typeof b[k] === 'string' ? b[k].trim() : '');
  switch (b.kind) {
    case 'wifi': {
      if (!v('wifiSsid')) throw new Error('enter the network name');
      const type = ['WPA', 'WEP', 'nopass'].includes(b.wifiType) ? b.wifiType : 'WPA';
      const pass = type === 'nopass' ? '' : `P:${wifiEscape(b.wifiPassword || '')};`;
      return `WIFI:T:${type};S:${wifiEscape(b.wifiSsid)};${pass}${b.wifiHidden ? 'H:true;' : ''};`;
    }
    case 'contact': {
      if (!v('contactName') && !v('phone') && !v('email')) throw new Error('enter a name, phone number or e-mail address');
      const name = v('contactName');
      const parts = name.split(/\s+/);
      const last = parts.length > 1 ? parts.pop() : '';
      const lines = ['BEGIN:VCARD', 'VERSION:3.0',
        `N:${vcardEscape(last)};${vcardEscape(parts.join(' '))};;;`, `FN:${vcardEscape(name)}`];
      if (v('org')) lines.push(`ORG:${vcardEscape(v('org'))}`);
      if (v('phone')) lines.push(`TEL:${tel(v('phone'))}`);
      if (v('email')) lines.push(`EMAIL:${v('email')}`);
      if (v('url')) lines.push(`URL:${v('url')}`);
      lines.push('END:VCARD');
      return lines.join('\n');
    }
    case 'email': {
      if (!v('email')) throw new Error('enter an e-mail address');
      return `mailto:${v('email')}${v('subject') ? `?subject=${encodeURIComponent(v('subject'))}` : ''}`;
    }
    case 'phone': {
      if (!tel(v('phone'))) throw new Error('enter a phone number');
      return `tel:${tel(v('phone'))}`;
    }
    default:
      return b.content;
  }
}
