<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GscAuthCheckCommand extends Command
{
    protected $signature = 'gsc:auth-check';

    protected $description = 'Check GSC foundation env configuration without contacting Google.';

    public function handle(): int
    {
        $config = config('services.gsc');
        $credentialsPath = $config['credentials_path'];
        $stats = [
            'GSC_PROPERTY_URL configured' => filled($config['property_url']) ? 'yes' : 'no',
            'GSC_AUTH_MODE' => $config['auth_mode'] ?: 'service_account',
            'GSC_CLIENT_EMAIL configured' => filled($config['client_email']) ? 'yes' : 'no',
            'GSC_CREDENTIALS_PATH configured' => filled($credentialsPath) ? 'yes' : 'no',
            'GSC_CREDENTIALS_PATH file exists' => filled($credentialsPath) && is_file((string) $credentialsPath) ? 'yes' : 'no',
            'GSC_SYNC_CHUNK_DAYS' => (string) $config['sync_chunk_days'],
        ];

        $this->writeReport($stats);
        $this->table(['Check', 'Value'], collect($stats)->map(fn (string $value, string $check): array => [$check, $value])->values());
        $this->info('GSC foundation check complete. No external Google request was made.');

        return self::SUCCESS;
    }

    private function writeReport(array $stats): void
    {
        $lines = ['# GSC_FOUNDATION_REPORT', '', 'Дата проверки: '.now()->toDateString(), '', '| Check | Value |', '| --- | --- |'];

        foreach ($stats as $check => $value) {
            $lines[] = '| '.$check.' | '.$value.' |';
        }

        $lines[] = '';
        $lines[] = 'Реальные credentials не подключались. Команда не делает запросы к Google Search Console.';

        File::put(dirname(base_path()).DIRECTORY_SEPARATOR.'GSC_FOUNDATION_REPORT.md', implode(PHP_EOL, $lines).PHP_EOL);
    }
}
