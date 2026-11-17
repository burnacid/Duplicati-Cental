<?php

namespace App\Services;

use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use RuntimeException;

class RemoteControlCommandService
{
    /**
     * Queue an HTTP request for the instance's local Duplicati API; the websocket server sends it.
     *
     * @param  int|null  $timeoutSeconds  how long to wait for an answer (default 30 s)
     */
    public function dispatch(
        RemoteControlRegistration $registration,
        string $method,
        string $path,
        ?array $jsonBody = null,
        ?string $requestedByUserId = null,
        ?int $timeoutSeconds = null,
    ): RemoteControlCommand {
        return RemoteControlCommand::query()->create([
            'remote_control_registration_id' => $registration->id,
            'requested_by_user_id' => $requestedByUserId,
            'method' => $method,
            'path' => $path,
            'request_body' => $jsonBody === null ? null : json_encode($jsonBody),
            'request_headers' => $jsonBody === null ? null : ['Content-Type' => 'application/json'],
            'status' => RemoteControlCommand::STATUS_PENDING,
            'timeout_seconds' => $timeoutSeconds,
        ]);
    }

    /**
     * Send a request and wait for the answer (for queue jobs and commands, never for web requests).
     *
     * @return array{0: int|null, 1: mixed} HTTP status and decoded JSON body
     *
     * @throws RuntimeException when the instance does not answer in time
     */
    public function dispatchAndWait(
        RemoteControlRegistration $registration,
        string $method,
        string $path,
        ?array $jsonBody = null,
        int $timeoutSeconds = 45,
    ): array {
        $command = $this->dispatch($registration, $method, $path, $jsonBody, timeoutSeconds: $timeoutSeconds);
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            usleep(300_000);
            $command = RemoteControlCommand::query()->find($command->id);

            if ($command === null) {
                throw new RuntimeException('The request was lost.');
            }

            if ($command->isFinished()) {
                if ($command->status !== RemoteControlCommand::STATUS_COMPLETED) {
                    throw new RuntimeException($command->error_message ?: 'The instance did not answer.');
                }

                return [$command->response_status_code, json_decode((string) $command->response_body, true)];
            }
        }

        throw new RuntimeException('Timed out waiting for the instance.');
    }
}
