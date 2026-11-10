<?php

namespace App\Livewire\Settings;

use App\Services\SystemHealthService;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Health: status of the installation (no user data, so every user may see it).
 */
#[Title('Health')]
class Health extends Component
{
    public function render(SystemHealthService $health)
    {
        $checks = $health->checks();

        return view('livewire.settings.health', [
            'checks' => $checks,
            'overall' => $health->overall($checks),
        ]);
    }
}
