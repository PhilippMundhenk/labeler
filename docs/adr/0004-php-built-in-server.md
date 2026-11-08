# 0004. PHP's built-in server under systemd is the default deployment

Date: 2026-11-08 · Status: accepted

## Context

A Pi Zero W has 512 MB of memory. Apache or nginx with PHP-FPM work, but they are more to install
and configure than a label printer for one household needs, and the web server user also needs
USB access.

## Decision

The documented default is `php -S` started by a systemd unit with its own system user in the group
`lp`, a state directory for the log, and systemd's sandboxing options. Existing web servers remain
supported by pointing their document root at `public/`.

## Consequences

- One package (`php-cli`), one unit file, one udev rule.
- The built-in server handles one request at a time. A print blocks other requests for a few
  seconds; for a few users on a home network that is acceptable.
- PHP documents the built-in server as not meant for production on the internet; Labeler is a
  local network tool without login either way (SECURITY.md).
