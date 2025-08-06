<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupServer;
use App\Models\BackupResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BackupController extends Controller
{
    public function storeBackupResult(Request $request, $serverId, $apiKey)
    {
        $server = BackupServer::where('id', $serverId)
            ->where('api_key', $apiKey)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            '0' => 'required|array',
            '0.Data' => 'required|array',
            '0.Data.DeletedFiles' => 'required|integer',
            '0.Data.DeletedFolders' => 'required|integer',
            '0.Data.ModifiedFiles' => 'required|integer',
            '0.Data.ExaminedFiles' => 'required|integer',
            '0.Data.OpenedFiles' => 'required|integer',
            '0.Data.AddedFiles' => 'required|integer',
            '0.Data.SizeOfModifiedFiles' => 'required|integer',
            '0.Data.SizeOfAddedFiles' => 'required|integer',
            '0.Data.SizeOfExaminedFiles' => 'required|integer',
            '0.Data.SizeOfOpenedFiles' => 'required|integer',
            '0.Data.NotProcessedFiles' => 'required|integer',
            '0.Data.AddedFolders' => 'required|integer',
            '0.Data.TooLargeFiles' => 'required|integer',
            '0.Data.FilesWithError' => 'required|integer',
            '0.Data.ModifiedFolders' => 'required|integer',
            '0.Data.ModifiedSymlinks' => 'required|integer',
            '0.Data.AddedSymlinks' => 'required|integer',
            '0.Data.DeletedSymlinks' => 'required|integer',
            '0.Data.PartialBackup' => 'required|boolean',
            '0.Data.Dryrun' => 'required|boolean',
            '0.Data.MainOperation' => 'required|string',
            '0.Data.CompactResults' => 'required|array',
            '0.Data.TestResults' => 'required|array',
            '0.Data.ParsedResult' => 'required|string',
            '0.Data.Interrupted' => 'required|boolean',
            '0.Data.Version' => 'required|string',
            '0.Data.EndTime' => 'required|date',
            '0.Data.BeginTime' => 'required|date',
            '0.Data.Duration' => 'required|string',
            '0.Data.MessagesActualLength' => 'required|integer',
            '0.Data.WarningsActualLength' => 'required|integer',
            '0.Data.ErrorsActualLength' => 'required|integer',
            '0.Data.BackendStatistics' => 'required|array',
            '0.Extra' => 'required|array',
            '0.Extra.OperationName' => 'required|string',
            '0.Extra.machine-id' => 'required|string',
            '0.Extra.machine-name' => 'required|string',
            '0.Extra.backup-name' => 'required|string',
            '0.Extra.backup-id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->input('0');

        // Store the backup result
        $backupResult = new BackupResult();
        $backupResult->backup_server_id = $server->id;
        $backupResult->fill($data['Data']);
        $backupResult->extra = $data['Extra'];
        $backupResult->save();

        return response()->json([
            'message' => 'Backup result received successfully',
            'data' => $backupResult
        ], 201);
    }
}
