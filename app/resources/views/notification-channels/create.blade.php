<x-layouts.app :title="__('Create Notification Channel')">
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-zinc-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 border-b border-gray-200 dark:border-zinc-700">
                    <form action="{{ route('notification-channels.store') }}" method="POST">
                        @csrf
                        <div class="mb-4">
                            <flux:input
                                name="name"
                                id="name"
                                :label="__('Name')"
                                required
                            />
                        </div>
                        <div class="mb-4">
                            <flux:select
                                name="type"
                                id="type"
                                :label="__('Type')"
                                required
                                x-on:change="updateConfigFields()"
                            >
                                @foreach($types as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </flux:select>
                        </div>

                        <div id="emailFields" class="hidden space-y-4">
                            <flux:input
                                name="hostname"
                                :label="__('Hostname')"
                                :description="__('The SMTP server hostname, e.g., smtp.gmail.com')"
                            />
                            <flux:input
                                name="port"
                                type="number"
                                :label="__('Port')"
                                :description="__('The SMTP server port, typically 587 for TLS or 465 for SSL')"
                                min="1"
                                max="65535"
                            />
                            <flux:select
                                name="security"
                                :label="__('Security')"
                                :description="__('The encryption method used by the SMTP server')"
                            >
                                <option value="none">{{ __('None') }}</option>
                                <option value="starttls">{{ __('STARTTLS') }}</option>
                                <option value="tls">{{ __('TLS') }}</option>
                            </flux:select>
                            <flux:checkbox
                                name="requires_auth"
                                :label="__('Requires Authentication')"
                                :description="__('Check if the SMTP server requires username and password')"
                            />
                            <flux:input
                                name="username"
                                :label="__('Username')"
                                :description="__('The username for SMTP authentication, often your email address')"
                            />
                            <flux:input
                                name="password"
                                type="password"
                                :label="__('Password')"
                                :description="__('The password for SMTP authentication')"
                            />
                            <flux:input
                                name="from_email"
                                type="email"
                                :label="__('FROM Email')"
                                :description="__('The email address that will appear in the From field of the sent email')"
                            />
                            <flux:input
                                name="to_email"
                                type="email"
                                :label="__('TO Email')"
                                :description="__('The primary recipient email address')"
                            />
                            <flux:input
                                name="cc"
                                type="email"
                                :label="__('CC')"
                                :description="__('Carbon copy recipients (optional, separate multiple addresses with commas)')"
                            />
                            <flux:input
                                name="bcc"
                                type="email"
                                :label="__('BCC')"
                                :description="__('Blind carbon copy recipients (optional, separate multiple addresses with commas)')"
                            />
                        </div>

                        <div id="otherConfigField" class="mb-4">
                            <flux:textarea
                                name="config"
                                id="config"
                                :label="__('Configuration')"
                                rows="4"
                                required
                            />
                            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Enter configuration as JSON') }}</p>
                        </div>
                        <div>
                            <flux:button type="submit" variant="primary">
                                {{ __('Create Channel') }}
                            </flux:button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            function updateConfigFields() {
                console.log('Updating config fields');
                const type = document.getElementById('type').value;
                const emailFields = document.getElementById('emailFields');
                const otherConfigField = document.getElementById('otherConfigField');

                if (type === 'email') {
                    emailFields.classList.remove('hidden');
                    otherConfigField.classList.add('hidden');
                } else {
                    emailFields.classList.add('hidden');
                    otherConfigField.classList.remove('hidden');
                }
            }

            // Call the function on page load to set the initial state
            document.addEventListener('DOMContentLoaded', updateConfigFields);

            document.getElementById('type').addEventListener('change', updateConfigFields);
        </script>
    @endpush
</x-layouts.app>
