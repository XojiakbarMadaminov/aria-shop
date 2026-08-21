<?php

namespace App\Console\Commands;

use Throwable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class RestoreDatabaseBackup extends Command
{
    protected $signature = 'db:restore-backup
        {file=database/backup/mirobuvu_mirobuv9494.tar : PostgreSQL backup file path}
        {--connection= : Database connection to restore into}
        {--force : Restore without asking for confirmation}';

    protected $description = 'Restore a PostgreSQL backup into a configured database connection';

    public function handle(): int
    {
        $backupPath = $this->resolveBackupPath((string) $this->argument('file'));

        if (!is_file($backupPath) || !is_readable($backupPath)) {
            $this->error("Backup file does not exist or is not readable: {$backupPath}");

            return self::FAILURE;
        }

        $connectionName = (string) ($this->option('connection') ?: config('database.default'));
        $connection     = config("database.connections.{$connectionName}");

        if (!is_array($connection)) {
            $this->error("Database connection [{$connectionName}] is not configured.");

            return self::FAILURE;
        }

        if (($connection['driver'] ?? null) !== 'pgsql') {
            $this->error("Database connection [{$connectionName}] must use the pgsql driver.");

            return self::FAILURE;
        }

        $database = (string) ($connection['database'] ?? '');
        $username = (string) ($connection['username'] ?? '');

        if ($database === '' || $username === '') {
            $this->error("Database connection [{$connectionName}] must have a database and username.");

            return self::FAILURE;
        }

        $this->warn("This will replace the existing objects in database [{$database}] using:");
        $this->line($backupPath);

        if (!$this->option('force') && !$this->confirm('Do you want to continue?')) {
            $this->info('Database restore cancelled.');

            return self::SUCCESS;
        }

        $command = [
            'pg_restore',
            '--host',
            (string) ($connection['host'] ?? '127.0.0.1'),
            '--port',
            (string) ($connection['port'] ?? '5432'),
            '--username',
            $username,
            '--dbname',
            $database,
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--exit-on-error',
            $backupPath,
        ];

        $environment = [
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
        ];

        if (!empty($connection['sslmode'])) {
            $environment['PGSSLMODE'] = (string) $connection['sslmode'];
        }

        $this->info("Restoring backup into [{$database}]...");

        try {
            $result = Process::env($environment)
                ->forever()
                ->run($command);
        } catch (Throwable $exception) {
            $this->error('Unable to start pg_restore: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($result->failed()) {
            $errorOutput = trim($result->errorOutput());

            $this->error($errorOutput !== '' ? $errorOutput : 'pg_restore failed without error output.');

            return self::FAILURE;
        }

        $this->info('Database restored successfully.');

        return self::SUCCESS;
    }

    protected function resolveBackupPath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
