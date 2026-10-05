<?php

namespace Extensions\Servers\Plesk;

use App\Extensions\Foundation\ServerExtension;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\ServerConnection;
use Exception;
use Extensions\Servers\Plesk\Actions\PleskActions;
use Extensions\Servers\Plesk\Providers\PleskServiceProvider;
use Extensions\Servers\Plesk\Support\PleskApi;
use Extensions\Servers\Plesk\Support\PleskManager;
use Illuminate\Support\Facades\Cache;

class Server extends ServerExtension
{
    protected string $id = 'server-plesk';

    protected string $name = 'Plesk';

    protected string $description = 'Resell Plesk hosting subscriptions from a service plan, with one-click Plesk login, password changes and plan upgrades.';

    protected string $type = 'Server';

    protected string $icon = 'server';

    protected string $version = '1.0.0';

    protected array $wemxVersions = ['*'];

    protected array $authors = [
        [
            'name' => 'WemX',
            'email' => 'mubeen@wemx.net',
        ],
    ];

    public function providers(): array
    {
        return [
            PleskServiceProvider::class,
        ];
    }

    public function elements(): array
    {
        return [
            [
                'element' => 'client-order-top-view',
                'view' => 'server-plesk::client_area.default.orders.widgets.subscription-panel',
            ],
            [
                'element' => 'admin-order-sidebar-view',
                'view' => 'server-plesk::admin_area.default.orders.widgets.subscription-sidebar',
            ],
        ];
    }

    public function setConfig(): array
    {
        return [
            [
                'key' => 'hostname',
                'name' => 'Hostname',
                'description' => 'Plesk URL without a port or trailing slash, for example https://plesk.example.com',
                'type' => 'text',
                'default_value' => 'https://plesk.example.com',
                'rules' => ['required', 'string', 'regex:#^https?://#', 'not_regex:/\/$/'],
            ],
            [
                'key' => 'port',
                'name' => 'Port',
                'description' => 'Plesk port. Default is 8443.',
                'type' => 'number',
                'default_value' => 8443,
                'rules' => ['required', 'numeric', 'min:1', 'max:65535'],
            ],
            [
                'key' => 'api_key',
                'name' => 'API key',
                'description' => 'Preferred. Create one with "plesk bin secret_key -c". Leave empty to use the username and password below.',
                'type' => 'password',
                'rules' => ['nullable', 'string'],
            ],
            [
                'key' => 'username',
                'name' => 'Admin username',
                'description' => 'Only used when no API key is set.',
                'type' => 'text',
                'default_value' => 'admin',
                'rules' => ['nullable', 'string'],
            ],
            [
                'key' => 'password',
                'name' => 'Admin password',
                'description' => 'Only used when no API key is set.',
                'type' => 'password',
                'rules' => ['nullable', 'string'],
            ],
            [
                'key' => 'verify_ssl',
                'name' => 'Verify SSL',
                'description' => 'Disable this for self-signed certificates.',
                'type' => 'select',
                'options' => [
                    '0' => 'Disabled',
                    '1' => 'Enabled',
                ],
                'default_value' => '1',
                'rules' => ['required', 'in:0,1'],
            ],
            [
                'key' => 'debug_mode',
                'name' => 'Debug mode',
                'description' => 'Include API endpoints in error messages. Keep disabled in production.',
                'type' => 'select',
                'options' => [
                    '0' => 'Disabled',
                    '1' => 'Enabled',
                ],
                'default_value' => '0',
                'rules' => ['required', 'in:0,1'],
            ],
        ];
    }

    public function setPackageConfig(Package $package, ServerConnection $connection): array
    {
        $plans = $this->cachedPlans($connection);

        return [
            $plans === []
                ? [
                    'key' => 'plan',
                    'name' => 'Service plan',
                    'col' => 'col-4',
                    'description' => 'Service plan name from Plesk → Service Plans. The connection is offline, so enter the name manually.',
                    'type' => 'text',
                    'rules' => ['required', 'string'],
                    'is_configurable' => false,
                ]
                : [
                    'key' => 'plan',
                    'name' => 'Service plan',
                    'col' => 'col-4',
                    'description' => 'Plesk service plan that sets the subscription limits.',
                    'type' => 'select',
                    'options' => $plans,
                    'default_value' => array_key_first($plans),
                    'rules' => ['required', 'string'],
                    'is_configurable' => false,
                ],
            $this->toggle('allow_login', 'Allow Plesk login', 'Let customers open Plesk with one click.'),
            $this->toggle('allow_password_change', 'Allow password change', 'Let customers change their Plesk password. It applies to all their Plesk subscriptions.'),
        ];
    }

    public function setCheckoutConfig(Package $package): array
    {
        return [
            [
                'key' => 'domain',
                'name' => 'Domain',
                'description' => 'The domain for this hosting subscription, for example example.com',
                'type' => 'text',
                'rules' => ['required', 'string', 'max:191', 'regex:/^(?=.{1,253}$)(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$/'],
                'is_configurable' => true,
            ],
        ];
    }

    public static function testConnection(array $credentials): string
    {
        $server = PleskApi::make($credentials)->server();
        $version = $server['platform'] ?? $server['panel_version'] ?? $server['hostname'] ?? 'Plesk';

        return "Connected to {$version}.";
    }

    public function create(Order $order, ServerConnection $connection): void
    {
        $data = [];

        $this->withErrorTracking($order, function () use ($order, $connection, &$data) {
            $data = PleskManager::for($connection)->create($order);
            self::actions()->storeProvisionedState($order, $data);
        });

        if (empty($data['ftp_password'])) {
            return;
        }

        $order->refresh();

        $order->user->email([
            'identifier' => 'server.plesk.created',
            'mailable_type' => Order::class,
            'mailable_id' => $order->id,
            'variables' => [
                'domain' => $data['domain'],
                'panel_url' => PleskApi::fromConnection($connection)->baseUrl(),
                'username' => $data['client_login'],
                'password' => $data['client_password'] ?? 'Use your existing Plesk password or the one-click login on your order page.',
                'ftp_login' => $data['ftp_login'],
                'ftp_password' => $data['ftp_password'],
            ],
            'button' => [
                'url' => route('orders.view', $order->id),
            ],
        ]);
    }

    public function suspend(Order $order, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, fn () => PleskManager::for($connection)->suspend($order));
    }

    public function unsuspend(Order $order, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, fn () => PleskManager::for($connection)->unsuspend($order));
    }

    public function terminate(Order $order, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, fn () => PleskManager::for($connection)->terminate($order));
    }

    public function upgradeOrDowngrade(Order $order, PackagePrice $oldPackagePrice, PackagePrice $newPackagePrice, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, function () use ($order, $newPackagePrice, $connection) {
            $order->update(['data' => PleskManager::for($connection)->upgrade($order, $newPackagePrice)]);
        });
    }

    public function changePassword(Order $order, string $newPassword): void
    {
        PleskManager::for($order->package->serverConnection)->changePassword($order, $newPassword);
        $order->updateExternalPassword($newPassword);
    }

    public static function actions(): PleskActions
    {
        return new PleskActions;
    }

    public static function usesPlesk(?Order $order): bool
    {
        return $order?->package?->serverConnection?->extension_identifier === 'server-plesk';
    }

    protected function withErrorTracking(Order $order, callable $callback): void
    {
        try {
            $callback();
        } catch (Exception $exception) {
            self::actions()->rememberError($order, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function toggle(string $key, string $name, string $description): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'col' => 'col-4',
            'description' => $description,
            'type' => 'select',
            'options' => ['1' => 'Enabled', '0' => 'Disabled'],
            'default_value' => '1',
            'rules' => ['required', 'in:0,1'],
            'is_configurable' => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function cachedPlans(ServerConnection $connection): array
    {
        $connectionId = $connection->id ?? 'new';

        try {
            return Cache::remember("plesk:plans:{$connectionId}", now()->addHour(), function () use ($connection) {
                return collect(PleskApi::fromConnection($connection)->servicePlans())
                    ->mapWithKeys(fn ($plan) => [$plan => $plan])
                    ->all();
            });
        } catch (Exception) {
            return [];
        }
    }
}
