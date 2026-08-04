<?php

namespace App\Services\Kaspi;

use Symfony\Component\Process\Process;

class KaspiLocalNodeProcessRunner
{
    /**
     * @param array<string, string|int|bool|null> $arguments
     * @return array{command: array<int, string>, script: string, cwd: string, exit_code: int|null, stdout: string, stderr: string}
     */
    public function run(string $script, array $arguments, int $timeoutSeconds = 90): array
    {
        $command = [$this->nodeExecutable(), $script];
        foreach ($arguments as $name => $value) {
            if ($value === null) {
                continue;
            }

            $command[] = '--'.$name.'='.$this->argumentValue($value);
        }

        $process = new Process($command, base_path(), ['NO_COLOR' => '1'], null, $timeoutSeconds);
        $process->run();

        return [
            'command' => $command,
            'script' => $script,
            'cwd' => base_path(),
            'exit_code' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function nodeExecutable(): string
    {
        return (string) (config('services.kaspi.node_binary') ?: 'node');
    }

    private function argumentValue(string|int|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
