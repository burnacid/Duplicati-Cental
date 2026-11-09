<x-layouts.app :title="__('Claim Instance')">
    <div class="py-10">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Claim Duplicati Instance</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    You are claiming this instance to your account. After claiming, the instance can be managed from the remote control area.
                </p>

                @if (session('error'))
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300">
                        {{ session('error') }}
                    </div>
                @endif

                <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-zinc-700">
                        <dt class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Machine name</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $registration->machine_name ?: 'Unknown' }}</dd>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-zinc-700">
                        <dt class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Machine id</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $registration->machine_id ?: 'Unavailable' }}</dd>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-zinc-700">
                        <dt class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Version</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $registration->version ?: 'Unknown' }}</dd>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-zinc-700">
                        <dt class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $registration->status }}</dd>
                    </div>
                </dl>

                @if ($isExpired && $registration->status !== \App\Models\RemoteControlRegistration::STATUS_CLAIMED)
                    <div class="mt-6 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800 dark:border-yellow-900/60 dark:bg-yellow-950/40 dark:text-yellow-300">
                        This claim link has expired. Please re-register this instance from Duplicati.
                    </div>
                @else
                    <form method="POST" action="{{ route('remote-control.claim.store', ['claimToken' => $registration->claim_token]) }}" class="mt-6">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            Claim this instance
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>

