<?php

namespace App\Livewire;

use App\Livewire\Concerns\FormatsBackupValues;
use App\Models\BackupResult;
use App\Models\RemoteControlRegistration;
use Livewire\Component;

class BackupDetail extends Component
{
    use FormatsBackupValues;

    public BackupResult $backupResult;
    public ?RemoteControlRegistration $claimedRegistration = null;

    public function mount($id)
    {
        $this->backupResult = BackupResult::forUser(auth()->user())->with('backupServer')->findOrFail($id);

        if (config('remote_control.enabled') && $this->backupResult->machine_id) {
            $this->claimedRegistration = RemoteControlRegistration::query()
                ->where('claimed_by_user_id', auth()->id())
                ->where('status', RemoteControlRegistration::STATUS_CLAIMED)
                ->where('machine_id', $this->backupResult->machine_id)
                ->first();
        }
    }

    public function render()
    {
        return view('livewire.backup-detail');
    }
}
