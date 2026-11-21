// What the label is printed on: P-Touch tape or Brother DK media (QL printers), as the printer
// reports it or as chosen for designing without a printer.

import { TAPES, tapePx, pxToMm } from './label.js';

export const DK_DPI = 300;
/** Shortest label of the QL-570 and most QL printers, in dots. */
export const DK_MIN_PX = 150;

/**
 * DK media for the preview without a printer, as in src/QlRaster.php: id => [width mm, length
 * mm (0 = continuous), printable dots across, printable dots along]. Wide-only media are left out.
 */
export const DK_MEDIA = {
  12: [12, 0, 106, 0],
  29: [29, 0, 306, 0],
  38: [38, 0, 413, 0],
  50: [50, 0, 554, 0],
  54: [54, 0, 590, 0],
  62: [62, 0, 696, 0],
  '17x54': [17, 54, 165, 566],
  '17x87': [17, 87, 165, 956],
  '23x23': [23, 23, 202, 202],
  '29x42': [29, 42, 306, 425],
  '29x90': [29, 90, 306, 991],
  '39x90': [38, 90, 413, 991],
  '39x48': [39, 48, 425, 495],
  '52x29': [52, 29, 578, 271],
  '54x29': [54, 29, 598, 271],
  '60x86': [60, 87, 672, 954],
  '62x29': [62, 29, 696, 271],
  '62x100': [62, 100, 696, 1109],
  d12: [12, 12, 94, 94],
  d24: [24, 24, 236, 236],
  d58: [58, 58, 618, 618],
};

const tape = (mm, live = false) => ({ kind: 'tape', mm, px: tapePx(mm, 180), dpi: 180, lengthPx: 0, minPx: 0, media: '', round: false, tapeColor: 'White', inkColor: 'Black', live });

/** The media for an id of DK_MEDIA, or null. */
export function dkMedia(id, live = false) {
  const m = DK_MEDIA[id];
  if (!m) return null;
  const [mm, lengthMm, px, lengthPx] = m;
  return {
    kind: lengthMm ? 'die-cut' : 'continuous', mm, lengthMm, px, dpi: DK_DPI, lengthPx, minPx: DK_MIN_PX,
    media: String(id), round: String(id).startsWith('d'), tapeColor: 'White', inkColor: 'Black', live,
  };
}

/**
 * The media to design for without a printer: a saved choice ("dk:62", "dk:29x90" or a tape
 * width in mm, the way older versions stored it); `fallback` when it is unknown.
 */
export function chosenMedia(choice, fallback = 12) {
  const value = String(choice);
  if (value.startsWith('dk:')) return dkMedia(value.slice(3)) || dkMedia('62');
  const mm = Number(value);
  return TAPES[mm] ? tape(mm) : chosenMedia(fallback, 12);
}

/** The value of a media in the "Design for" list. */
export const mediaChoice = (m) => (m.kind === 'tape' ? String(m.mm) : `dk:${m.media}`);

/** Options of the "Design for" list: [group, [[value, text], ...]]. */
export function mediaChoices() {
  const ids = Object.keys(DK_MEDIA);
  const name = (id) => mediaName(dkMedia(id));
  return [
    ['P-Touch tape', Object.keys(TAPES).map((mm) => [mm, `${mm} mm`])],
    ['DK roll (QL)', ids.filter((id) => !DK_MEDIA[id][1]).map((id) => [`dk:${id}`, name(id)])],
    ['DK labels (QL)', ids.filter((id) => DK_MEDIA[id][1]).map((id) => [`dk:${id}`, name(id)])],
  ];
}

/** The media the printer reports (its status `printer`), for a ready printer. */
export function printerMedia(p) {
  const m = p.media;
  if (!m) {
    return {
      ...tape(p.media_width_mm, true), px: p.tape_px, dpi: p.dpi,
      tapeColor: (p.tape_color && p.tape_color.name) || 'White',
      inkColor: (p.text_color && p.text_color.name) || 'Black',
    };
  }
  return {
    kind: m.kind, mm: m.width_mm, lengthMm: m.length_mm || 0, px: p.tape_px, dpi: p.dpi,
    lengthPx: m.length_px || 0, minPx: p.min_px || 0, media: m.id, round: Boolean(m.round),
    tapeColor: 'White', inkColor: 'Black', live: true,
  };
}

/** Short name: "12 mm", "62 mm DK roll", "29 × 90 mm", "Ø 24 mm". */
export function mediaName(m) {
  if (m.kind === 'tape') return `${m.mm} mm`;
  if (m.kind === 'continuous') return `${m.mm} mm DK roll`;
  return m.round ? `Ø ${m.mm} mm` : `${m.mm} × ${m.lengthMm} mm`;
}

/** The line above the preview. */
export function mediaInfo(m) {
  if (m.kind === 'tape') return `${m.mm} mm tape · ${m.px} px at ${m.dpi} dpi`;
  if (m.kind === 'continuous') return `${m.mm} mm DK roll · ${m.px} px at ${m.dpi} dpi`;
  return `${mediaName(m)} DK labels · ${m.px} × ${m.lengthPx} px at ${m.dpi} dpi`;
}

/**
 * The length of the printed label for content `width` px long: continuous media have a shortest
 * label, die-cut labels a fixed length. `over` is how many px do not fit a die-cut label.
 */
export function fitLength(width, m) {
  if (m.kind === 'die-cut') return { width: m.lengthPx, over: Math.max(0, width - m.lengthPx) };
  return { width: Math.max(width, m.minPx || 0), over: 0 };
}

/** Why the content does not fit, or ''. */
export function overflowMessage(width, m) {
  const { over } = fitLength(width, m);
  if (!over) return '';
  return `The content is ${pxToMm(width, m.dpi)} mm long, the label only ${pxToMm(m.lengthPx, m.dpi)} mm: make it shorter or smaller`;
}
