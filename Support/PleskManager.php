<?php

namespace Extensions\Servers\Plesk\Support;

use App\Models\Order;
use App\Models\PackagePrice;
use App\Models\ServerConnection;
use Exception;
use Illuminate\Support\Str;

class PleskManager
{
    public function __construct(
        protected PleskApi $api,
        protected ServerConnection $connection,
    ) {}

    public static function for(ServerConnection $connection): self
    {
        return new self(PleskApi::fromConnection($connection), $connection);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Order $order): array
    {
        if ($order->external_id) {
            $data = $order->data ?? [];
            $data['last_error'] = null;

            return $data;
        }

        $plan = (string) $order->package->data('plan', '');
        $domain = strtolower(trim((string) $order->option('domain', '')));

        if ($plan === '') {
            throw new Exception('No Plesk service plan is set on this package.');
        }

        if ($domain === '') {
            throw new Exception('A domain is required to create a Plesk subscription.');
        }

        $customer = $this->customer($order);
        $ftpLogin = $this->systemLogin($domain);
        $ftpPassword = Str::password(16, symbols: false);

        $created = $this->api->createDomain([
            'name' => $domain,
            'hosting_type' => 'virtual',
            'description' => 'WemX order #'.$order->id,
            'external_id' => 'wemx-order-'.$order->id,
            'owner_client' => [
                'id' => $customer['id'],
            ],
            'hosting_settings' => [
                'ftp_login' => $ftpLogin,
                'ftp_password' => $ftpPassword,
            ],
            'plan' => [
                'name' => $plan,
            ],
        ]);

        $domainId = (string) ($created['id'] ?? '');

        if ($domainId === '') {
            throw new Exception('Plesk did not return the new subscription ID.');
        }

        return [
            'domain_id' => $domainId,
            'domain' => $domain,
            'plan' => $plan,
            'ftp_login' => $ftpLogin,
            'ftp_password' => $ftpPassword,
            'client_id' => (string) $customer['id'],
            'client_login' => $customer['login'],
            'client_password' => $customer['password'] ?? null,
            'last_error' => null,
        ];
    }

    public function suspend(Order $order): void
    {
        $this->api->setDomainStatus($this->domainId($order), 'suspended');
    }

    public function unsuspend(Order $order): void
    {
        $this->api->setDomainStatus($this->domainId($order), 'active');
    }

    public function terminate(Order $order): void
    {
        $this->api->deleteDomain($this->domainId($order));
    }

    /**
     * @return array<string, mixed>
     */
    public function upgrade(Order $order, PackagePrice $newPackagePrice): array
    {
        $plan = (string) $newPackagePrice->package->data('plan', '');

        if ($plan === '') {
            throw new Exception('The new package has no Plesk service plan.');
        }

        $this->api->switchPlan($this->domainName($order), $plan);

        $data = $order->data ?? [];
        $data['plan'] = $plan;
        $data['last_error'] = null;

        return $data;
    }

    public function changePassword(Order $order, string $password): void
    {
        $clientId = (string) ($order->data['client_id'] ?? $order->getExternalUser()?->external_id ?? '');

        if ($clientId === '') {
            throw new Exception('This Plesk customer has not finished provisioning yet.');
        }

        $this->api->changeClientPassword($clientId, $password);
    }

    public function loginUrl(Order $order): string
    {
        $login = (string) ($order->data['client_login'] ?? $order->getExternalUser()?->username ?? '');

        if ($login === '') {
            throw new Exception('This Plesk customer has not finished provisioning yet.');
        }

        return $this->api->loginUrl($login);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Order $order): array
    {
        $domain = $this->api->domain($this->domainId($order));

        return [
            'domain' => $domain['name'] ?? ($order->data['domain'] ?? null),
            'ip' => $domain['ipv4'][0] ?? ($domain['ip_addresses'][0] ?? null),
            'status' => $domain['status'] ?? null,
            'suspended' => isset($domain['status']) && (string) $domain['status'] !== 'active' && (string) $domain['status'] !== '0',
            'created' => $domain['created'] ?? null,
        ];
    }

    public function domainId(Order $order): string
    {
        $domainId = (string) ($order->external_id ?: ($order->data['domain_id'] ?? ''));

        if ($domainId === '') {
            throw new Exception('This Plesk subscription has not finished provisioning yet.');
        }

        return $domainId;
    }

    protected function domainName(Order $order): string
    {
        $domain = (string) ($order->data['domain'] ?? '');

        if ($domain === '') {
            $domain = (string) ($this->api->domain($this->domainId($order))['name'] ?? '');
        }

        return $domain;
    }

    /**
     * Find or create the Plesk customer that owns this WemX user's subscriptions.
     *
     * @return array{id: int|string, login: string, password?: string}
     */
    protected function customer(Order $order): array
    {
        $externalId = 'wemx-user-'.$order->user->id;
        $existing = $this->api->findClientByExternalId($externalId);

        if ($existing) {
            return ['id' => $existing['id'], 'login' => (string) $existing['login']];
        }

        $login = $this->customerLogin($order);
        $password = Str::password(16, symbols: false);
        $name = trim(($order->user->first_name ?? '').' '.($order->user->last_name ?? '')) ?: (string) $order->user->username;

        $created = $this->api->createClient([
            'name' => $name,
            'login' => $login,
            'password' => $password,
            'email' => $order->user->email,
            'external_id' => $externalId,
        ]);

        if (empty($created['id'])) {
            throw new Exception('Plesk did not create the customer account.');
        }

        return ['id' => $created['id'], 'login' => $login, 'password' => $password];
    }

    protected function customerLogin(Order $order): string
    {
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', Str::before((string) $order->user->email, '@')));

        if ($base === '' || ! preg_match('/^[a-z]/', $base)) {
            $base = 'c'.$base;
        }

        return substr($base, 0, 20).$order->user->id;
    }

    protected function systemLogin(string $domain): string
    {
        $base = strtolower((string) preg_replace('/[^a-z0-9]/', '', Str::before($domain, '.')));

        if ($base === '' || ! preg_match('/^[a-z]/', $base)) {
            $base = 'w'.$base;
        }

        return substr($base, 0, 12).Str::lower(Str::random(4));
    }
}
