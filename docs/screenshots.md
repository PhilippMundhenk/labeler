# Screenshots

All pictures come from a demo instance on the simulated printer, built by
`tools/dev.sh npm run screenshots` (`tools/screenshots.js`).

## Editor

A label with an image, two lines of text and a QR code on 24 mm tape. The preview above the editor
is the printout pixel for pixel, in the colours of the inserted tape.

| Light | Dark |
| --- | --- |
| ![Editor](screenshots/editor.png) | ![Editor in dark mode](screenshots/editor-dark.png) |

## Wide screen

From 1400 px wide, Print and every print option sit in a column beside the preview, always open.

![Wide screen](screenshots/wide.png)

## Floating preview

When the preview has scrolled out of view, a small copy of the label floats in the corner; a
click on it scrolls back. *Floating preview* next to the preview switches it off.

![Floating preview](screenshots/floating.png)

## Phone

| Editor | No printer attached |
| --- | --- |
| ![Phone](screenshots/phone.png) | ![No printer](screenshots/no-printer.png) |

Without a printer the tape width can be chosen to design labels in advance.

## Printer details and print log

The badge in the brand bar opens the printer details and the last print jobs with the exact
ptouch-print command and its output.

![Printer details](screenshots/details.png)
