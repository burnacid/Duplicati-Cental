<x-layouts.app :title="__('Notification Channels')">
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-zinc-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 border-b border-gray-200 dark:border-zinc-700">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-semibold text-zinc-800 dark:text-white">{{ __('Notification Channels') }}</h2>
                        <flux:button :href="route('notification-channels.create')" variant="primary">
                            {{ __('Create New Channel') }}
                        </flux:button>
                    </div>

                    @if($channels->isEmpty())
                        <p class="text-zinc-600 dark:text-zinc-400">{{ __('No notification channels found.') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                <thead class="bg-zinc-50 dark:bg-zinc-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-300 uppercase tracking-wider">{{ __('Name') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-300 uppercase tracking-wider">{{ __('Type') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-300 uppercase tracking-wider">{{ __('Actions') }}</th>
                                </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-zinc-800 divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach($notificationChannels as $channel)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-zinc-800 dark:text-zinc-200">{{ $channel->name }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-zinc-800 dark:text-zinc-200">{{ $channel->type }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <flux:button :href="route('notification-channels.edit', $channel)" variant="secondary" size="sm" class="mr-2">
                                                {{ __('Edit') }}
                                            </flux:button>
                                            <form action="{{ route('notification-channels.destroy', $channel) }}" method="POST" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <flux:button type="submit" variant="danger" size="sm" onclick="return confirm('{{ __('Are you sure you want to delete this channel?') }}')">
                                                    {{ __('Delete') }}
                                                </flux:button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
