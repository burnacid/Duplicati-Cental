<?php

namespace App\Services;

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
        if (! $user) {
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
            default => throw new \InvalidArgumentException("Unsupported channel type: {$channel->type}"),
        };
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
