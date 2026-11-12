<?php

use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

function buildSyncProcess(array $command, string $directory): Process
{
    return new Process($command, $directory, [
        'GIT_CONFIG_GLOBAL'   => Storage::disk('build-sync')->path('empty-git-config'),
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_TERMINAL_PROMPT' => '0',
    ], timeout: 60);
}

function buildSyncShell(): string
{
    $finder = new ExecutableFinder;

    return $finder->find('sh') ?? dirname($finder->find('git'), 2) . '/bin/sh.exe';
}

beforeEach(function () {
    $testDirectory = storage_path('framework/testing/disks/build-sync');

    if (PHP_OS_FAMILY === 'Windows' && is_dir($testDirectory)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testDirectory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                chmod($file->getPathname(), 0o666);
            }
        }
    }

    $disk = Storage::fake('build-sync');
    $disk->put('source/install-build-sync.sh', file_get_contents(base_path('install-build-sync.sh')));
    $disk->put('source/public/build/manifest.json', '{"app":{"file":"assets/app.css"}}');
    $disk->put('source/public/build/assets/app.css', 'original styles');
    $disk->put('source/public/js/pos.js', 'original javascript');
    $disk->makeDirectory('public_html');

    buildSyncProcess(['git', 'init', '--initial-branch=main'], $disk->path('source'))->mustRun();
    buildSyncProcess(['git', 'config', 'core.autocrlf', 'false'], $disk->path('source'))->mustRun();
    buildSyncProcess(['git', 'config', 'user.name', 'Build Sync Test'], $disk->path('source'))->mustRun();
    buildSyncProcess(['git', 'config', 'user.email', 'build-sync@example.invalid'], $disk->path('source'))->mustRun();
    buildSyncProcess(['git', 'add', '.'], $disk->path('source'))->mustRun();
    buildSyncProcess(['git', 'commit', '-m', 'Add initial assets'], $disk->path('source'))->mustRun();
    buildSyncProcess(['git', 'clone', '-c', 'core.autocrlf=false', $disk->path('source'), 'aria'], $disk->path(''))->mustRun();
});

it('requires the explicit production flag before installing hooks', function () {
    $disk    = Storage::disk('build-sync');
    $process = buildSyncProcess([buildSyncShell(), 'install-build-sync.sh'], $disk->path('aria'));

    expect($process->run())->toBe(1)
        ->and($process->getErrorOutput())->toContain('--production');
    $disk->assertMissing('aria/.git/hooks/post-merge');
    $disk->assertMissing('public_html/build/manifest.json');
});

it('copies build and pos javascript immediately and safely supports reinstalling', function () {
    $disk = Storage::disk('build-sync');
    $disk->put('public_html/index.php', 'existing index');
    $disk->put('public_html/storage/uploads/photo.jpg', 'existing upload');
    $disk->put('public_html/js/custom.js', 'existing javascript');
    $disk->put('public_html/build/assets/old.css', 'old styles');

    for ($attempt = 0; $attempt < 2; $attempt++) {
        buildSyncProcess([buildSyncShell(), 'install-build-sync.sh', '--production'], $disk->path('aria'))->mustRun();
    }

    expect($disk->get('public_html/build/manifest.json'))->toBe($disk->get('aria/public/build/manifest.json'))
        ->and($disk->get('public_html/build/assets/app.css'))->toBe('original styles')
        ->and($disk->get('public_html/js/pos.js'))->toBe('original javascript')
        ->and($disk->get('public_html/index.php'))->toBe('existing index')
        ->and($disk->get('public_html/storage/uploads/photo.jpg'))->toBe('existing upload')
        ->and($disk->get('public_html/js/custom.js'))->toBe('existing javascript')
        ->and($disk->get('public_html/build/assets/old.css'))->toBe('old styles');
    $disk->assertExists(['aria/.git/hooks/post-merge', 'aria/.git/hooks/post-rewrite']);
});

it('automatically updates a custom public directory after git pull', function () {
    $disk = Storage::disk('build-sync');
    $disk->makeDirectory('custom public html');
    buildSyncProcess([
        buildSyncShell(), 'install-build-sync.sh', '--production', $disk->path('custom public html'),
    ], $disk->path('aria'))->mustRun();

    $disk->put('source/public/build/manifest.json', '{"app":{"file":"assets/new.css"}}');
    $disk->put('source/public/build/assets/new.css', 'updated styles');
    $disk->put('source/public/js/pos.js', 'updated javascript');
    buildSyncProcess(['git', 'add', '.'], $disk->path('source'))->mustRun();
    buildSyncProcess(['git', 'commit', '-m', 'Update assets'], $disk->path('source'))->mustRun();

    $pull = buildSyncProcess(['git', 'pull', '--ff-only'], $disk->path('aria'))->mustRun();

    expect($pull->getOutput() . $pull->getErrorOutput())->toContain('Build va POS JavaScript yangilandi:')
        ->and($disk->get('custom public html/build/manifest.json'))->toBe($disk->get('source/public/build/manifest.json'))
        ->and($disk->get('custom public html/build/assets/new.css'))->toBe('updated styles')
        ->and($disk->get('custom public html/js/pos.js'))->toBe('updated javascript');
});

it('does not replace an existing unrelated git hook', function () {
    $disk = Storage::disk('build-sync');
    $disk->put('aria/.git/hooks/post-rewrite', "#!/bin/sh\necho existing hook\n");
    $process = buildSyncProcess([buildSyncShell(), 'install-build-sync.sh', '--production'], $disk->path('aria'));

    expect($process->run())->toBe(1)
        ->and($process->getErrorOutput())->toContain('mavjud hook almashtirilmadi')
        ->and($disk->get('aria/.git/hooks/post-rewrite'))->toBe("#!/bin/sh\necho existing hook\n");
    $disk->assertMissing('aria/.git/hooks/post-merge');
    $disk->assertMissing('public_html/build/manifest.json');
});

it('rejects missing source files before installing hooks', function (string $source) {
    $disk = Storage::disk('build-sync');
    $disk->delete('aria/' . $source);
    $process = buildSyncProcess([buildSyncShell(), 'install-build-sync.sh', '--production'], $disk->path('aria'));

    expect($process->run())->toBe(1);
    $disk->assertMissing('aria/.git/hooks/post-merge');
    $disk->assertMissing('public_html/build/manifest.json');
})->with(['public/build/manifest.json', 'public/js/pos.js']);

it('honors the setting that disables automatic asset syncing', function () {
    $disk = Storage::disk('build-sync');
    buildSyncProcess([buildSyncShell(), 'install-build-sync.sh', '--production'], $disk->path('aria'))->mustRun();
    buildSyncProcess(['git', 'config', '--local', 'deploy.buildSyncEnabled', 'false'], $disk->path('aria'))->mustRun();
    $disk->put('aria/public/js/pos.js', 'updated javascript');

    buildSyncProcess([buildSyncShell(), '.git/hooks/post-merge'], $disk->path('aria'))->mustRun();

    expect($disk->get('public_html/js/pos.js'))->toBe('original javascript');
});
