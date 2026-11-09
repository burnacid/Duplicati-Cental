{{--
    "Sign in with passkey". Uses spatie/laravel-passkeys routes; the WebAuthn
    browser calls come from @simplewebauthn/browser (resources/js/app.js).
--}}
<div x-data="passkeyLogin()" class="flex flex-col gap-3">
    <flux:separator text="{{ __('or') }}" />

    <form x-ref="form" method="POST" action="{{ route('passkeys.login') }}" class="hidden">
        @csrf
        <input type="hidden" name="start_authentication_response" x-ref="response">
        <input type="hidden" name="remember" x-ref="remember" value="0">
    </form>

    <flux:button type="button" icon="finger-print" class="w-full" x-on:click="signIn()" x-bind:disabled="busy || !supported">
        <span x-text="busy ? '{{ __('Waiting for passkey…') }}' : '{{ __('Sign in with passkey') }}'">{{ __('Sign in with passkey') }}</span>
    </flux:button>

    @if ($message = session('authenticatePasskey::message'))
        <p class="text-sm text-red-600 dark:text-red-400 text-center">{{ $message }}</p>
    @endif
    <p x-show="error" x-text="error" x-cloak class="text-sm text-red-600 dark:text-red-400 text-center"></p>
    <p x-show="!supported" x-cloak class="text-xs text-zinc-500 text-center" x-text="unsupportedReason"></p>
</div>

@once
<script>
    function passkeyLogin() {
        const secure = window.isSecureContext;
        const available = typeof window.PublicKeyCredential !== 'undefined';

        return {
            busy: false,
            error: null,
            supported: secure && available,
            unsupportedReason: !secure
                ? @js(__('Passkeys need HTTPS (or localhost). Open this site over HTTPS to use them.'))
                : @js(__('This browser does not support passkeys.')),

            async signIn() {
                this.error = null;
                this.busy = true;

                try {
                    const response = await fetch(@js(route('passkeys.authentication_options')), {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (!response.ok) throw new Error('options');

                    const options = await response.json();
                    const assertion = await window.startAuthentication({ optionsJSON: options });

                    this.$refs.response.value = JSON.stringify(assertion);
                    this.$refs.form.submit();
                } catch (e) {
                    this.busy = false;
                    this.error = e && e.name === 'NotAllowedError'
                        ? @js(__('Passkey sign-in was cancelled or timed out.'))
                        : @js(__('Passkey sign-in failed. Try again or use your password.'));
                }
            },
        };
    }
</script>
@endonce
