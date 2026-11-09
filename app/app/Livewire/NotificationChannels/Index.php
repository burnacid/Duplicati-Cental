<?php

namespace App\Livewire\NotificationChannels;

use App\Models\NotificationChannel;
use App\Services\NotificationService;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public ?int $editingId = null;

    public string $name = '';
    public string $type = 'email';
    public bool $is_active = true;
    public string $notify_on = NotificationChannel::NOTIFY_WARNING;
    public bool $notify_overdue = true;

    /** Type-specific settings, see rules() for the keys per type. */
    public array $config = [];

    public ?int $deleteId = null;
    public ?string $flash = null;
    public ?string $flashVariant = null;

    public function mount(): void
    {
        $this->resetForm();
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('channel-form')->show();
    }

    public function edit(int $id): void
    {
        $channel = $this->findOwned($id);
        $this->authorize('update', $channel);

        $this->resetValidation();
        $this->editingId = $channel->id;
        $this->name = $channel->name;
        $this->type = $channel->type;
        $this->is_active = $channel->is_active;
        $this->notify_on = $channel->notify_on ?? NotificationChannel::NOTIFY_WARNING;
        $this->notify_overdue = (bool) $channel->notify_overdue;
        $this->config = array_merge($this->defaultConfig(), $channel->config ?? []);

        // Never send the stored password back to the browser.
        $this->config['password'] = '';
        $this->config['headers'] = isset($channel->config['headers']) && is_array($channel->config['headers'])
            ? json_encode($channel->config['headers'], JSON_PRETTY_PRINT)
            : '';

        Flux::modal('channel-form')->show();
    }

    public function save(): void
    {
        $this->validate();

        $config = $this->cleanConfig();

        if ($this->editingId) {
            $channel = $this->findOwned($this->editingId);
            $this->authorize('update', $channel);

            // Keep the old SMTP password when the field was left empty.
            if ($this->type === 'email' && ($config['password'] ?? '') === '') {
                $config['password'] = $channel->config['password'] ?? null;
            }

            $channel->update($this->attributes($config));
        } else {
            auth()->user()->notificationChannels()->create($this->attributes($config));
        }

        Flux::modal('channel-form')->close();
        $this->showFlash('Notification channel saved.');
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $channel = $this->findOwned($id);
        $this->authorize('update', $channel);
        $channel->update(['is_active' => ! $channel->is_active]);
    }

    public function sendTest(int $id, NotificationService $notifications): void
    {
        $channel = $this->findOwned($id);
        $this->authorize('update', $channel);

        try {
            $notifications->sendTest($channel);
            $this->showFlash("Test notification sent via \"{$channel->name}\".");
        } catch (\Throwable $e) {
            $this->showFlash('Sending failed: ' . $e->getMessage(), 'danger');
        }
    }

    public function confirmDelete(int $id): void
    {
        $this->deleteId = $id;
        Flux::modal('delete-channel')->show();
    }

    public function delete(): void
    {
        if ($this->deleteId) {
            $channel = $this->findOwned($this->deleteId);
            $this->authorize('delete', $channel);
            $channel->delete();
            $this->showFlash('Notification channel deleted.');
        }

        $this->deleteId = null;
        Flux::modal('delete-channel')->close();
    }

    protected function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(array_keys(config('notification_channels.available_types')))],
            'is_active' => 'boolean',
            'notify_on' => ['required', Rule::in([NotificationChannel::NOTIFY_ALL, NotificationChannel::NOTIFY_WARNING, NotificationChannel::NOTIFY_ERROR])],
            'notify_overdue' => 'boolean',
        ];

        return $rules + match ($this->type) {
            'email' => [
                'config.to' => 'required|string|max:1000',
                'config.cc' => 'nullable|string|max:1000',
                'config.bcc' => 'nullable|string|max:1000',
                'config.from_email' => 'nullable|email|max:255',
                'config.hostname' => 'nullable|string|max:255',
                'config.port' => 'nullable|integer|between:1,65535',
                'config.security' => 'nullable|in:none,starttls,tls',
                'config.username' => 'nullable|string|max:255',
                'config.password' => 'nullable|string|max:255',
            ],
            'discord' => [
                'config.webhook_url' => 'required|url:https|max:1000',
            ],
            'webhook' => [
                'config.url' => 'required|url:http,https|max:1000',
                'config.method' => 'required|in:GET,POST,PUT,PATCH',
                'config.headers' => 'nullable|json',
            ],
            default => [],
        };
    }

    protected function validationAttributes(): array
    {
        return [
            'config.to' => 'recipient',
            'config.webhook_url' => 'webhook URL',
            'config.url' => 'URL',
            'config.headers' => 'headers',
        ];
    }

    public function render()
    {
        return view('livewire.notification-channels.index', [
            'channels' => auth()->user()->notificationChannels()->orderBy('name')->get(),
            'types' => config('notification_channels.available_types'),
        ]);
    }

    private function attributes(array $config): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'is_active' => $this->is_active,
            'notify_on' => $this->notify_on,
            'notify_overdue' => $this->notify_overdue,
            'config' => $config,
        ];
    }

    /** Only keep the settings that belong to the selected type. */
    private function cleanConfig(): array
    {
        $keys = match ($this->type) {
            'email' => ['to', 'cc', 'bcc', 'from_email', 'hostname', 'port', 'security', 'username', 'password'],
            'discord' => ['webhook_url'],
            'webhook' => ['url', 'method', 'headers'],
            default => [],
        };

        $config = array_intersect_key($this->config, array_flip($keys));

        if ($this->type === 'webhook') {
            $config['headers'] = filled($config['headers'] ?? null) ? json_decode($config['headers'], true) : [];
        }

        return $config;
    }

    private function defaultConfig(): array
    {
        return [
            'to' => '', 'cc' => '', 'bcc' => '', 'from_email' => '',
            'hostname' => '', 'port' => 587, 'security' => 'starttls', 'username' => '', 'password' => '',
            'webhook_url' => '',
            'url' => '', 'method' => 'POST', 'headers' => '',
        ];
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->name = '';
        $this->type = 'email';
        $this->is_active = true;
        $this->notify_on = NotificationChannel::NOTIFY_WARNING;
        $this->notify_overdue = true;
        $this->config = $this->defaultConfig();
    }

    private function findOwned(int $id): NotificationChannel
    {
        return auth()->user()->notificationChannels()->findOrFail($id);
    }

    private function showFlash(string $message, string $variant = 'success'): void
    {
        $this->flash = $message;
        $this->flashVariant = $variant;
    }
}
