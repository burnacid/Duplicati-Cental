<?php

namespace App\Livewire\RemoteControl;

use App\Models\RemoteControlRegistration;
use App\Services\RemoteControlUrlService;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';
    public string $bridgeWebsocketUrl = '';
    public string $registrationUrl = '';

    public function mount(RemoteControlUrlService $urlService): void
    {
        $this->bridgeWebsocketUrl = $urlService->websocketUrl(request());
        $this->registrationUrl = $urlService->appBaseUrl(request()) . '/api/remote-control/register';
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function removeRegistration(string $registrationId): void
    {
        $registration = RemoteControlRegistration::query()
            ->whereKey($registrationId)
            ->where('claimed_by_user_id', auth()->id())
            ->first();

        if (! $registration) {
            return;
        }

        $registration->delete();
        session()->flash('status', 'Remote controlled server removed.');
        $this->resetPage();
    }

    public function render()
    {
        $registrations = RemoteControlRegistration::query()
            ->with('agentState')
            ->where('claimed_by_user_id', auth()->id())
            ->when($this->search !== '', function ($query) {
                $query->where(function ($inner) {
                    $inner->where('machine_name', 'like', '%' . $this->search . '%')
                        ->orWhere('machine_id', 'like', '%' . $this->search . '%')
                        ->orWhere('version', 'like', '%' . $this->search . '%');
                });
            })
            ->latest('claimed_at')
            ->paginate(10);

        return view('livewire.remote-control.index', [
            'registrations' => $registrations,
        ]);
    }
}

