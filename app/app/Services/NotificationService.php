<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Models\BackupResult;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NotificationService
{
    private const WEBHOOK_METHODS = ['get', 'post', 'put', 'patch'];

    /**
     * Send a plain message to all of the user's active channels.
     */
    public function send(User $user, string $message, ?string $subject = null, array $context = []): void
    {
        $user->notificationChannels()
            ->where('is_active', true)
            ->get()
            ->each(fn (NotificationChannel $channel) => $this->deliver($channel, $message, $subject, $context));
    }

    /**
     * Notify the server owner about a backup result, respecting each channel's level.
     */
    public function notifyBackupResult(BackupResult $result): void
    {
        $user = $result->backupServer?->user;
        if (! $user || $result->backupServer->isJobMuted($result->backup_id)) {
            return;
        }

        $status = $result->status;
        $subject = sprintf('[%s] %s - %s', $status->label(), $result->backupServer->name, $result->backup_name);
        $message = $this->describeResult($result);
        $context = [
            'event' => 'backup_result',
            'status' => $status->value,
            'server' => $result->backupServer->name,
            'backup' => $result->backup_name,
            'operation' => $result->MainOperation,
            'end_time' => $result->EndTime?->toIso8601String(),
            'url' => route('backup-detail', $result->id),
        ];

        $user->notificationChannels()
            ->where('is_active', true)
            ->get()
            ->filter(fn (NotificationChannel $channel) => $channel->wantsStatus($status))
            ->each(fn (NotificationChannel $channel) => $this->deliver($channel, $message, $subject, $context));
    }

    /**
     * Notify about a backup job that stopped reporting.
     *
     * @param array{server_name: string, backup_name: string, last_backup_at: ?\Carbon\CarbonInterface} $job
     */
    public function notifyOverdue(User $user, array $job): void
    {
        $subject = sprintf('[Overdue] %s - %s', $job['server_name'], $job['backup_name']);
        $message = sprintf(
            "Backup \"%s\" on %s has not reported since %s.",
            $job['backup_name'],
            $job['server_name'],
            $job['last_backup_at']?->format('Y-m-d H:i') ?? 'unknown',
        );

        $user->notificationChannels()
            ->where('is_active', true)
            ->where('notify_overdue', true)
            ->get()
            ->each(fn (NotificationChannel $channel) => $this->deliver($channel, $message, $subject, [
                'event' => 'backup_overdue',
                'server' => $job['server_name'],
                'backup' => $job['backup_name'],
                'last_backup_at' => $job['last_backup_at']?->toIso8601String(),
            ]));
    }

    /**
     * Send a test message. Throws on failure so the UI can show the error.
     */
    public function sendTest(NotificationChannel $channel): void
    {
        $this->sendToChannel($channel, 'This is a test notification from ' . config('app.name') . '.', 'Test notification', ['event' => 'test']);
    }

    /**
     * Send a summary to the channels that asked for this digest frequency.
     *
     * @param array{subject: string, message: string, context: array} $digest from DigestService::build()
     */
    public function notifyDigest(User $user, string $frequency, array $digest): int
    {
        $channels = $user->notificationChannels()
            ->where('is_active', true)
            ->where('digest', $frequency)
            ->get();

        $channels->each(fn (NotificationChannel $channel) => $this->deliver($channel, $digest['message'], $digest['subject'], $digest['context']));

        return $channels->count();
    }

    private function deliver(NotificationChannel $channel, string $message, ?string $subject, array $context): void
    {
        try {
            $this->sendToChannel($channel, $message, $subject, $context);
        } catch (\Throwable $e) {
            // One broken channel must not stop the others.
            Log::warning('Notification delivery failed', [
                'channel_id' => $channel->id,
                'type' => $channel->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function sendToChannel(NotificationChannel $channel, string $message, ?string $subject = null, array $context = []): void
    {
        match ($channel->type) {
            'email' => $this->sendEmail($channel, $message, $subject),
            'discord' => $this->sendDiscord($channel, $message, $subject),
            'webhook' => $this->sendWebhook($channel, $message, $subject, $context),
            'slack' => $this->sendSlack($channel, $message, $subject),
            'teams' => $this->sendTeams($channel, $message, $subject, $context),
            'telegram' => $this->sendTelegram($channel, $message, $subject),
            'ntfy' => $this->sendNtfy($channel, $message, $subject, $context),
            'pushover' => $this->sendPushover($channel, $message, $subject, $context),
            default => throw new \InvalidArgumentException("Unsupported channel type: {$channel->type}"),
        };
    }

    /** Failures and overdue backups are sent with a higher priority where the service supports it. */
    private function isUrgent(array $context): bool
    {
        if (($context['event'] ?? null) === 'backup_overdue') {
            return true;
        }

        return isset($context['status']) && BackupStatus::fromResult($context['status'])->isFailure();
    }

    protected function sendSlack(NotificationChannel $channel, string $message, ?string $subject): void
    {
        Http::timeout(10)->post($channel->config['webhook_url'], [
            'text' => Str::limit($subject ? "*{$subject}*\n{$message}" : $message, 3900),
        ])->throw();
    }

    /** Microsoft Teams "Workflows" webhook (Post to a channel when a webhook request is received). */
    protected function sendTeams(NotificationChannel $channel, string $message, ?string $subject, array $context): void
    {
        $body = [];
        if ($subject) {
            $body[] = ['type' => 'TextBlock', 'text' => $subject, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true];
        }
        $body[] = ['type' => 'TextBlock', 'text' => Str::limit($message, 20000), 'wrap' => true];

        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => $body,
        ];
        if (! empty($context['url'])) {
            $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => 'Open in ' . config('app.name'), 'url' => $context['url']]];
        }

        Http::timeout(10)->post($channel->config['webhook_url'], [
            'type' => 'message',
            'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => $card]],
        ])->throw();
    }

    protected function sendTelegram(NotificationChannel $channel, string $message, ?string $subject): void
    {
        $config = $channel->config ?? [];

        Http::timeout(10)->post('https://api.telegram.org/bot' . $config['bot_token'] . '/sendMessage', [
            'chat_id' => $config['chat_id'],
            'text' => Str::limit($subject ? "{$subject}\n\n{$message}" : $message, 4000),
            'disable_web_page_preview' => true,
        ])->throw();
    }

    protected function sendNtfy(NotificationChannel $channel, string $message, ?string $subject, array $context): void
    {
        $config = $channel->config ?? [];
        $request = Http::timeout(10);
        if (! empty($config['token'])) {
            $request = $request->withToken($config['token']);
        }

        // JSON publishing: POST to the server root with the topic in the body.
        $request->post(rtrim($config['server_url'] ?? 'https://ntfy.sh', '/'), array_filter([
            'topic' => $config['topic'],
            'title' => $subject,
            'message' => Str::limit($message, 4000),
            'priority' => $this->isUrgent($context) ? 4 : 3,
            'tags' => $this->isUrgent($context) ? ['warning'] : null,
            'click' => $context['url'] ?? null,
        ], fn ($value) => $value !== null))->throw();
    }

    protected function sendPushover(NotificationChannel $channel, string $message, ?string $subject, array $context): void
    {
        $config = $channel->config ?? [];

        Http::timeout(10)->asForm()->post('https://api.pushover.net/1/messages.json', array_filter([
            'token' => $config['app_token'],
            'user' => $config['user_key'],
            'title' => $subject ? Str::limit($subject, 250) : null,
            'message' => Str::limit($message, 1000),
            'priority' => $this->isUrgent($context) ? 1 : 0,
            'url' => $context['url'] ?? null,
        ], fn ($value) => $value !== null))->throw();
    }

    protected function sendEmail(NotificationChannel $channel, string $message, ?string $subject): void
    {
        $config = $channel->config ?? [];
        $subject = $subject ?? ($config['subject'] ?? config('app.name') . ' notification');
        $split = fn ($value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

        $send = function ($mail) use ($config, $subject, $split) {
            $mail->to($split($config['to'] ?? ''))->subject($subject);
            if ($cc = $split($config['cc'] ?? '')) {
                $mail->cc($cc);
            }
            if ($bcc = $split($config['bcc'] ?? '')) {
                $mail->bcc($bcc);
            }
            if (! empty($config['from_email'])) {
                $mail->from($config['from_email']);
            }
        };

        // Per-channel SMTP server, or the application's default mailer.
        $mailer = empty($config['hostname'])
            ? Mail::mailer()
            : Mail::build([
                'transport' => 'smtp',
                'host' => $config['hostname'],
                'port' => (int) ($config['port'] ?? 587),
                'scheme' => ($config['security'] ?? '') === 'tls' ? 'smtps' : 'smtp',
                'username' => $config['username'] ?? null,
                'password' => $config['password'] ?? null,
            ]);

        $mailer->raw($message, $send);
    }

    protected function sendDiscord(NotificationChannel $channel, string $message, ?string $subject): void
    {
        $content = $subject ? "**{$subject}**\n{$message}" : $message;

        Http::timeout(10)->post($channel->config['webhook_url'], [
            'content' => Str::limit($content, 1990),
        ])->throw();
    }

    protected function sendWebhook(NotificationChannel $channel, string $message, ?string $subject, array $context): void
    {
        $config = $channel->config ?? [];
        $method = strtolower($config['method'] ?? 'post');

        if (! in_array($method, self::WEBHOOK_METHODS, true)) {
            throw new \InvalidArgumentException("Unsupported webhook method: {$method}");
        }

        Http::timeout(10)
            ->withHeaders(is_array($config['headers'] ?? null) ? $config['headers'] : [])
            ->{$method}($config['url'], array_merge($context, [
                'subject' => $subject,
                'message' => $message,
            ]))
            ->throw();
    }

    private function describeResult(BackupResult $result): string
    {
        $lines = [
            sprintf('%s of "%s" on %s finished with status %s.', $result->MainOperation, $result->backup_name, $result->backupServer->name, $result->ParsedResult),
            'Finished: ' . ($result->EndTime?->format('Y-m-d H:i') ?? 'unknown'),
            sprintf('Warnings: %d, Errors: %d', $result->WarningsActualLength, $result->ErrorsActualLength),
            'Details: ' . route('backup-detail', $result->id),
        ];

        return implode("\n", $lines);
    }
}
