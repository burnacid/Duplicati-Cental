<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PingHeartbeat;
use App\Jobs\SendBackupResultNotification;
use App\Models\BackupServer;
use App\Services\BackupResultFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BackupController extends Controller
{
    public function storeBackupResult(Request $request, string $serverId, string $apiKey, BackupResultFactory $factory): JsonResponse
    {
        $server = BackupServer::where('id', $serverId)
            ->where('api_key', $apiKey)
            ->firstOrFail();

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
            Log::info('Backup result rejected', [
                'backup_server_id' => $server->id,
                'errors' => $validator->errors()->toArray(),
            ]);

            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->input('Data');
        $operation = $data['MainOperation'];

        if ($factory->isIgnoredOperation($operation)) {
            return response()->json(['message' => 'List operations are not stored'], 200);
        }

        $backupResult = $factory->make($server, $data, $request->input('Extra'), $request->input('LogLines', []) ?? []);
        $backupResult->save();

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
}
