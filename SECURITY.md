# Security

## Reporting a vulnerability

Please do not open a public issue for security problems. Use GitHub's private vulnerability
reporting on this repository ("Security" tab, "Report a vulnerability"). You should get a first
response within a week.

## Deployment notes

- Without the `password` setting, anyone who can reach the page can print and read the print log.
  With it, every API call except `health` needs a login: a signed, HttpOnly, SameSite=Strict
  session cookie valid for 30 days, keyed by the password (changing it logs everybody out), with a
  one-second delay after a wrong password. Store a hash rather than the plain password:
  `php -r 'echo password_hash("...", PASSWORD_DEFAULT);'`. Behind a reverse proxy with HTTPS the
  cookie gets the Secure flag (`X-Forwarded-Proto: https`).
- Even with a password, do not forward the port to the internet without TLS: use a reverse proxy
  with HTTPS or a VPN.
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
  CORS preflight that the API never answers. A web page could still point its own domain name at
  your Labeler (DNS rebinding); therefore an API without password and token only answers when it
  is reached by IP address, `localhost` or a local name, and other names have to be listed in
  `allowed_hosts`. With a password or token this does not apply: the foreign page has neither.
- The `ptouch_bin` setting names a program that is executed; protect `config.php` and the
  environment of the service like any other configuration.
- The udev rule in `deploy/` gives the group `lp` access to all Brother USB devices. On a machine
  shared with untrusted users, narrow it to your printer's product id (`lsusb`).
- Icon search runs in the browser against the Iconify API (`icon_api`, by default
  `api.iconify.design`): the search words and the chosen icon names go to that service, together
  with what every web request carries: the IP address, the browser and the `Origin` header (the
  address you opened Labeler with); no `Referer` is sent, no label content. Set `icon_api` to your own Iconify server or to empty to avoid it. The chosen icon's SVG is
  stored in the label, so printing never needs the service.
- The print log keeps the arguments and output of the last ptouch-print runs, never the label
  content. Temporary label files are removed after printing.

## Fixed issues

- The old single `index.php` (before the rewrite; the first release of the new code is 0.0.1)
  passed the label text to a shell, so text such as `$(...)` was executed as the web server user.
  Version 0.0.1 had a stored XSS through saved labels and further weaknesses found in a review
  before 1.0.0 (see the changelog, section Security). Update to 1.0.0 or newer.
