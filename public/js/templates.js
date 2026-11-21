// Ready-made labels to start from. Sample content only; everything can be changed afterwards.

const text = (t, extra = {}) => ({ type: 'text', text: t, font: 'sans-serif', size: 0, bold: true, italic: false, align: 'center', invert: false, ...extra });
const layout = (extra) => ({ margin: 2, gap: 3, length: 0, align: 'center', frame: false, frameWidth: 2, counter: 1, ...extra });

export const TEMPLATES = [
  {
    id: 'asset',
    name: 'Asset tag',
    description: 'Numbered inventory label with a QR code; every copy gets the next number.',
    label: layout({ blocks: [
      { type: 'qr', kind: 'text', content: 'https://example.com/asset/{n:4}', level: 'M', scale: 100, quiet: 0 },
      text('Asset {n:4}\nProperty of Example', { align: 'left' }),
    ] }),
  },
  {
    id: 'wifi',
    name: 'Wi-Fi guest access',
    description: 'Network name and a QR code that phones join by scanning.',
    label: layout({ blocks: [
      text('Guest Wi-Fi\nScan to connect', { align: 'left' }),
      { type: 'qr', kind: 'wifi', wifiSsid: 'Guest', wifiPassword: 'change-me', wifiType: 'WPA', wifiHidden: false, level: 'M', scale: 100, quiet: 0 },
    ] }),
  },
  {
    id: 'cable',
    name: 'Cable label',
    description: 'Cable flag: the name twice, folded around a 6 mm cable, white on black.',
    label: layout({ margin: 1, flag: true, flagDiameter: 6, blocks: [text('LAN 01', { invert: true })] }),
  },
  {
    id: 'spine',
    name: 'Folder spine',
    description: 'One big line and a year, for binders and boxes.',
    label: layout({ length: 120, blocks: [text('Invoices', { size: 0 }), text('{date}', { bold: false })] }),
  },
  {
    id: 'address',
    name: 'Address',
    description: 'Three lines, left aligned, for envelopes and parcels.',
    label: layout({ blocks: [text('Erika Example\nExample Street 1\n12345 Example City', { bold: false, align: 'left' })] }),
  },
  {
    id: 'name',
    name: 'Name tag',
    description: 'Name and role in a frame, at least 60 mm long.',
    label: layout({ length: 60, frame: true, blocks: [text('Alex Example\nWorkshop team')] }),
  },
  {
    id: 'shelf',
    name: 'Shelf label with barcode',
    description: 'Item name and a Code 128 barcode for scanners.',
    label: layout({ blocks: [
      text('Screws M4 × 20', { align: 'left' }),
      { type: 'barcode', format: 'code128', content: 'SKU-0042', text: true, bar: 2, scale: 100 },
    ] }),
  },
  // for Brother QL printers: `media` is the DK media they are made for (media.js)
  {
    id: 'dk-address',
    name: 'Address label (DK 29 × 90 mm)',
    description: 'Recipient in three lines on a die-cut address label.',
    media: '29x90',
    label: layout({ margin: 3, align: 'left', blocks: [text('Erika Example\nExample Street 1\n12345 Example City', { bold: false, align: 'left' })] }),
  },
  {
    id: 'dk-shipping',
    name: 'Shipping label (DK 62 × 100 mm)',
    description: 'Sender in small print above the recipient, on a die-cut shipping label.',
    media: '62x100',
    label: layout({ margin: 4, align: 'left', blocks: [
      text('From: A. Example, 54321 Sample Town\nTo:\nErika Example\nExample Street 1\n12345 Example City', { size: 22, bold: false, align: 'left', lineSizes: '45, 45, 100' }),
    ] }),
  },
  {
    id: 'dk-note',
    name: 'Note with date (DK 62 mm)',
    description: 'Today\'s date and a few lines, for boxes, jars and the fridge.',
    media: '62',
    label: layout({ length: 80, align: 'left', blocks: [text('{date}\nLeftovers\nEat by Friday', { align: 'left', lineSizes: '60, 100, 80' })] }),
  },
];

export function findTemplate(id) {
  return TEMPLATES.find((t) => t.id === id);
}
