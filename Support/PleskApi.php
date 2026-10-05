<?php

namespace Extensions\Servers\Plesk\Support;

use App\Models\ServerConnection;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PleskApi
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        protected array $credentials,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function make(array $credentials): self
    {
        return new self($credentials);
    }

    public static function fromConnection(ServerConnection $connection): self
    {
        return new self($connection->config ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function server(): array
    {
        return $this->request('get', '/server');
    }

    /**
     * @return array<int, string>
     */
    public function servicePlans(): array
    {
        $output = $this->cli('service_plan', ['--list']);

        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $output) ?: [])));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findClientByExternalId(string $externalId): ?array
    {
        foreach ($this->request('get', '/clients') as $client) {
            if (is_array($client) && (string) ($client['external_id'] ?? '') === $externalId) {
                return $client;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createClient(array $data): array
    {
        return $this->request('post', '/clients', array_merge(['type' => 'customer'], $data));
    }

    public function changeClientPassword(int|string $clientId, string $password): void
    {
        $this->request('put', '/clients/'.$clientId, ['password' => $password]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createDomain(array $data): array
    {
        return $this->request('post', '/domains', $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function domain(int|string $domainId): array
    {
        return $this->request('get', '/domains/'.$domainId);
    }

    public function setDomainStatus(int|string $domainId, string $status): void
    {
        $this->request('put', '/domains/'.$domainId.'/status', ['status' => $status]);
    }

    public function deleteDomain(int|string $domainId): void
    {
        $this->request('delete', '/domains/'.$domainId);
    }

    public function switchPlan(string $domain, string $plan): void
    {
        $this->cli('subscription', ['--switch-subscription', $domain, '-service-plan', $plan]);
    }

    public function loginUrl(string $login): string
    {
        $url = trim($this->cli('admin', ['--get-login-link', '-user', $login]));
        $url = trim((string) (preg_split('/\r?\n/', $url)[0] ?? ''));

        if (! str_starts_with($url, 'http')) {
            throw new Exception('Plesk did not return a login link.');
        }

        return $url;
    }

    public function baseUrl(): string
    {
        $hostname = rtrim((string) ($this->credentials['hostname'] ?? ''), '/');

        if ($hostname === '') {
            throw new Exception('Plesk hostname is not configured.');
        }

        $port = $this->credentials['port'] ?? null;

        if ($port && ! preg_match('/:\d+$/', $hostname)) {
            $hostname .= ':'.$port;
        }

        return $hostname;
    }

    /**
     * Run a Plesk CLI utility through the REST API and return its stdout.
     *
     * @param  array<int, string>  $params
     */
    public function cli(string $command, array $params): string
    {
        $result = $this->request('post', '/cli/'.$command.'/call', ['params' => $params]);

        if ((int) ($result['code'] ?? 1) !== 0) {
            $message = trim((string) ($result['stderr'] ?? '')) ?: trim((string) ($result['stdout'] ?? '')) ?: 'Plesk CLI command failed.';

            throw new Exception($this->friendlyError("cli/{$command}", $message));
        }

        return (string) ($result['stdout'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int|string, mixed>
     */
    protected function request(string $method, string $endpoint, array $data = []): array
    {
        try {
            $response = $this->client()->{$method}($this->baseUrl().'/api/v2'.$endpoint, $data);
        } catch (ConnectionException $exception) {
            throw new Exception($this->friendlyError($endpoint, 'Could not connect to Plesk: '.$exception->getMessage()));
        }

        if ($response->failed()) {
            throw new Exception($this->friendlyError($endpoint, $this->errorMessage($response)));
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    protected function client(): PendingRequest
    {
        $client = Http::asJson()->acceptJson()->timeout(60);

        $apiKey = (string) ($this->credentials['api_key'] ?? '');

        $client = $apiKey !== ''
            ? $client->withHeaders(['X-API-Key' => $apiKey])
            : $client->withBasicAuth((string) ($this->credentials['username'] ?? ''), (string) ($this->credentials['password'] ?? ''));

        if ((string) ($this->credentials['verify_ssl'] ?? '1') !== '1') {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    protected function errorMessage(Response $response): string
    {
        if (in_array($response->status(), [401, 403], true)) {
            return 'Plesk rejected the credentials. Check the API key or username and password.';
        }

        $message = $response->json('message') ?? $response->json('errors.0') ?? null;

        return $message ? (string) (is_array($message) ? json_encode($message) : $message) : "Plesk returned HTTP {$response->status()}.";
    }

    protected function friendlyError(string $endpoint, string $message): string
    {
        if ((string) ($this->credentials['debug_mode'] ?? '0') === '1') {
            return "[{$endpoint}] {$message}";
        }

        return $message;
    }
}
