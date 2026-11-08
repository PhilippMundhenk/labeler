# 0005. Saved labels live in the browser's storage

Date: 2026-11-08 · Status: superseded for saved labels by #34 (server, when the data folder is
writable); the current label and the print history stay in the browser

## Context

People want to reprint labels and keep templates. Storing them on the server needs write access,
a storage format, and a decision about who may change what.

## Decision

The current label and named saved labels are kept in the browser's `localStorage`. Uploaded images
are scaled down before they are stored so a label stays well below the storage limit; when storage
is full, the user is told.

## Consequences

- No server state besides the optional print log; nothing to back up on the Pi.
- Saved labels are per browser: the phone and the laptop do not share them. Shared templates on
  the server are a possible extension.
