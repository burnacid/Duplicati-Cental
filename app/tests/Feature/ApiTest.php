<?php

use App\Livewire\Settings\ApiTokens;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\User;
use Livewire\Livewire;

function apiResult(BackupServer $server, string $name, string $status = 'Success', array $overrides = []): BackupResult
{
    $result = new BackupResult;
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => $name, 'backup-id' => 'DB-'.$name, 'machine-id' => 'm'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = $status;
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = $overrides['EndTime'] ?? now()->subHour();
    $result->BeginTime = $overrides['BeginTime'] ?? now()->subHour()->subMinutes(5);
    $result->Duration = '00:05:00';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = $status === 'Error' ? 1 : 0;
    $result->SizeOfExaminedFiles = 5000;
    $result->BackendStatistics = ['KnownFileSize' => 4000, 'BackupListCount' => 3];
    $result->log_lines = ['started', 'finished'];
    $result->save();

    return $result;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = BackupServer::query()->create(['name' => 'Office "NAS"', 'user_id' => $this->user->id]);
    $this->ok = apiResult($this->server, 'Docs');
    $this->failed = apiResult($this->server, 'Photos', 'Error');
    $this->token = $this->user->createToken('test', ['read'])->plainTextToken;

    $this->other = User::factory()->create();
    $this->otherServer = BackupServer::query()->create(['name' => 'Secret server', 'user_id' => $this->other->id]);
    $this->otherResult = apiResult($this->otherServer, 'Hidden');
});

test('the API needs a token and answers with JSON', function () {
    $this->get('/api/v1/jobs')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    $this->get('/api/v1/metrics')->assertUnauthorized();
    $this->withToken('nonsense')->getJson('/api/v1/jobs')->assertUnauthorized();
});

test('a token without the read ability is refused', function () {
    $token = $this->user->createToken('write only', ['something-else'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/jobs')->assertForbidden();
});

test('expired tokens are refused', function () {
    $token = $this->user->createToken('old', ['read'], now()->subDay())->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/jobs')->assertUnauthorized();
});

test('the session cookie does not authenticate the API', function () {
    $this->actingAs($this->user)->getJson('/api/v1/jobs')->assertUnauthorized();
});

test('summary, servers and jobs only contain the owner\'s data', function () {
    $this->withToken($this->token)->getJson('/api/v1/summary')
        ->assertOk()
        ->assertJson(['data' => ['servers' => 1, 'jobs' => 2, 'healthy' => 1, 'failed' => 1, 'needs_attention' => 1]]);

    $this->withToken($this->token)->getJson('/api/v1/servers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Office "NAS"')
        ->assertJsonPath('data.0.result_count', 2)
        ->assertJsonMissingPath('data.0.api_key')
        ->assertDontSee('Secret server');

    $this->withToken($this->token)->getJson('/api/v1/jobs')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['backup_name' => 'Photos', 'last_status' => 'Error'])
        ->assertDontSee('Hidden');
});

test('results can be filtered and paginated', function () {
    $this->withToken($this->token)->getJson('/api/v1/results?status=Failed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->failed->id)
        ->assertJsonPath('data.0.is_failure', true)
        ->assertJsonPath('data.0.server_name', 'Office "NAS"')
        ->assertJsonMissingPath('data.0.log_lines');

    $this->withToken($this->token)->getJson('/api/v1/results?backup=DB-Docs&per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.per_page', 1);

    $this->withToken($this->token)->getJson('/api/v1/results?since='.urlencode(now()->addDay()->toIso8601String()))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withToken($this->token)->getJson('/api/v1/results?status=Nope')->assertUnprocessable();
});

test('a single result includes log lines, but not another user\'s result', function () {
    $this->withToken($this->token)->getJson("/api/v1/results/{$this->ok->id}")
        ->assertOk()
        ->assertJsonPath('data.log_lines', ['started', 'finished'])
        ->assertJsonPath('data.sizes.backup_bytes', 4000)
        ->assertJsonPath('data.duration_seconds', 300);

    $this->withToken($this->token)->getJson("/api/v1/results/{$this->otherResult->id}")->assertNotFound();
});

test('metrics are in Prometheus format with escaped labels', function () {
    $response = $this->withToken($this->token)->get('/api/v1/metrics')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain; version=0.0.4');

    $body = $response->getContent();
    expect($body)
        ->toContain('# TYPE duplicati_central_job_failing gauge')
        ->toContain('duplicati_central_job_failing{server="Office \"NAS\"",job="Photos",backup_id="DB-Photos"} 1')
        ->toContain('duplicati_central_job_failing{server="Office \"NAS\"",job="Docs",backup_id="DB-Docs"} 0')
        ->toContain('duplicati_central_job_backup_size_bytes{server="Office \"NAS\"",job="Docs",backup_id="DB-Docs"} 4000')
        ->toContain('duplicati_central_job_last_duration_seconds{server="Office \"NAS\"",job="Docs",backup_id="DB-Docs"} 300')
        ->toContain('duplicati_central_jobs 2')
        ->not->toContain('Secret server');

    // Only the successful job has a last-success timestamp.
    expect(substr_count($body, 'duplicati_central_job_last_success_timestamp_seconds{'))->toBe(1);
});

test('tokens are created once, listed and revoked from settings', function () {
    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test(ApiTokens::class)
        ->set('name', 'Prometheus')
        ->set('expiresIn', '30')
        ->call('createToken')
        ->assertHasNoErrors();

    $plain = $component->get('plainToken');
    expect($plain)->toBeString()->not->toBeEmpty();

    $token = $this->user->tokens()->where('name', 'Prometheus')->firstOrFail();
    expect($token->can('read'))->toBeTrue()
        ->and($token->expires_at->isFuture())->toBeTrue();

    $this->withToken($plain)->getJson('/api/v1/summary')->assertOk();

    $component->call('revoke', $token->id);
    expect($this->user->tokens()->where('name', 'Prometheus')->exists())->toBeFalse();
});

test('another user\'s token cannot be revoked', function () {
    $otherToken = $this->other->createToken('theirs', ['read'])->accessToken;

    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()]);
    Livewire::test(ApiTokens::class)->call('revoke', $otherToken->id);

    expect($this->other->tokens()->count())->toBe(1);
});

test('the token page needs a recent password confirmation', function () {
    $this->actingAs($this->user)->get(route('settings.api-tokens'))->assertRedirect(route('password.confirm'));
    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('settings.api-tokens'))->assertOk()->assertSee('API tokens');
});
