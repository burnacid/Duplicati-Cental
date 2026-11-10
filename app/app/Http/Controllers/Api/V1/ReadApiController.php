<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BackupStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BackupResultResource;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Services\BackupHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Read-only REST API (/api/v1), authenticated with personal access tokens
 * (Settings → API tokens). Everything is scoped to the token's owner.
 */
class ReadApiController extends Controller
{
    public function summary(Request $request, BackupHealthService $health): JsonResponse
    {
        $jobs = $health->jobs($request->user());

        return response()->json(['data' => [
            'version' => config('app.version'),
            'servers' => BackupServer::ownedBy($request->user())->count(),
            'jobs' => $jobs->count(),
            'healthy' => $jobs->filter(fn ($j) => $j['last_status'] === BackupStatus::Success)->count(),
            'warning' => $jobs->filter(fn ($j) => $j['last_status'] === BackupStatus::Warning)->count(),
            'failed' => $jobs->filter(fn ($j) => $j['last_status']->isFailure())->count(),
            'overdue' => $jobs->where('is_overdue', true)->count(),
            'snoozed' => $jobs->where('is_muted', true)->count(),
            'needs_attention' => $jobs->filter(fn ($j) => ! $j['is_muted'] && ($j['is_overdue'] || $j['last_status']->isFailure()))->count(),
        ]]);
    }

    public function servers(Request $request): JsonResponse
    {
        $servers = BackupServer::ownedBy($request->user())
            ->withCount(['backupResults as result_count'])
            ->withMax('backupResults as last_report_at', 'EndTime')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $servers->map(fn (BackupServer $server) => [
            'id' => $server->id,
            'name' => $server->name,
            'description' => $server->description,
            'snoozed_until' => $server->isMuted() ? $server->muted_until->toIso8601String() : null,
            'result_count' => (int) $server->result_count,
            'last_report_at' => $server->last_report_at
                ? CarbonImmutable::parse($server->last_report_at, 'UTC')->setTimezone(config('app.timezone'))->toIso8601String()
                : null,
            'created_at' => $server->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function jobs(Request $request, BackupHealthService $health): JsonResponse
    {
        return response()->json(['data' => $health->jobs($request->user())->map(fn (array $job) => [
            'server_id' => $job['server_id'],
            'server_name' => $job['server_name'],
            'backup_id' => $job['backup_id'],
            'backup_name' => $job['backup_name'],
            'last_status' => $job['last_status']->value,
            'last_result_id' => $job['last_result']->id,
            'last_run_at' => $job['last_result']->EndTime?->toIso8601String(),
            'last_backup_at' => $job['last_backup_at']?->toIso8601String(),
            'is_overdue' => $job['is_overdue'],
            'monitored' => $job['monitored'],
            'is_snoozed' => $job['is_muted'],
            'snoozed_until' => $job['muted_until']?->toIso8601String(),
            'expected_interval_seconds' => $job['expected_interval'],
            'interval_source' => $job['interval_source'],
            'recent_statuses' => array_map(fn ($r) => $r['status']->value, $job['recent']),
        ])->values()]);
    }

    public function results(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'server' => ['nullable', 'string', 'max:64'],
            'backup' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['Success', 'Warning', 'Error', 'Fatal', 'Failed', 'Unknown'])],
            'operation' => ['nullable', 'string', 'max:64'],
            'since' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $utc = fn (string $date) => CarbonImmutable::parse($date)->utc()->format('Y-m-d H:i:s');

        $results = BackupResult::forUser($request->user())
            ->with('backupServer:id,name')
            ->when($filters['server'] ?? null, fn ($q, $v) => $q->where('backup_server_id', $v))
            ->when($filters['backup'] ?? null, fn ($q, $v) => $q->where('backup_id', $v))
            ->when(($filters['status'] ?? null) === 'Failed', fn ($q) => $q->whereIn('ParsedResult', ['Error', 'Fatal']))
            ->when(in_array($filters['status'] ?? null, ['Success', 'Warning', 'Error', 'Fatal', 'Unknown'], true), fn ($q) => $q->where('ParsedResult', $filters['status']))
            ->when($filters['operation'] ?? null, fn ($q, $v) => $q->where('MainOperation', $v))
            ->when($filters['since'] ?? null, fn ($q, $v) => $q->where('EndTime', '>=', $utc($v)))
            ->when($filters['until'] ?? null, fn ($q, $v) => $q->where('EndTime', '<=', $utc($v)))
            ->orderByDesc('EndTime')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return BackupResultResource::collection($results);
    }

    public function result(Request $request, int $id): BackupResultResource
    {
        $result = BackupResult::forUser($request->user())->with('backupServer:id,name')->findOrFail($id);

        return (new BackupResultResource($result))->detailed();
    }
}
