# Labeler: branding guide

One product, one surface: the web page in `public/`. Code references: `public/app.css` (tokens),
`public/icon.svg`, `public/manifest.webmanifest`.

> Labeler is an independent open-source project, not affiliated with Brother Industries; "Brother"
> and "P-touch" are used only to describe compatibility.

## 1. Name

| Where | Name |
| --- | --- |
| Product (titles, docs) | **Labeler** |
| Code name (package, config, env prefix) | `labeler`: `labeler.service`, `labeler-<version>.zip`, `LABELER_*`, `labeler.*` storage keys |
| Repository | `PhilippMundhenk/labeler` |

## 2. Colours

| Token (CSS) | Light | Dark | Used for |
| --- | --- | --- | --- |
| `--primary` | `#2A6FDB` | `#8AB4F8` | brand bar, primary button, links, selected segment |
| `--on-primary` | `#FFFFFF` | `#0B2A5B` | text on primary |
| `--link` | `#1D5BC4` | `#8AB4F8` | blue text: links, secondary and link buttons (4.5:1 on every surface) |
| `--bg` | `#F3F4F6` | `#121212` | page background |
| `--card` | `#FFFFFF` | `#1E1E1E` | cards, dialog |
| `--field` | `#F7F7F7` | `#262626` | inputs, log entries |
| `--fg` | `#1A1A1A` | `#EEEEEE` | text |
| `--muted` | `#666666` | `#AAAAAA` | labels, hints, section titles |
| `--line` | `#E3E3E3` | `#333333` | borders |
| `--ok` | `#2E7D32` | `#81C995` | successful print in the log |
| `--warn` | `#B26A00` | `#FDD663` | printer not ready notice |
| `--bad` | `#C62828` | `#F28B82` | errors |

The label preview uses the tape and ink colours the printer reports, not the theme.

## 3. Typography

System font stack, 15 px / 1.5. Section titles 13 px, muted, all caps, 0.04 em spacing. Text
boxes for label text use a monospace font so line breaks are obvious.

## 4. Components

Brand bar with the printer badge (dot: green ready, amber not ready, red no printer, pulsing while
checking) · sticky preview card with tape size, label length, copies and one filled **Print**
button · content card with one bordered block per label block (title, inline error, icon tools) ·
segmented controls for choices · toasts for results · dialog for printer details and print log.

## 5. Icon

`public/icon.svg`: a white label tag with a hole on the primary blue rounded square, two dark lines
of "text". Used as favicon and web app icon.

## 6. Tone

Short sentences, no exclamation marks. Errors say what to do next ("Set the mode switch to E").
Printer controls and switches in bold in the docs.
