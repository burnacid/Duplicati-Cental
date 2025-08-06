<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupServer;
use App\Models\BackupResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BackupController extends Controller
{
    public function storeBackupResult(Request $request, $serverId, $apiKey)
    {
        Log::info($request->all());

        $server = BackupServer::where('id', $serverId)
            ->where('api_key', $apiKey)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'Data' => 'required|array',
            'Data.DeletedFiles' => 'required|integer',
            'Data.DeletedFolders' => 'required|integer',
            'Data.ModifiedFiles' => 'required|integer',
            'Data.ExaminedFiles' => 'required|integer',
            'Data.OpenedFiles' => 'required|integer',
            'Data.AddedFiles' => 'required|integer',
            'Data.SizeOfModifiedFiles' => 'required|integer',
            'Data.SizeOfAddedFiles' => 'required|integer',
            'Data.SizeOfExaminedFiles' => 'required|integer',
            'Data.SizeOfOpenedFiles' => 'required|integer',
            'Data.NotProcessedFiles' => 'required|integer',
            'Data.AddedFolders' => 'required|integer',
            'Data.TooLargeFiles' => 'required|integer',
            'Data.FilesWithError' => 'required|integer',
            'Data.ModifiedFolders' => 'required|integer',
            'Data.ModifiedSymlinks' => 'required|integer',
            'Data.AddedSymlinks' => 'required|integer',
            'Data.DeletedSymlinks' => 'required|integer',
            'Data.PartialBackup' => 'required|boolean',
            'Data.Dryrun' => 'required|boolean',
            'Data.MainOperation' => 'required|string',
            'Data.CompactResults' => 'nullable',
            'Data.VacuumResults' => 'nullable',
            'Data.DeleteResults' => 'nullable',
            'Data.RepairResults' => 'nullable',
            'Data.TestResults' => 'required|array',
            'Data.TestResults.MainOperation' => 'required|string',
            'Data.TestResults.VerificationsActualLength' => 'required|integer',
            'Data.TestResults.Verifications' => 'required|array',
            'Data.ParsedResult' => 'required|string',
            'Data.Interrupted' => 'required|boolean',
            'Data.Version' => 'required|string',
            'Data.EndTime' => 'required|date',
            'Data.BeginTime' => 'required|date',
            'Data.Duration' => 'required|string',
            'Data.MessagesActualLength' => 'required|integer',
            'Data.WarningsActualLength' => 'required|integer',
            'Data.ErrorsActualLength' => 'required|integer',
            'Data.BackendStatistics' => 'required|array',
            'Extra' => 'required|array',
            'Extra.OperationName' => 'required|string',
            'Extra.machine-id' => 'required|string',
            'Extra.machine-name' => 'required|string',
            'Extra.backup-name' => 'required|string',
            'Extra.backup-id' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::info('Validation failed');
            Log::info($validator->errors());
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->input('Data');
        $extra = $request->input('Extra');

        // Store the backup result
        $backupResult = new BackupResult();
        $backupResult->backup_server_id = $server->id;
        $backupResult->fill($data);
        $backupResult->extra = $extra;
        $backupResult->save();

        return response()->json([
            'message' => 'Backup result received successfully',
            'data' => $backupResult
        ], 201);
    }
}
