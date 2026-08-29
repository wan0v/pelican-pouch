<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ACME / TLS
    |--------------------------------------------------------------------------
    |
    | Used by the agent's dedicated Caddy instance to obtain certificates for
    | the generated hostnames. Leave the CA empty to use Caddy's default
    | issuer chain (Let's Encrypt with ZeroSSL as fallback).
    |
    */
    'acme' => [
        'email' => env('POUCH_ACME_EMAIL'),
        'ca' => env('POUCH_ACME_CA'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hostname generation
    |--------------------------------------------------------------------------
    |
    | The proxy base domain is NEVER configured here. It is derived from the
    | Wings FQDN of the node the allocation belongs to; only a node whose FQDN
    | is an IP address may carry an explicit proxy domain, stored per node in
    | pouch_node_settings. Everything else is just the label (the
    | left-most part of the hostname).
    |
    */
    'hostname' => [
        // Maximum length of the slug part before the random suffix is appended.
        'label_slug_length' => 24,
        // Length of the random suffix appended to auto generated labels.
        'suffix_length' => 6,
    ],

    /*
    | Labels that may not be used, e.g. because they are commonly used for
    | other services running on the same base domain.
    */
    'reserved_labels' => [
        'www',
        'wings',
        'daemon',
        'panel',
        'node',
        'admin',
        'api',
        'mail',
        'smtp',
        'imap',
        'ns1',
        'ns2',
        'localhost',
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent
    |--------------------------------------------------------------------------
    |
    | Defaults advertised to node administrators when rendering the agent
    | installation snippet. The agent may override all of these locally.
    |
    */
    'agent' => [
        // Recommended poll interval in seconds.
        'interval' => env('POUCH_AGENT_INTERVAL', 15),
        // Consider a node offline when it has not synced within this many seconds.
        'offline_after' => env('POUCH_AGENT_OFFLINE_AFTER', 60),
        // Container image used in the generated compose snippet.
        'image' => env('POUCH_AGENT_IMAGE', 'ghcr.io/wan0v/pouch-agent:latest'),
    ],
];
