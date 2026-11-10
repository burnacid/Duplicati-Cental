<?php

namespace App\Livewire\Settings;

use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → API tokens: personal access tokens for the read-only API and /metrics.
 * A new token is shown once; only its hash is stored.
 */
#[Title('API tokens')]
class ApiTokens extends Component
{
    public const EXPIRY_OPTIONS = ['30' => '30 days', '90' => '90 days', '365' => '1 year', 'never' => 'Never'];

    public string $name = '';

    public string $expiresIn = '365';

    /** Plain token, shown once after creating it. */
    public ?string $plainToken = null;

    public function createToken(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'expiresIn' => ['required', Rule::in(array_keys(self::EXPIRY_OPTIONS))],
        ]);

        $expiresAt = $this->expiresIn === 'never' ? null : now()->addDays((int) $this->expiresIn);
        $token = auth()->user()->createToken(trim($this->name), ['read'], $expiresAt);

        $this->plainToken = $token->plainTextToken;
        $this->reset('name', 'expiresIn');
        Flux::modal('new-token')->show();
    }

    public function revoke(int $tokenId): void
    {
        // Only the user's own tokens.
        auth()->user()->tokens()->whereKey($tokenId)->delete();
    }

    public function forgetPlainToken(): void
    {
        $this->plainToken = null;
    }

    public function render()
    {
        return view('livewire.settings.api-tokens', [
            'tokens' => auth()->user()->tokens()->latest()->get(),
        ]);
    }
}
