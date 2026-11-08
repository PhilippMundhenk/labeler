# Security

## Reporting a vulnerability

Please do not open a public issue for security problems. Use GitHub's private vulnerability
reporting on this repository ("Security" tab, "Report a vulnerability"). You should get a first
response within a week.

## Deployment notes

- Labeler has no login. Anyone who can reach the page can print and read the print log. Run it on
  your home network only; do not forward the port to the internet. If it must be reachable from
  elsewhere, put it behind a VPN or a reverse proxy with authentication.
- A printer agent (`install.sh --agent`) always has an access token (48 hex characters,
  `config.php` readable only by root and the service). Every request except `health` needs it as
  `Authorization: Bearer` or `X-Labeler-Token`; it is compared in constant time. It travels in
  plain HTTP: use TLS (reverse proxy) or a VPN between networks you do not trust.
- The interface forwards to `printer_url` only (http/https, no redirects followed); keep its
  `printer_token` out of shared files like any other secret.
- The document root must be `public/`. `src/`, `config.php` and the data folder are outside it on
  purpose.
- The server never passes user input through a shell. It runs ptouch-print with an argument list,
  and the only user data on the command line is the path of a temporary PNG it wrote itself. That
  PNG is checked first: palette image, at most two colours, not higher than the tape, size limit.
- Printing only accepts `application/json`, which browsers cannot send to another site without a
  CORS preflight that the API never answers. Other web pages therefore cannot print.
- The `ptouch_bin` setting names a program that is executed; protect `config.php` and the
  environment of the service like any other configuration.
- The udev rule in `deploy/` gives the group `lp` access to all Brother USB devices. On a machine
  shared with untrusted users, narrow it to your printer's product id (`lsusb`).
- The print log keeps the arguments and output of the last ptouch-print runs, never the label
  content. Temporary label files are removed after printing.

## Fixed issues

- Versions before 1.0.0 (the single `index.php`) passed the label text to a shell, so text such as
  `$(...)` was executed as the web server user (#2). Update to 1.0.0 or newer.
