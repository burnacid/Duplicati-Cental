<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PingHeartbeat;
use App\Jobs\SendBackupResultNotification;
use App\Models\BackupServer;
use App\Models\EventLog;
use App\Services\BackupResultFactory;
use App\Services\EventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BackupController extends Controller
{
    public function storeBackupResult(Request $request, string $serverId, string $apiKey, BackupResultFactory $factory, EventLogger $events): JsonResponse
    {
        $server = BackupServer::query()->find($serverId);

        if (! $server) {
            // Not tied to any user, so it can only go to laravel.log.
            Log::warning('Backup report for unknown server', ['server_id' => Str::limit($serverId, 64), 'ip' => $request->ip()]);
            abort(404);
        }

        if (! hash_equals((string) $server->api_key, $apiKey)) {
            $events->forServer($server, EventLog::CATEGORY_REPORT, EventLog::LEVEL_WARNING,
                'Report refused: wrong API key. Copy the URL again from Backup servers.', $this->requestContext($request));
            abort(404);
        }

        $validator = Validator::make($request->all(), [
            'Data' => 'required|array',
            'Data.MainOperation' => 'required|string|max:64',
            'Data.ParsedResult' => 'required|string|max:32',
            'Data.Interrupted' => 'nullable|boolean',
            'Data.Version' => 'nullable|string|max:120',
            'Data.EndTime' => 'required|date',
            'Data.BeginTime' => 'required|date',
            'Data.Duration' => 'nullable|string|max:64',
            'Data.MessagesActualLength' => 'nullable|integer',
            'Data.WarningsActualLength' => 'nullable|integer',
            'Data.ErrorsActualLength' => 'nullable|integer',
            'Data.BackendStatistics' => 'nullable|array',
            'Extra' => 'required|array',
            'Extra.OperationName' => 'nullable|string|max:64',
            'Extra.machine-id' => 'required|string|max:255',
            'Extra.machine-name' => 'required|string|max:255',
            'Extra.backup-name' => 'required|string|max:255',
            'Extra.backup-id' => 'required|string|max:255',
            'LogLines' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            Log::warning('Backup result rejected', [
                'backup_server_id' => $server->id,
                'errors' => $validator->errors()->toArray(),
            ]);

            $events->forServer($server, EventLog::CATEGORY_REPORT, EventLog::LEVEL_ERROR,
                'Report rejected: '.$this->rejectionReason($request),
                $this->requestContext($request) + [
                    'errors' => $validator->errors()->all(),
                    'received_keys' => array_slice(array_map('strval', array_keys($request->all())), 0, 20),
                    'body_preview' => Str::limit((string) $request->getContent(), 1000),
                ]);

            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->input('Data');
        $extra = $request->input('Extra');
        $operation = $data['MainOperation'];
        $jobName = (string) ($extra['backup-name'] ?? '');

        if ($factory->isIgnoredOperation($operation)) {
            $events->forServer($server, EventLog::CATEGORY_REPORT, EventLog::LEVEL_INFO,
                "{$operation} report for \"{$jobName}\" ignored (list operations are not stored)", $this->requestContext($request));

            return response()->json(['message' => 'List operations are not stored'], 200);
        }

        $backupResult = $factory->make($server, $data, $extra, $request->input('LogLines', []) ?? []);
        $backupResult->save();

        $status = $backupResult->status;
        $events->forServer($server, EventLog::CATEGORY_REPORT, $status->isFailure() ? EventLog::LEVEL_WARNING : EventLog::LEVEL_INFO,
            "{$operation} report for \"{$jobName}\" stored: {$data['ParsedResult']}",
            $this->requestContext($request) + ['backup_result_id' => $backupResult->id]);

        SendBackupResultNotification::dispatch($backupResult->id);
        if (PingHeartbeat::shouldPing($backupResult)
            && $server->jobSettings()->where('backup_id', $backupResult->backup_id)->whereNotNull('heartbeat_url')->exists()) {
            PingHeartbeat::dispatch($backupResult->id);
        }

        return response()->json([
            'message' => ucfirst($operation).' result received successfully',
            'id' => $backupResult->id,
        ], 201);
    }

    /** A short, actionable reason for the most common setup mistakes. */
    private function rejectionReason(Request $request): string
    {
        if ($request->getContent() === '' && $request->all() === []) {
            return 'the request was empty.';
        }

        if (! $request->isJson()) {
            return 'Duplicati sent the report as form data instead of JSON. Add --send-http-result-output-format=Json to the job or default options, or use --send-http-json-urls instead of --send-http-url.';
        }

        if (! is_array($request->input('Data'))) {
            return 'the report has no result data ("Data"). Check that the job did not stop before it started.';
        }

        return 'required fields are missing or invalid (see details).';
    }

    /** @return array<string, mixed> */
    private function requestContext(Request $request): array
    {
        return [
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 200),
            'content_type' => Str::limit((string) $request->header('Content-Type'), 100),
            'size_bytes' => strlen((string) $request->getContent()),
        ];
    }
}
