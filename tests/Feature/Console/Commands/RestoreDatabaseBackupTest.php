<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->backupPath = tempnam(sys_get_temp_dir(), 'postgres-backup-');

    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql', [
        'driver'   => 'pgsql',
        'host'     => 'database.example.test',
        'port'     => '5432',
        'database' => 'testing_database',
        'username' => 'testing_user',
        'password' => 'testing_password',
        'sslmode'  => 'require',
    ]);
});

afterEach(function () {
    if (is_string($this->backupPath) && is_file($this->backupPath)) {
        unlink($this->backupPath);
    }
});

it('restores a PostgreSQL backup with the configured connection', function () {
    Process::fake();

    $this->artisan('db:restore-backup', [
        'file'    => $this->backupPath,
        '--force' => true,
    ])
        ->expectsOutputToContain('Database restored successfully.')
        ->assertSuccessful();

    Process::assertRan(function (PendingProcess $process): bool {
        return $process->command === [
            'pg_restore',
            '--host',
            'database.example.test',
            '--port',
            '5432',
            '--username',
            'testing_user',
            '--dbname',
            'testing_database',
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--exit-on-error',
            $this->backupPath,
        ] && $process->environment === [
            'PGPASSWORD' => 'testing_password',
            'PGSSLMODE'  => 'require',
        ] && $process->timeout === null;
    });
});

it('fails when the backup file does not exist', function () {
    Process::fake();

    $missingPath = $this->backupPath . '-missing';

    $this->artisan('db:restore-backup', [
        'file'    => $missingPath,
        '--force' => true,
    ])
        ->expectsOutputToContain('Backup file does not exist or is not readable')
        ->assertFailed();

    Process::assertNothingRan();
});

it('rejects non PostgreSQL database connections', function () {
    Process::fake();
    config()->set('database.connections.mysql', [
        'driver' => 'mysql',
    ]);

    $this->artisan('db:restore-backup', [
        'file'         => $this->backupPath,
        '--connection' => 'mysql',
        '--force'      => true,
    ])
        ->expectsOutputToContain('must use the pgsql driver')
        ->assertFailed();

    Process::assertNothingRan();
});

it('reports pg restore failures', function () {
    Process::fake([
        '*' => Process::result(errorOutput: 'pg_restore: restore failed', exitCode: 1),
    ]);

    $this->artisan('db:restore-backup', [
        'file'    => $this->backupPath,
        '--force' => true,
    ])
        ->expectsOutputToContain('pg_restore: restore failed')
        ->assertFailed();
});
