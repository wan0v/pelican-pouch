<?php

namespace Wan0v\Pouch;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

class PouchPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    /** Where this plugin lives. */
    public const REPO_URL = 'https://github.com/wan0v/pelican-pouch';

    /**
     * Agent installation docs. The `agent/` directory is deliberately not part
     * of the release zip, so the panel has to link the documentation instead of
     * pointing at a local path.
     */
    public const AGENT_DOCS_URL = self::REPO_URL . '/blob/main/agent/README.md';

    public function getId(): string
    {
        return 'pouch';
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettingsFormData(): array
    {
        return [
            'acme_email' => config('pouch.acme.email'),
            'acme_ca' => config('pouch.acme.ca'),
            'agent_interval' => config('pouch.agent.interval'),
            'agent_offline_after' => config('pouch.agent.offline_after'),
            'agent_image' => config('pouch.agent.image'),
        ];
    }

    /**
     * Note: there is deliberately no setting for the proxy base domain. It is
     * always derived from the Wings FQDN of the node an allocation belongs to.
     *
     * @return array<int, Component>
     */
    public function getSettingsForm(): array
    {
        return [
            TextInput::make('acme_email')
                ->label(trans('pouch::strings.settings.acme_email'))
                ->helperText(trans('pouch::strings.settings.acme_email_hint'))
                ->email(),
            TextInput::make('acme_ca')
                ->label(trans('pouch::strings.settings.acme_ca'))
                ->helperText(trans('pouch::strings.settings.acme_ca_hint'))
                ->url()
                ->placeholder('https://acme-v02.api.letsencrypt.org/directory'),
            TextInput::make('agent_interval')
                ->label(trans('pouch::strings.settings.agent_interval'))
                ->numeric()
                ->minValue(5)
                ->maxValue(3600)
                ->required(),
            TextInput::make('agent_offline_after')
                ->label(trans('pouch::strings.settings.agent_offline_after'))
                ->numeric()
                ->required()
                ->minValue(fn (Get $get) => (int) $get('agent_interval') * 2)
                ->maxValue(86400),
            TextInput::make('agent_image')
                ->label(trans('pouch::strings.settings.agent_image'))
                ->required(),
        ];
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'POUCH_ACME_EMAIL' => $data['acme_email'] ?? '',
            'POUCH_ACME_CA' => $data['acme_ca'] ?? '',
            'POUCH_AGENT_INTERVAL' => $data['agent_interval'],
            'POUCH_AGENT_OFFLINE_AFTER' => $data['agent_offline_after'],
            'POUCH_AGENT_IMAGE' => $data['agent_image'],
        ]);

        Notification::make()
            ->title(trans('pouch::strings.settings.saved'))
            ->success()
            ->send();
    }
}
