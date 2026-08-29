<?php

namespace Wan0v\Pouch\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Deployment mode of the agent's Caddy instance on a node.
 *
 * The mode is chosen by the node administrator via the agent's `POUCH_MODE`
 * environment variable and reported to the panel on every sync. The panel
 * only uses it to decide how the Caddy configuration has to be shaped.
 */
enum ProxyMode: string implements HasColor, HasLabel
{
    /**
     * Ports 80/443 of the node are free. The agent's Caddy binds them and
     * terminates TLS itself.
     */
    case Standalone = 'standalone';

    /**
     * The node is behind_proxy, but the agent's Caddy replaces the existing
     * front-end proxy. It binds 80/443 and additionally serves the Wings vhost.
     */
    case Frontend = 'frontend';

    /**
     * An existing front-end proxy keeps ports 80/443. The agent's Caddy listens
     * on localhost only and serves plain HTTP; TLS is handled upstream.
     */
    case Behind = 'behind';

    public function getLabel(): string
    {
        return trans("pouch::strings.modes.{$this->value}.label");
    }

    public function getDescription(): string
    {
        return trans("pouch::strings.modes.{$this->value}.description");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Standalone => 'success',
            self::Frontend => 'info',
            self::Behind => 'warning',
        };
    }

    /** Whether the agent's Caddy terminates TLS itself. */
    public function terminatesTls(): bool
    {
        return $this !== self::Behind;
    }

    /** Whether the panel has to emit a passthrough route for the Wings vhost. */
    public function needsWingsPassthrough(): bool
    {
        return $this === self::Frontend;
    }
}
