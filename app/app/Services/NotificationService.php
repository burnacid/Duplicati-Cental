<?php

namespace App\Services;

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function send(User $user, $message)
    {
        $channels = $user->notificationChannels()->where('is_active', true)->get();

        if ($channels->isEmpty()) {
            // Handle the case where the user has no active notification channels
            // You might want to log this or handle it in some way
            return;
        }

        foreach ($channels as $channel) {
            $this->sendToChannel($channel, $message);
        }
    }

    protected function sendToChannel(NotificationChannel $channel, $message)
    {
        switch ($channel->type) {
            case 'email':
                $this->sendEmail($channel, $message);
                break;
            case 'discord':
                $this->sendDiscord($channel, $message);
                break;
            case 'webhook':
                $this->sendWebhook($channel, $message);
                break;
            // Add more cases for other channel types
        }
    }

    protected function sendEmail(NotificationChannel $channel, $message)
    {
        $config = $channel->config;
        Mail::raw($message, function ($mail) use ($config) {
            $mail->to($config['to'])
                ->subject($config['subject']);
        });
    }

    protected function sendDiscord(NotificationChannel $channel, $message)
    {
        $webhookUrl = $channel->config['webhook_url'];
        Http::post($webhookUrl, [
            'content' => $message,
        ]);
    }

    protected function sendWebhook(NotificationChannel $channel, $message)
    {
        $config = $channel->config;
        Http::withHeaders($config['headers'] ?? [])
            ->{strtolower($config['method'])}($config['url'], ['message' => $message]);
    }
}
