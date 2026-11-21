<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Creates the printer driver named by the `printer_driver` setting.
 */
final class Drivers
{
    /** Known drivers: name => what they print on. */
    public const NAMES = [
        'ptouch' => 'Brother P-Touch printers through ptouch-print',
        'ql' => 'Brother QL printers on DK media, built in',
    ];

    public static function create(Config $config, ?Log $log = null): Driver
    {
        $name = (string) $config->get('printer_driver');
        if ($name === 'ptouch') {
            return PtouchPrint::fromConfig($config, $log);
        }
        if ($name === 'ql') {
            return QlPrint::fromConfig($config, $log);
        }
        return new UnknownDriver($name, array_keys(self::NAMES));
    }
}
