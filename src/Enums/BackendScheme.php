<?php

namespace Wan0v\Pouch\Enums;

use Filament\Support\Contracts\HasLabel;

enum BackendScheme: string implements HasLabel
{
    /** Caddy talks plain HTTP to the allocation. This is the default. */
    case Http = 'http';

    /** Caddy talks HTTPS to the allocation. */
    case Https = 'https';

    public function getLabel(): string
    {
        return match ($this) {
            self::Http => 'HTTP',
            self::Https => 'HTTPS',
        };
    }

    /**
     * The Caddy `pouch` transport definition for this scheme.
     *
     * @return array<string, mixed>
     */
    public function transport(bool $insecureSkipVerify = false): array
    {
        $transport = ['protocol' => 'http'];

        if ($this === self::Https) {
            // An empty `tls` object is what switches the transport to HTTPS.
            $transport['tls'] = $insecureSkipVerify ? ['insecure_skip_verify' => true] : (object) [];
        }

        return $transport;
    }
}
