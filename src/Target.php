<?php

declare(strict_types=1);

namespace Labeler;

/**
 * One printer of the interface: a driver on this computer, or a printer agent (with the id of
 * the agent's printer, empty for its first one).
 */
final class Target
{
    public string $id;
    public string $name;
    public ?Driver $driver;
    public ?Remote $remote;
    public string $remotePrinter;

    public function __construct(string $id, string $name, ?Driver $driver, ?Remote $remote = null, string $remotePrinter = '')
    {
        $this->id = $id;
        $this->name = $name;
        $this->driver = $driver;
        $this->remote = $remote;
        $this->remotePrinter = $remotePrinter;
    }

    /** What the printer is, for the interface: the driver name, or "agent". */
    public function kind(): string
    {
        return $this->remote !== null ? 'agent' : ($this->driver !== null ? $this->driver->name() : 'agent');
    }
}
