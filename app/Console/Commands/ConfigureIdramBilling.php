<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingPaymentProviders;
use App\Services\Billing\IdramGateway;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

class ConfigureIdramBilling extends Command
{
    protected $signature = 'billing:configure-idram
        {--account= : Bank-issued nine-digit merchant account}
        {--check : Show configuration checks without changing files}';

    protected $description = 'Configure Idram with a hidden secret prompt, or check its local readiness';

    public function handle(Filesystem $files): int
    {
        if ($this->option('check')) {
            $gateway = app(IdramGateway::class);
            $account = (string) config('billing.providers.idram.rec_account');
            $secret = config('billing.providers.idram.secret_key');
            $ready = config('billing.providers.default') === 'idram' && $gateway->isReady();
            $this->table(['Setting', 'Result'], [
                ['BILLING_DEFAULT_PROVIDER=idram', config('billing.providers.default') === 'idram' ? 'yes' : 'no'],
                ['BILLING_PROVIDER_MODE=live', config('billing.providers.mode') === 'live' ? 'yes' : 'no'],
                ['IDRAM_LIVE_ENABLED', config('billing.providers.idram.live_enabled') ? 'yes' : 'no'],
                ['IDRAM_REC_ACCOUNT', preg_match('/^[0-9]{9}$/D', $account) ? $account : 'missing or invalid'],
                ['IDRAM_SECRET_KEY configured', is_string($secret) && trim($secret) !== '' && $secret !== 'CHANGE_ME' ? 'yes' : 'no'],
                ['checkout_available', BillingPaymentProviders::status()['checkout_available'] && $ready ? 'yes' : 'no'],
            ]);
            $this->line('These are local configuration checks; they do not verify a bank payment.');

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        $account = (string) $this->option('account');
        if (preg_match('/^[0-9]{9}$/D', $account) !== 1) {
            $this->error('Pass the bank-issued nine-digit account with --account.');

            return self::FAILURE;
        }
        $path = $this->laravel->environmentFilePath();
        if (! $files->isFile($path) || ! is_readable($path) || ! is_writable($path) || ! is_writable(dirname(realpath($path)))) {
            $this->error('The application environment file and its directory must be writable.');

            return self::FAILURE;
        }
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively to enter the secret in the hidden prompt.');

            return self::FAILURE;
        }

        $changed = false;
        $before = null;
        $mode = fileperms($path) & 0777;
        try {
            $secret = $this->secret('Idram SECRET_KEY', false);
            if (! is_string($secret) || trim($secret) === '' || $secret === 'CHANGE_ME' || preg_match('/[\r\n\x00]/', $secret)) {
                $this->error('A non-empty, single-line bank secret is required.');

                return self::FAILURE;
            }
            $values = [
                'BILLING_DEFAULT_PROVIDER' => 'idram', 'BILLING_PROVIDER_MODE' => 'live',
                'BILLING_ALLOW_MOCK_PAYMENTS' => 'false', 'IDBANK_LIVE_ENABLED' => 'false',
                'IDRAM_LIVE_ENABLED' => 'true', 'IDRAM_REC_ACCOUNT' => $account,
                'IDRAM_SECRET_KEY' => $secret,
            ];
            $before = $files->get($path);
            $after = $before;
            foreach ($values as $key => $value) {
                $encoded = '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
                $line = $key.'='.$encoded;
                $pattern = '/^[\t ]*(?:export[\t ]+)?'.preg_quote($key, '/').'[\t ]*=.*$/m';
                $after = preg_match($pattern, $after)
                    ? preg_replace_callback($pattern, fn () => $line, $after)
                    : rtrim($after)."\n".$line."\n";
            }
            $parsed = Dotenv::parse($after);
            foreach ($values as $key => $value) {
                if (($parsed[$key] ?? null) !== $value) {
                    $this->error('The environment could not be safely encoded. No settings were changed.');

                    return self::FAILURE;
                }
            }
            $backup = $path.'.before-idram-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
            $files->replace($backup, $before, 0600);
            $files->replace($path, $after, $mode);
            $changed = true;
            if ($this->callSilent('config:clear') !== self::SUCCESS) {
                $files->replace($path, $before, $mode);
                $this->error('Configuration cache could not be cleared. The environment was restored.');

                return self::FAILURE;
            }
        } catch (Throwable) {
            // Dotenv parse exceptions can include secret values. Never print them.
            if ($changed && is_string($before)) {
                try {
                    $files->replace($path, $before, $mode);
                } catch (Throwable) {
                    $this->error('Update failed. Restore the private environment backup and clear configuration cache.');

                    return self::FAILURE;
                }
            }
            $this->error('Configuration could not be saved. Check environment-file permissions and hidden-input support.');

            return self::FAILURE;
        }

        $this->info('Idram settings saved for account '.$account.'. Configuration cache cleared.');
        $this->line('A private environment backup was saved beside the environment file.');
        $this->line('Verify in a fresh process: php artisan billing:configure-idram --check');
        $this->line('Use a test business when entering bank-issued test credentials.');

        return self::SUCCESS;
    }
}
