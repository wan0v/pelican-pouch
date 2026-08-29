<?php

namespace Wan0v\Pouch\Http\Requests;

use App\Models\Node;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Wan0v\Pouch\Enums\ProxyMode;

class SyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The `daemon` middleware group already authenticated the node.
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
            'wings_upstream' => ['nullable', 'string', 'max:255'],
            'agent_version' => ['nullable', 'string', 'max:64'],
            'caddy_version' => ['nullable', 'string', 'max:64'],
            'applied_hash' => ['nullable', 'string', 'size:64'],
            'last_error' => ['nullable', 'string', 'max:2000'],
            'cert_status' => ['nullable', 'array'],
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

            $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

            if (!ctype_digit($parts[1]) || (int) $parts[1] > $max) {
                $fail('The :attribute must be a valid IP address or CIDR range.');
            }
        };
    }
}
