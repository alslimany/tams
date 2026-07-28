<?php

namespace App\Console\Commands\Insurance;

use App\Models\Tenant;
use App\Models\Tenant\TenantInsuranceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ProbeOrangePolicyReportCommand extends Command
{
    protected $signature = 'insurance:probe-orange-report
        {tenant : Tenant id that holds Al Baraka credentials}
        {--card= : Orange card / policy number (e.g. LBY/6884971)}
        {--id= : Numeric policy id (e.g. 71223)}
        {--encrypted= : EncryptedId when known}';

    protected $description = 'Probe Al Baraka Oranges/GetReportById query variants and report which return a PDF';

    public function handle(): int
    {
        $tenantId = (string) $this->argument('tenant');
        $card = trim((string) $this->option('card'));
        $policyId = trim((string) $this->option('id'));
        $encrypted = trim((string) $this->option('encrypted'));

        if ($card === '' && $policyId === '' && $encrypted === '') {
            $this->error('Provide at least one of --card, --id, or --encrypted.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            $this->error("Tenant [{$tenantId}] was not found.");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        $provider = TenantInsuranceProvider::query()
            ->where('provider_type', 'albaraka')
            ->where('is_active', true)
            ->first();

        if ($provider === null) {
            $this->error('No active Al Baraka insurance provider for this tenant.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) data_get($provider->credentials, 'base_url', config('services.albaraka.base_url')), '/');
        $token = (string) data_get($provider->credentials, 'token', '');

        if ($baseUrl === '' || $token === '') {
            $this->error('Al Baraka credentials are incomplete (base_url/token).');

            return self::FAILURE;
        }

        $attempts = $this->buildAttempts($card, $policyId, $encrypted);

        $this->info('Probing '.count($attempts).' Oranges/GetReportById variants...');
        $this->newLine();

        $rows = [];

        foreach ($attempts as $index => $query) {
            $queryString = http_build_query($query);
            $url = $baseUrl.'/api/Oranges/GetReportById?'.$queryString;

            $response = Http::withToken($token)
                ->accept('application/pdf')
                ->timeout(60)
                ->get($url);

            $body = (string) $response->body();
            $isPdf = str_starts_with(ltrim($body), '%PDF');
            $snippet = $isPdf
                ? '%PDF…'
                : mb_substr(preg_replace('/\s+/', ' ', $body) ?? $body, 0, 160);

            $label = collect($query)
                ->map(fn ($value, $key): string => $key.'='.$value)
                ->implode('&');

            $rows[] = [
                $index + 1,
                $label,
                $response->status(),
                strlen($body),
                $isPdf ? 'yes' : 'no',
                $snippet,
            ];

            $this->line(sprintf(
                '[%d] %s → status=%d bytes=%d isPdf=%s',
                $index + 1,
                $label,
                $response->status(),
                strlen($body),
                $isPdf ? 'yes' : 'no',
            ));

            if (! $isPdf) {
                $this->line('    body: '.$snippet);
            }
        }

        $this->newLine();
        $this->table(['#', 'Query', 'HTTP', 'Bytes', 'PDF?', 'Body/Snippet'], $rows);

        $winners = array_values(array_filter($rows, fn (array $row): bool => $row[4] === 'yes'));

        if (count($winners) === 0) {
            $this->warn('No variant returned a PDF body.');

            return self::FAILURE;
        }

        $this->info('Working variant(s):');
        foreach ($winners as $winner) {
            $this->line(' - '.$winner[1]);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function buildAttempts(string $card, string $policyId, string $encrypted): array
    {
        $attempts = [];

        // Production-confirmed winners first.
        if ($policyId !== '' && $card !== '') {
            $attempts[] = ['CardNumber' => $card, 'Id' => $policyId];
        }

        if ($card !== '') {
            $attempts[] = ['CardNumber' => $card];
        }

        // Optional extras for debugging only when --encrypted is provided.
        if ($encrypted !== '') {
            $attempts[] = ['EncryptedId' => $encrypted];

            if ($policyId !== '') {
                $attempts[] = ['EncryptedId' => $encrypted, 'Id' => $policyId];
            }

            if ($card !== '') {
                $attempts[] = ['EncryptedId' => $encrypted, 'CardNumber' => $card];
            }
        }

        if ($policyId !== '') {
            $attempts[] = ['Id' => $policyId];
            $attempts[] = ['EncryptedId' => $policyId];
            $attempts[] = ['CardNumber' => $policyId];
        }

        if ($card !== '') {
            $attempts[] = ['EncryptedId' => $card];
            $attempts[] = ['Id' => $card];
        }

        $unique = [];
        $seen = [];

        foreach ($attempts as $attempt) {
            ksort($attempt);
            $key = http_build_query($attempt);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $attempt;
        }

        return $unique;
    }
}
