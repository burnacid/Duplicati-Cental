<?php

namespace App\Livewire\RemoteControl\Concerns;

use App\Models\RemoteControlCommand;
use App\Services\RemoteControlCommandService;
use Illuminate\Support\Facades\Auth;

/**
 * One-off requests to the instance whose answers arrive later (the websocket server fills
 * in the response). request() queues one under a key; every poll hands finished ones to
 * onRemoteResponse(). Commands are always looked up within this page's registration.
 */
trait AwaitsRemoteCommands
{
    /** @var array<string, string> key => command id */
    public array $requests = [];

    protected function request(string $key, string $method, string $path, ?array $body = null, ?int $timeoutSeconds = null): void
    {
        $this->requests[$key] = app(RemoteControlCommandService::class)
            ->dispatch($this->registration, $method, $path, $body, Auth::id(), $timeoutSeconds)
            ->id;
    }

    protected function isWaitingFor(string $key): bool
    {
        return isset($this->requests[$key]);
    }

    protected function collectRequests(): void
    {
        foreach ($this->requests as $key => $commandId) {
            $command = $this->findCommand($commandId);

            if ($command !== null && ! $command->isFinished()) {
                continue;
            }

            unset($this->requests[$key]);

            if ($command === null) {
                $this->onRemoteResponse($key, null, [], 'The request to the instance was lost.');
            } elseif ($command->status !== RemoteControlCommand::STATUS_COMPLETED) {
                $this->onRemoteResponse($key, null, [], $command->error_message ?: 'The instance did not respond in time.');
            } else {
                $body = json_decode((string) $command->response_body, true);
                $body = is_array($body) ? $body : [];
                $code = $command->response_status_code;
                $ok = $code >= 200 && $code < 300 && ($body['Success'] ?? true) !== false;

                $this->onRemoteResponse($key, $code, $body, $ok ? null : self::remoteError($code, $body));
            }
        }
    }

    /** A command of this page's instance (never another registration's). */
    protected function findCommand(?string $commandId): ?RemoteControlCommand
    {
        if ($commandId === null) {
            return null;
        }

        return RemoteControlCommand::query()
            ->where('remote_control_registration_id', $this->registration->id)
            ->find($commandId);
    }

    public static function remoteError(?int $code, array $body): string
    {
        if ($code === 404 && ! isset($body['Error']) && ! isset($body['error'])) {
            return 'This Duplicati version does not support this (HTTP 404). Update Duplicati and try again.';
        }

        $message = $body['Error'] ?? $body['error'] ?? $body['Message'] ?? $body['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : "Duplicati answered with HTTP {$code}.";
    }

    /**
     * @param  string|null  $error  null when the request succeeded
     */
    abstract protected function onRemoteResponse(string $key, ?int $code, array $body, ?string $error): void;
}
