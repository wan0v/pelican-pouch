<?php

namespace Wan0v\Pouch\Http\Requests;

use App\Models\Node;
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
}
