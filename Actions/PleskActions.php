<?php

namespace Extensions\Servers\Plesk\Actions;

use App\Actions\Action;
use App\Models\Order;
use App\Models\User;
use Extensions\Servers\Plesk\Server;
use Extensions\Servers\Plesk\Support\PleskManager;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PleskActions extends Action
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function loginAsClient(array $input): string
    {
        $validated = $this->validateOrderInput($input);
        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id'], requireActive: true);

        $this->assertFlag($order, 'allow_login', 'Plesk login is not enabled for this package.');

        return PleskManager::for($order->package->serverConnection)->loginUrl($order);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function loginAsAdmin(array $input): string
    {
        $validated = $this->validateOrderInput($input);
        $order = $this->adminOrder($validated['order_id'], $validated['user_id']);

        return PleskManager::for($order->package->serverConnection)->loginUrl($order);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function changePasswordAsClient(array $input): Order
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
            'password' => ['required', 'string', 'min:8', 'max:64'],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id'], requireActive: true);

        $this->assertFlag($order, 'allow_password_change', 'Password changes are not enabled for this package.');

        PleskManager::for($order->package->serverConnection)->changePassword($order, $validated['password']);
        $order->updateExternalPassword($validated['password']);

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeProvisionedState(Order $order, array $data): void
    {
        $clientPassword = $data['client_password'] ?? null;
        $orderData = $data;
        unset($orderData['client_password'], $orderData['ftp_password']);

        $order->update([
            'external_id' => (string) $data['domain_id'],
            'data' => $orderData,
        ]);

        if (empty($data['client_id'])) {
            return;
        }

        $account = $order->getExternalUser();

        if ($account) {
            $account->update([
                'external_id' => (string) $data['client_id'],
                'username' => $data['client_login'],
                'password' => $clientPassword ?? $account->password,
            ]);

            return;
        }

        $order->createExternalUser([
            'external_id' => (string) $data['client_id'],
            'username' => $data['client_login'],
            'password' => $clientPassword ?? 'unknown',
            'data' => ['client_id' => $data['client_id']],
        ]);
    }

    public function rememberError(Order $order, string $message): void
    {
        $data = $order->data ?? [];
        $data['last_error'] = $message;
        $order->update(['data' => $data]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function validateOrderInput(array $input): array
    {
        return Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
        ])->validate();
    }

    protected function assertFlag(Order $order, string $flag, string $message): void
    {
        if ((string) $order->option($flag, '1') !== '1') {
            throw ValidationException::withMessages([
                'order_id' => $message,
            ]);
        }
    }

    protected function authorizedOrder(int|string $orderId, int|string $userId, bool $requireActive = false): Order
    {
        $order = Order::query()->with(['package.serverConnection', 'members', 'user'])->find($orderId);
        $user = User::query()->find($userId);

        if (! $order || ! $user || ! Server::usesPlesk($order)) {
            throw ValidationException::withMessages([
                'order_id' => 'This order is not provisioned on Plesk.',
            ]);
        }

        $isOwner = (int) $order->user_id === (int) $user->id;
        $isMember = $order->members()
            ->where('status', 'active')
            ->where('user_id', $user->id)
            ->exists();

        if (! $isOwner && ! $isMember) {
            throw ValidationException::withMessages([
                'order_id' => 'You do not have access to this order.',
            ]);
        }

        if ($requireActive && $order->status !== 'active') {
            throw ValidationException::withMessages([
                'order_id' => 'This action is only available while the subscription is active.',
            ]);
        }

        $this->assertProvisioned($order);

        return $order;
    }

    protected function adminOrder(int|string $orderId, int|string $userId): Order
    {
        $order = Order::query()->with(['package.serverConnection', 'user'])->find($orderId);
        $user = User::query()->find($userId);

        if (! $order || ! Server::usesPlesk($order)) {
            throw ValidationException::withMessages([
                'order_id' => 'This order is not provisioned on Plesk.',
            ]);
        }

        if (! $user || (! $user->isAdmin() && ! $user->hasPermission('admin.orders.view'))) {
            throw ValidationException::withMessages([
                'order_id' => 'You do not have access to this order.',
            ]);
        }

        $this->assertProvisioned($order);

        return $order;
    }

    protected function assertProvisioned(Order $order): void
    {
        if (! $order->external_id && empty($order->data['domain_id'])) {
            throw ValidationException::withMessages([
                'order_id' => 'This Plesk subscription has not finished provisioning yet.',
            ]);
        }
    }
}
