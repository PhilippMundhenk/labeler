<?php

// Copy to config.php next to this file and change what you need. Every key can also be set
// as an environment variable LABELER_<KEY>, e.g. LABELER_PTOUCH_BIN=/usr/local/bin/ptouch-print,
// which wins over this file. Keys not listed here keep their default.

return [
    // Printer driver: "ptouch" for Brother P-Touch printers through ptouch-print, "ql" for
    // Brother QL printers on DK labels (built in, nothing to install).
    'printer_driver' => 'ptouch',

    // ql: the printer's USB printer device, e.g. /dev/usb/lp0. Empty: the first Brother QL found.
    'ql_device' => '',

    // ptouch-print executable: a name on PATH or an absolute path.
    'ptouch_bin' => 'ptouch-print',

    // Seconds a single ptouch-print run may take before it is stopped.
    'timeout' => 60,

    // USB serial number of the printer to use when several are attached (ptouch-print 1.9+).
    // Empty: the first printer found.
    'serial' => '',

    // Writable folder for the print log (the last runs of ptouch-print and their output).
    // Empty switches the log off.
    'data_dir' => __DIR__ . '/data',

    // Folder with .ttf, .otf, .woff or .woff2 files offered in the font list.
    'fonts_dir' => __DIR__ . '/fonts',

    // Largest accepted label image in bytes, and most copies per print.
    'max_upload_bytes' => 2000000,
    'max_copies' => 50,

    // Entries kept in the print log.
    'log_entries' => 50,

    // Access token for this API. When set, every request except health needs
    // "Authorization: Bearer <token>" (or "X-Labeler-Token: <token>"). Always set it on a
    // printer agent; install.sh --agent generates one.
    'token' => '',

    // Printer agent to use instead of a local ptouch-print, e.g. 'http://printer-pi:8631', and
    // its token. Empty: print locally.
    'printer_url' => '',
    'printer_token' => '',

    // Several printers in one interface, each with an id (letters, digits, - and _), a name and
    // either a driver with its own settings or a printer agent ('printer': the agent's printer id,
    // empty for its first). For one printer of each kind, 'printer_driver' => 'ptouch,ql' is
    // enough. As an environment variable: LABELER_PRINTERS='[{"id":"tape","driver":"ptouch"}]'.
    // Empty: printer_url, else printer_driver.
    'printers' => [
        // ['id' => 'tape', 'name' => 'Office P-touch', 'driver' => 'ptouch', 'serial' => '000F1Z401234'],
        // ['id' => 'dk', 'name' => 'Office QL', 'driver' => 'ql', 'ql_device' => '/dev/usb/lp0'],
        // ['id' => 'shed', 'name' => 'Shed', 'url' => 'http://shed-pi:8631', 'token' => '...'],
    ],

    // Password for the interface: plain text or, better, a hash made with
    //   php -r 'echo password_hash("your password", PASSWORD_DEFAULT);'
    // Empty: no login (fine on a home network).
    'password' => '',

    // Iconify API for the icon search (Material Design Icons, Simple Icons). The browser asks
    // it directly; the chosen icon is stored in the label. Use your own Iconify server, or ''
    // to switch icons off.
    'icon_api' => 'https://api.iconify.design',

    // Without password and token, the API only answers when it is reached by IP address,
    // localhost or a local name (one word like "labeler", or .local, .lan, .home.arpa, .internal),
    // so that a foreign web page cannot use it through DNS rebinding. Other names you use, e.g.
    // behind a reverse proxy, comma-separated; "*" allows every name.
    'allowed_hosts' => '',
];
