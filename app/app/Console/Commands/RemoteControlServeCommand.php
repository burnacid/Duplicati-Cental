<?php

namespace App\Console\Commands;

use App\Services\RemoteControlConnectionHandler;
use App\Services\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

class RemoteControlServeCommand extends Command
{
    protected $signature = 'remote-control:serve {--host=} {--port=}';

    protected $description = 'Run the persistent remote-control websocket server for Duplicati agents.';

    public function handle(RemoteControlConnectionHandler $handler, SystemHealthService $health): int
    {
        if (! config('remote_control.enabled')) {
            $this->info('Remote control is disabled (REMOTE_CONTROL_ENABLED=false); websocket server not started.');

            return self::SUCCESS;
        }

        $host = $this->option('host') ?: (string) config('remote_control.ws_host');
        $port = $this->option('port') ?: (string) config('remote_control.ws_port');

        $loop = Loop::get();
        $socket = new SocketServer("{$host}:{$port}", [], $loop);
        $server = new IoServer(new HttpServer(new WsServer($handler)), $socket, $loop);

        $this->info("Remote control websocket server listening on {$host}:{$port}");

        $loop->addPeriodicTimer(1.0, function () use ($handler): void {
            try {
                $handler->dispatchPendingCommands();
                $handler->reapStaleCommands();
            } catch (\Throwable $e) {
                Log::error('remote-control: periodic command tick failed', ['error' => $e->getMessage()]);
            }
        });

        $health->beat(SystemHealthService::WEBSOCKET);
        $loop->addPeriodicTimer(30.0, function () use ($health): void {
            try {
                $health->beat(SystemHealthService::WEBSOCKET);
            } catch (\Throwable $e) {
                Log::warning('remote-control: heartbeat failed', ['error' => $e->getMessage()]);
            }
        });

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);

            $shutdown = function () use ($loop): void {
                $loop->stop();
            };

            pcntl_signal(SIGTERM, $shutdown);
            pcntl_signal(SIGINT, $shutdown);
        }

        $server->run();

        return self::SUCCESS;
    }
}
