<?php

namespace App\Http\Controllers;

use App\Models\NotificationChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class NotificationChannelController extends Controller
{
    public function index()
    {
        $channels = auth()->user()->notificationChannels;
        return view('notification-channels.index', compact('channels'));
    }

    public function create()
    {
        $types = Config::get('notification_channels.available_types');
        return view('notification-channels.create', compact('types'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:' . implode(',', array_keys(Config::get('notification_channels.available_types'))),
            'config' => 'required|array',
        ]);

        $channel = auth()->user()->notificationChannels()->create($validated);

        return redirect()->route('notification-channels.index')->with('success', 'Notification channel created successfully.');
    }

    public function edit(NotificationChannel $notificationChannel)
    {
        $this->authorize('update', $notificationChannel);
        $types = Config::get('notification_channels.available_types');
        return view('notification-channels.edit', compact('notificationChannel', 'types'));
    }

    public function update(Request $request, NotificationChannel $notificationChannel)
    {
        $this->authorize('update', $notificationChannel);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:' . implode(',', array_keys(Config::get('notification_channels.available_types'))),
            'config' => 'required|array',
            'is_active' => 'boolean',
        ]);

        $notificationChannel->update($validated);

        return redirect()->route('notification-channels.index')->with('success', 'Notification channel updated successfully.');
    }

    public function destroy(NotificationChannel $notificationChannel)
    {
        $this->authorize('delete', $notificationChannel);

        $notificationChannel->delete();

        return redirect()->route('notification-channels.index')->with('success', 'Notification channel deleted successfully.');
    }
}
