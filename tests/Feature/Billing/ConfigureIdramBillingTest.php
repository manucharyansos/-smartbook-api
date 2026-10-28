<?php

use App\Services\Billing\IdramGateway;
use Dotenv\Dotenv;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

function withIdramConsoleEnvironment(string $before, Closure $run): void
{
    $files = new Filesystem;
    $directory = sys_get_temp_dir().'/vizit-idram-console-'.bin2hex(random_bytes(6));
    $files->makeDirectory($directory.'/bootstrap/cache', 0700, true);
    $files->put($directory.'/.env', $before);
    chmod($directory.'/.env', 0600);
    $environmentPath = app()->environmentPath();
    $bootstrapPath = app()->bootstrapPath();
    $environmentFile = app()->environmentFile();
    app()->useEnvironmentPath($directory)->loadEnvironmentFrom('.env')->useBootstrapPath($directory.'/bootstrap');
    try {
        $run($directory);
    } finally {
        app()->useEnvironmentPath($environmentPath)->loadEnvironmentFrom($environmentFile)->useBootstrapPath($bootstrapPath);
        $files->deleteDirectory($directory);
    }
}

it('saves Idram settings with a hidden secret, preserves unrelated values, and clears cached configuration', function () {
    $before = "APP_NAME=ExistingApp\nDB_HOST=original-db\nBILLING_DEFAULT_PROVIDER=idbank\nIDRAM_LIVE_ENABLED=false\nexport IDRAM_LIVE_ENABLED = false\n";
    withIdramConsoleEnvironment($before, function ($directory) use ($before) {
        $secret = 'consoleQaKey$with#quotes"and\\slashes';
        file_put_contents(app()->getCachedConfigPath(), '<?php return [];');
        $this->artisan('billing:configure-idram', ['--account' => '111222333'])
            ->expectsQuestion('Idram SECRET_KEY', $secret)
            ->expectsOutputToContain('Idram settings saved for account 111222333')
            ->doesntExpectOutputToContain($secret)
            ->assertSuccessful();
        $settings = Dotenv::parse(file_get_contents($directory.'/.env'));
        expect($settings)->toMatchArray([
            'APP_NAME' => 'ExistingApp', 'DB_HOST' => 'original-db',
            'BILLING_DEFAULT_PROVIDER' => 'idram', 'BILLING_PROVIDER_MODE' => 'live',
            'BILLING_ALLOW_MOCK_PAYMENTS' => 'false', 'IDBANK_LIVE_ENABLED' => 'false',
            'IDRAM_LIVE_ENABLED' => 'true', 'IDRAM_REC_ACCOUNT' => '111222333', 'IDRAM_SECRET_KEY' => $secret,
        ]);
        expect(file_exists(app()->getCachedConfigPath()))->toBeFalse();
        $backups = glob($directory.'/.env.before-idram-*');
        expect($backups)->toHaveCount(1);
        expect(file_get_contents($backups[0]))->toBe($before);
        expect(fileperms($backups[0]) & 0777)->toBe(0600);
    });
});

it('rejects an invalid merchant before requesting a secret or modifying the environment', function () {
    withIdramConsoleEnvironment("APP_NAME=ExistingApp\n", function ($directory) {
        $before = file_get_contents($directory.'/.env');
        $this->artisan('billing:configure-idram', ['--account' => '123'])
            ->expectsOutputToContain('nine-digit account')->assertFailed();
        expect(file_get_contents($directory.'/.env'))->toBe($before);
        expect(glob($directory.'/.env.before-idram-*'))->toBe([]);
    });
});

it('rejects an empty or multiline secret without writing the environment', function (string $secret) {
    withIdramConsoleEnvironment("APP_NAME=ExistingApp\n", function ($directory) use ($secret) {
        $this->artisan('billing:configure-idram', ['--account' => '111222333'])
            ->expectsQuestion('Idram SECRET_KEY', $secret)
            ->expectsOutputToContain('non-empty, single-line')->assertFailed();
        expect(file_get_contents($directory.'/.env'))->toBe("APP_NAME=ExistingApp\n");
        expect(glob($directory.'/.env.before-idram-*'))->toBe([]);
    });
})->with(['empty' => [''], 'multiline' => ["qa-secret\ninjected=value"]]);

it('reports the missing readiness setting without revealing secrets or changing files', function () {
    config()->set([
        'billing.providers.default' => 'idram', 'billing.providers.mode' => 'live',
        'billing.providers.idram.live_enabled' => false, 'billing.providers.idram.rec_account' => '111222333',
        'billing.providers.idram.secret_key' => 'console-status-private-qa-key',
    ]);
    withIdramConsoleEnvironment("APP_NAME=ExistingApp\n", function ($directory) {
        expect(Artisan::call('billing:configure-idram', ['--check' => true]))->toBe(1);
        $output = Artisan::output();
        expect($output)->toContain('IDRAM_LIVE_ENABLED', 'checkout_available', 'no')->not->toContain('console-status-private-qa-key');
        expect(file_get_contents($directory.'/.env'))->toBe("APP_NAME=ExistingApp\n");
        config()->set('billing.providers.idram.live_enabled', true);
        expect(Artisan::call('billing:configure-idram', ['--check' => true]))->toBe(0);
        expect(app(IdramGateway::class)->isReady())->toBeTrue();
    });
});

it('requires interactive hidden input rather than accepting a secret in arguments', function () {
    withIdramConsoleEnvironment("APP_NAME=ExistingApp\n", function ($directory) {
        expect(Artisan::call('billing:configure-idram', ['--account' => '111222333', '--no-interaction' => true]))->toBe(1);
        expect(Artisan::output())->toContain('interactively');
        expect(file_get_contents($directory.'/.env'))->toBe("APP_NAME=ExistingApp\n");
    });
});
