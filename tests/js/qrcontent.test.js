import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildQrContent, KINDS } from '../../public/js/qrcontent.js';
import { normalizeBlock } from '../../public/js/label.js';

const qr = (fields) => normalizeBlock({ type: 'qr', ...fields });

test('text and links pass through', () => {
  assert.equal(buildQrContent(qr({ content: 'https://example.com' })), 'https://example.com');
  assert.equal(KINDS.length, 5);
});

test('Wi-Fi with escaping, security types and hidden networks', () => {
  assert.equal(buildQrContent(qr({ kind: 'wifi', wifiSsid: 'Guest', wifiPassword: 'secret' })), 'WIFI:T:WPA;S:Guest;P:secret;;');
  assert.equal(buildQrContent(qr({ kind: 'wifi', wifiSsid: 'My;Net', wifiPassword: 'a:b,c"d\\e', wifiType: 'WEP', wifiHidden: true })),
    'WIFI:T:WEP;S:My\\;Net;P:a\\:b\\,c\\"d\\\\e;H:true;;');
  assert.equal(buildQrContent(qr({ kind: 'wifi', wifiSsid: 'Cafe', wifiPassword: 'ignored', wifiType: 'nopass' })), 'WIFI:T:nopass;S:Cafe;;');
  assert.equal(buildQrContent({ kind: 'wifi', wifiSsid: 'X', wifiType: 'bogus' }), 'WIFI:T:WPA;S:X;P:;;');
  assert.throws(() => buildQrContent(qr({ kind: 'wifi', wifiSsid: '  ' })), /network name/);
});

test('contact as vCard', () => {
  const card = buildQrContent(qr({ kind: 'contact', contactName: 'Erika Maria Musterfrau', org: 'Example, Inc.', phone: '+49 (30) 123-456', email: 'erika@example.com', url: 'https://example.com' }));
  assert.equal(card, ['BEGIN:VCARD', 'VERSION:3.0', 'N:Musterfrau;Erika Maria;;;', 'FN:Erika Maria Musterfrau',
    'ORG:Example\\, Inc.', 'TEL:+4930123456', 'EMAIL:erika@example.com', 'URL:https://example.com', 'END:VCARD'].join('\n'));
  assert.equal(buildQrContent(qr({ kind: 'contact', phone: '110' })).split('\n')[2], 'N:;;;;');
  assert.match(buildQrContent(qr({ kind: 'contact', contactName: 'Plato' })), /N:;Plato;;;\nFN:Plato/);
  assert.match(buildQrContent(qr({ kind: 'contact', contactName: 'A\\B; C' })), /FN:A\\\\B\\; C/);
  assert.throws(() => buildQrContent(qr({ kind: 'contact' })), /name, phone number or e-mail/);
});

test('e-mail and phone', () => {
  assert.equal(buildQrContent(qr({ kind: 'email', email: 'info@example.com', subject: 'Box 4 & 5' })), 'mailto:info@example.com?subject=Box%204%20%26%205');
  assert.equal(buildQrContent(qr({ kind: 'email', email: 'info@example.com' })), 'mailto:info@example.com');
  assert.throws(() => buildQrContent(qr({ kind: 'email' })), /e-mail address/);
  assert.equal(buildQrContent(qr({ kind: 'phone', phone: '+1 (555) 010-0199' })), 'tel:+15550100199');
  assert.throws(() => buildQrContent(qr({ kind: 'phone', phone: 'none' })), /phone number/);
});

test('normalizeBlock keeps the QR helper fields', () => {
  const b = qr({ kind: 'nope', wifiType: 'x', wifiHidden: 1, email: 5 });
  assert.deepEqual([b.kind, b.wifiType, b.wifiHidden, b.email], ['text', 'WPA', true, '']);
});
