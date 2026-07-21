<?php

namespace App\Services\Automation;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class ArtisanProcessRunner
{
    /**
     * @param array<int, string> $arguments
     * @param array<string, string|null> $env
     * @return array<string, mixed>
     */
    public function run(array $arguments, array $env = [], int $timeout = 180): array
    {
        $started = microtime(true);
        $php = $this->phpBinary();
        $command = [$php, 'artisan', ...$arguments];
        $baseEnv = [...$this->currentProcessEnvironment(), ...$this->baseEnvironment()];
        $processEnv = $this->filterEnvironment([...$baseEnv, ...$env]);

        $process = new Process(
            $command,
            base_path(),
            $processEnv,
            null,
            $timeout
        );

        try {
            DB::disconnect();
            $process->run();
        } catch (Throwable $exception) {
            DB::reconnect();

            return $this->exceptionResult($command, $php, $processEnv, $started, $exception, $process);
        }

        DB::reconnect();

        return [
            'cwd' => base_path(),
            'php_binary' => $php,
            'command' => $this->commandString($command),
            'exit_code' => $process->getExitCode(),
            'successful' => $process->isSuccessful(),
            'stdout' => trim($process->getOutput()),
            'stderr' => trim($process->getErrorOutput()),
            'exception_class' => null,
            'exception_message' => null,
            'stack_trace_first_20_lines' => [],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'env_keys' => array_keys($processEnv),
            'db_connection' => $processEnv['DB_CONNECTION'] ?? null,
            'db_host' => $processEnv['DB_HOST'] ?? null,
            'db_port' => $processEnv['DB_PORT'] ?? null,
            'db_database' => $processEnv['DB_DATABASE'] ?? null,
            'cache_store' => $processEnv['CACHE_STORE'] ?? null,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function baseEnvironment(): array
    {
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}", []);

        return [
            'APP_ENV' => config('app.env'),
            'APP_URL' => config('app.url') ?: 'http://127.0.0.1:8000',
            'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => $connectionName,
            'DB_HOST' => $connection['host'] ?? null,
            'DB_PORT' => (string) ($connection['port'] ?? ''),
            'DB_DATABASE' => $connection['database'] ?? null,
            'DB_USERNAME' => (string) ($connection['username'] ?? ''),
            'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
            'CACHE_STORE' => config('cache.default'),
            'DB_CACHE_CONNECTION' => config('cache.stores.database.connection'),
            'DB_CACHE_TABLE' => config('cache.stores.database.table'),
            'DB_CACHE_LOCK_CONNECTION' => config('cache.stores.database.lock_connection'),
            'DB_CACHE_LOCK_TABLE' => config('cache.stores.database.lock_table'),
            'PATH' => getenv('PATH') ?: null,
            'Path' => getenv('Path') ?: null,
            'NODE_PATH' => getenv('NODE_PATH') ?: null,
            'PLAYWRIGHT_BROWSERS_PATH' => getenv('PLAYWRIGHT_BROWSERS_PATH') ?: null,
            'USERPROFILE' => getenv('USERPROFILE') ?: null,
            'APPDATA' => getenv('APPDATA') ?: null,
            'LOCALAPPDATA' => getenv('LOCALAPPDATA') ?: null,
            'TEMP' => getenv('TEMP') ?: null,
            'TMP' => getenv('TMP') ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentProcessEnvironment(): array
    {
        $environment = getenv();

        return is_array($environment) ? $environment : [];
    }

    /**
     * @param array<string, mixed> $env
     * @return array<string, string>
     */
    private function filterEnvironment(array $env): array
    {
        return collect($env)
            ->filter(fn ($value): bool => $value !== null)
            ->map(fn ($value): string => (string) $value)
            ->all();
    }

    private function phpBinary(): string
    {
        $binary = PHP_BINARY;

        if (is_string($binary) && $binary !== '' && file_exists($binary) && strtolower(basename($binary)) === 'php.exe') {
            return $binary;
        }

        return (new PhpExecutableFinder())->find(false) ?: ($binary ?: 'php');
    }

    /**
     * @param array<int, string> $command
     */
    private function commandString(array $command): string
    {
        return implode(' ', array_map(
            fn (string $part): string => str_contains($part, ' ') ? '"'.$part.'"' : $part,
            $command
        ));
    }

    /**
     * @param array<int, string> $command
     * @param array<string, string> $processEnv
     * @return array<string, mixed>
     */
    private function exceptionResult(array $command, string $php, array $processEnv, float $started, Throwable $exception, Process $process): array
    {
        return [
            'cwd' => base_path(),
            'php_binary' => $php,
            'command' => $this->commandString($command),
            'exit_code' => $process->getExitCode(),
            'successful' => false,
            'stdout' => trim($process->getOutput()),
            'stderr' => trim($process->getErrorOutput()),
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'stack_trace_first_20_lines' => array_slice(explode(PHP_EOL, $exception->getTraceAsString()), 0, 20),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'env_keys' => array_keys($processEnv),
            'db_connection' => $processEnv['DB_CONNECTION'] ?? null,
            'db_host' => $processEnv['DB_HOST'] ?? null,
            'db_port' => $processEnv['DB_PORT'] ?? null,
            'db_database' => $processEnv['DB_DATABASE'] ?? null,
            'cache_store' => $processEnv['CACHE_STORE'] ?? null,
        ];
    }
}
