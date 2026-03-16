<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel\Commands;

use Gianfriaur\OpcuaSessionManager\Daemon\SessionManagerDaemon;
use Illuminate\Console\Command;

class SessionCommand extends Command
{
    protected $signature = 'opcua:session
        {--timeout= : Session inactivity timeout in seconds}
        {--cleanup-interval= : Cleanup check interval in seconds}
        {--max-sessions= : Maximum concurrent sessions}
        {--socket-mode= : Socket file permissions (octal)}';

    protected $description = 'Start the OPC UA session manager daemon';

    public function handle(): int
    {
        $config = config('opcua.session_manager');

        $socketPath = $config['socket_path'];
        $timeout = $this->option('timeout') ?? $config['timeout'];
        $cleanupInterval = $this->option('cleanup-interval') ?? $config['cleanup_interval'];
        $maxSessions = $this->option('max-sessions') ?? $config['max_sessions'];
        $socketMode = $this->option('socket-mode')
            ? intval($this->option('socket-mode'), 8)
            : $config['socket_mode'];

        $allowedCertDirs = $config['allowed_cert_dirs'];

        $authToken = $config['auth_token'];

        // Ensure the directory for the socket exists
        $socketDir = dirname($socketPath);
        if (!is_dir($socketDir)) {
            mkdir($socketDir, 0755, true);
        }

        $this->info('Starting OPC UA Session Manager...');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Socket', $socketPath],
                ['Timeout', $timeout . 's'],
                ['Cleanup Interval', $cleanupInterval . 's'],
                ['Max Sessions', $maxSessions],
                ['Socket Mode', sprintf('0%o', $socketMode)],
                ['Auth Token', $authToken ? 'configured' : 'none'],
                ['Cert Dirs', $allowedCertDirs ? implode(', ', $allowedCertDirs) : 'any'],
            ],
        );

        $daemon = new SessionManagerDaemon(
            socketPath: $socketPath,
            timeout: (int) $timeout,
            cleanupInterval: (int) $cleanupInterval,
            authToken: $authToken,
            maxSessions: (int) $maxSessions,
            socketMode: $socketMode,
            allowedCertDirs: $allowedCertDirs,
        );

        $daemon->run();

        return self::SUCCESS;
    }
}
