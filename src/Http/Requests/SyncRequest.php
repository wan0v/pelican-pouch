<?php

namespace Wan0v\Pouch\Http\Requests;

use App\Models\Node;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Wan0v\Pouch\Enums\ProxyMode;

class SyncRequest extends FormRequest
{
    /** Shortest IPv4 proxy prefix a node may declare trusted. */
    private const MIN_PREFIX_V4 = 8;

    /** Shortest IPv6 proxy prefix a node may declare trusted. */
    private const MIN_PREFIX_V6 = 32;

    public function authorize(): bool
    {
        // AuthenticatePouchAgent already authenticated the node.
        return $this->node() instanceof Node;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:' . implode(',', array_column(ProxyMode::cases(), 'value'))],
            'http_port' => ['required', 'integer', 'between:1,65535'],
            'https_port' => ['required', 'integer', 'between:1,65535'],
            // Local address the agent binds in `behind` mode. Absent on older
            // agents, which keeps the loopback default.
            'bind_address' => ['nullable', 'string', 'ip'],
            'trusted_proxies' => ['nullable', 'array', 'max:20'],
            'trusted_proxies.*' => ['string', 'max:43', $this->cidrRule()],
            // Dialed verbatim by Caddy for the node's own Wings vhost, so it
            // has to be a bare host:port and nothing else.
            'wings_upstream' => ['nullable', 'string', 'max:255', $this->upstreamRule()],
            'agent_version' => ['nullable', 'string', 'max:64'],
            'caddy_version' => ['nullable', 'string', 'max:64'],
            'applied_hash' => ['nullable', 'string', 'size:64'],
            'last_error' => ['nullable', 'string', 'max:2000'],
            // Display only, but it is stored as-is: bound so a node cannot
            // grow the row without limit.
            'cert_status' => ['nullable', 'array', 'max:200', $this->hostnameKeysRule()],
            'cert_status.*' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function node(): ?Node
    {
        $node = $this->attributes->get('node');

        return $node instanceof Node ? $node : null;
    }

    public function mode(): ProxyMode
    {
        return ProxyMode::from($this->string('mode')->value());
    }

    /**
     * Caddy accepts both bare addresses and CIDR notation in
     * `trusted_proxies.ranges`, and Laravel has no rule for the latter.
     *
     * A range that is too wide would let anyone on the internet spoof
     * `X-Forwarded-*` towards every backend of the node, so anything shorter
     * than a /8 (/32 for IPv6) is refused outright. The node tab warns about
     * the merely questionable ones.
     */
    private function cidrRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $parts = explode('/', (string) $value, 2);
            $ip = $parts[0];

            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $fail('The :attribute must be a valid IP address or CIDR range.');

                return;
            }

            if (!isset($parts[1])) {
                return;
            }

            $ipv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $max = $ipv6 ? 128 : 32;

            if (!ctype_digit($parts[1]) || (int) $parts[1] > $max) {
                $fail('The :attribute must be a valid IP address or CIDR range.');

                return;
            }

            if ((int) $parts[1] < ($ipv6 ? self::MIN_PREFIX_V6 : self::MIN_PREFIX_V4)) {
                $fail('The :attribute is too broad to be trusted as a proxy range.');
            }
        };
    }

    /**
     * `host:port`, where host is an IP (v6 in brackets) or a hostname. Anything
     * else — a scheme, a path, a space — would end up in Caddy's `dial`.
     */
    private function upstreamRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $value = (string) $value;

            if (!preg_match('/^(?:\[([0-9a-fA-F:]+)]|([^:\/\s]+)):(\d{1,5})$/', $value, $matches)) {
                $fail('The :attribute must be a host:port pair.');

                return;
            }

            $port = (int) $matches[3];

            if ($port < 1 || $port > 65535) {
                $fail('The :attribute must contain a valid port.');

                return;
            }

            $host = $matches[1] !== '' ? $matches[1] : $matches[2];

            if ($matches[1] !== '') {
                if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                    $fail('The :attribute must contain a valid IPv6 address.');
                }

                return;
            }

            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return;
            }

            if (!preg_match('/^(?=.{1,253}$)[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $host)) {
                $fail('The :attribute must contain a valid hostname or IP address.');
            }
        };
    }

    /**
     * The keys of `cert_status` are hostnames the agent found a certificate
     * for. They are rendered in the admin UI, so they are checked as well.
     */
    private function hostnameKeysRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (!is_array($value)) {
                return;
            }

            foreach (array_keys($value) as $host) {
                if (!is_string($host) || !preg_match('/^(?=.{1,253}$)\*?[a-zA-Z0-9.-]+$/', $host)) {
                    $fail('The :attribute contains an invalid hostname.');

                    return;
                }
            }
        };
    }
}
