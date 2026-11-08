<?php

// Copy to config.php next to this file and change what you need. Every key can also be set
// as an environment variable LABELER_<KEY>, e.g. LABELER_PTOUCH_BIN=/usr/local/bin/ptouch-print,
// which wins over this file. Keys not listed here keep their default.

return [
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

    // Iconify API for the icon search (Material Design Icons, Simple Icons). The browser asks
    // it directly; the chosen icon is stored in the label. Use your own Iconify server, or ''
    // to switch icons off.
    'icon_api' => 'https://api.iconify.design',
];
