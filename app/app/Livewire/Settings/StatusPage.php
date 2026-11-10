<?php

namespace App\Livewire\Settings;

use App\Services\StatusPageService;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Status page: turn the public page on or off, set its title and replace its link.
 * Jobs are added to it in Backup Management → Settings.
 */
#[Title('Status page')]
class StatusPage extends Component
{
    public string $title = '';

    public function mount(): void
    {
        $this->title = (string) auth()->user()->status_page_title;
    }

    public function enable(): void
    {
        $this->newToken();
    }

    /** New link; the old one stops working. */
    public function regenerate(): void
    {
        $this->newToken();
    }

    public function disable(): void
    {
        auth()->user()->forceFill(['status_page_token' => null])->save();
    }

    public function saveTitle(): void
    {
        $this->validate(['title' => ['nullable', 'string', 'max:100']]);

        auth()->user()->forceFill(['status_page_title' => trim($this->title) ?: null])->save();
        $this->dispatch('status-page-saved');
    }

    public function render(StatusPageService $statusPage)
    {
        $user = auth()->user();

        return view('livewire.settings.status-page', [
            'url' => $user->hasStatusPage() ? route('status-page', $user->status_page_token) : null,
            'jobs' => $statusPage->jobs($user),
        ]);
    }

    private function newToken(): void
    {
        auth()->user()->forceFill(['status_page_token' => Str::random(48)])->save();
    }
}
