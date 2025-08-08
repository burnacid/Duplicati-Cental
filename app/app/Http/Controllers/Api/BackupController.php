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
            'Data.MainOperation' => 'required|string|in:Backup,Restore,Test',
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
            'Extra.OperationName' => 'required|string|in:Backup,Restore,Test',
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

        // Determine the operation type
        $operationType = $extra['OperationName'];

        // Store the result
        $backupResult = new BackupResult();
        $backupResult->backup_server_id = $server->id;
        $backupResult->fill($data);
        $backupResult->extra = $extra;

        // Set operation-specific fields
        switch ($operationType) {
            case 'Backup':
                $backupResult->DeletedFiles = $data['DeletedFiles'] ?? 0;
                $backupResult->DeletedFolders = $data['DeletedFolders'] ?? 0;
                $backupResult->ModifiedFiles = $data['ModifiedFiles'] ?? 0;
                $backupResult->ExaminedFiles = $data['ExaminedFiles'] ?? 0;
                $backupResult->OpenedFiles = $data['OpenedFiles'] ?? 0;
                $backupResult->AddedFiles = $data['AddedFiles'] ?? 0;
                $backupResult->SizeOfModifiedFiles = $data['SizeOfModifiedFiles'] ?? 0;
                $backupResult->SizeOfAddedFiles = $data['SizeOfAddedFiles'] ?? 0;
                $backupResult->SizeOfExaminedFiles = $data['SizeOfExaminedFiles'] ?? 0;
                $backupResult->SizeOfOpenedFiles = $data['SizeOfOpenedFiles'] ?? 0;
                $backupResult->NotProcessedFiles = $data['NotProcessedFiles'] ?? 0;
                $backupResult->AddedFolders = $data['AddedFolders'] ?? 0;
                $backupResult->TooLargeFiles = $data['TooLargeFiles'] ?? 0;
                $backupResult->FilesWithError = $data['FilesWithError'] ?? 0;
                $backupResult->ModifiedFolders = $data['ModifiedFolders'] ?? 0;
                $backupResult->ModifiedSymlinks = $data['ModifiedSymlinks'] ?? 0;
                $backupResult->AddedSymlinks = $data['AddedSymlinks'] ?? 0;
                $backupResult->DeletedSymlinks = $data['DeletedSymlinks'] ?? 0;
                $backupResult->PartialBackup = $data['PartialBackup'] ?? false;
                $backupResult->Dryrun = $data['Dryrun'] ?? false;
                break;

            case 'Restore':
                // Add any restore-specific fields here if needed
                break;

            case 'Test':
                // Add any test-specific fields here if needed
                $backupResult->TestResults = $data['TestResults'] ?? null;
                break;
        }

        // Common fields for all operation types
        $backupResult->MainOperation = $data['MainOperation'];
        $backupResult->ParsedResult = $data['ParsedResult'];
        $backupResult->Interrupted = $data['Interrupted'];
        $backupResult->Version = $data['Version'];
        $backupResult->EndTime = $data['EndTime'];
        $backupResult->BeginTime = $data['BeginTime'];
        $backupResult->Duration = $data['Duration'];
        $backupResult->MessagesActualLength = $data['MessagesActualLength'];
        $backupResult->WarningsActualLength = $data['WarningsActualLength'];
        $backupResult->ErrorsActualLength = $data['ErrorsActualLength'];
        $backupResult->BackendStatistics = $data['BackendStatistics'];

        $backupResult->save();

        return response()->json([
            'message' => ucfirst($operationType) . ' result received successfully',
            'data' => $backupResult
        ], 201);
    }
}
