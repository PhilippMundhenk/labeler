# 0007. Printers are drivers behind one small interface, chosen in the config

Date: 2026-11-21 · Status: accepted

## Context

Labeler talked to exactly one kind of printer: `Api` held a `PtouchPrint` and called it for the
version, the features, the attached printers, the status and printing. Brother QL printers (DK
labels) cannot be reached through ptouch-print, so a second backend is needed, and the API should
not grow an `if` for every printer family.

## Decision

`Driver` is an interface with what the API uses today: `name`, `version`, `supports`/`features`,
`withSerial`, `connected`, `info`, `printImage`, and `offline`/`offlineFeatures` for the answer
when no printer can be asked (agent unreachable). `PtouchPrint` implements it unchanged as the
`ptouch` driver. `Drivers::create()` picks the driver by the setting `printer_driver` (default
`ptouch`) on the interface and on a printer agent alike; an unknown name gives an `UnknownDriver`
whose status names the mistake, rather than silently falling back to ptouch-print.

The status answer gets `driver` and `driver_version`; `ptouch_version` stays for older interfaces
and is null for other drivers. Through an agent, the agent's driver is reported; an agent that
does not name one is a ptouch agent.

## Consequences

- No change in behaviour for P-Touch printers; the simulator, the contract test and the coverage
  gate stay as they are.
- A new driver is one class plus a line in `Drivers`; the interface can show driver-specific
  options from `driver` and `features`.
- The `info` array (`tape_px`, `dpi`, `media_width_mm`, …) is shared by all drivers, so the editor
  keeps working unchanged; driver-specific fields are added, never renamed.
