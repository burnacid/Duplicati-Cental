<?php

namespace App\Services;

use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;

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
}
