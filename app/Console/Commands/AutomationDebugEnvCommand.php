<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AutomationDebugEnvCommand extends Command
{
    protected $signature = 'automation:debug-env';

    protected $description = 'Print automation runtime environment for CLI vs Filament process comparison.';

    public function handle(): int
    {
        $databaseName = null;
        $databaseConfig = null;
        $databaseError = null;

        try {
            $connection = DB::connection();
            $databaseName = $connection->getDatabaseName();
            $databaseConfig = $this->sanitize($connection->getConfig());
        } catch (Throwable $exception) {
            $databaseError = [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ];
        }

        $payload = [
            'app_base_path_constant' => defined('APP_BASE_PATH') ? APP_BASE_PATH : null,
            'base_path' => base_path(),
            'getcwd' => getcwd(),
            'bootstrap_app_path' => base_path('bootstrap/app.php'),
            'env_file' => base_path('.env'),
            'env_file_exists' => file_exists(base_path('.env')),
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'app_environment' => app()->environment(),
            'config_app_url' => config('app.url'),
            'config_database_default' => config('database.default'),
            'config_database_connections' => $this->sanitize(config('database.connections')),
            'config_cache_default' => config('cache.default'),
            'config_cache_stores' => $this->sanitize(config('cache.stores')),
            'db_connection_database_name' => $databaseName,
            'db_connection_config' => $databaseConfig,
            'db_connection_error' => $databaseError,
            'php_binary' => PHP_BINARY,
            'php_sapi' => PHP_SAPI,
            'getenv' => $this->sanitize(getenv() ?: []),
            '_ENV' => $this->sanitize($_ENV),
            '_SERVER' => $this->sanitize($_SERVER),
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                $keyString = (string) $key;
                $clean[$key] = preg_match('/password|passwd|pwd|secret|key|token/i', $keyString)
                    ? $this->mask($item)
                    : $this->sanitize($item);
            }

            return $clean;
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : $value::class;
        }

        return $value;
    }

    private function mask(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return '***';
    }
}
