<?php

declare(strict_types=1);

// No Composer on the device: the few classes are loaded directly.
foreach (['Config', 'Process', 'Log', 'Png', 'PtouchPrint', 'Response', 'Remote', 'Api'] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
