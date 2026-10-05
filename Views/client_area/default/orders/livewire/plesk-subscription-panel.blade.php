<?php

use App\Models\Order;
use Extensions\Servers\Plesk\Server;
use Extensions\Servers\Plesk\Support\PleskManager;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public int $order_id;

    public bool $showPassword = false;

    public string $password = '';

    #[Computed]
    public function order(): ?Order
    {
        return Order::query()->with(['package.serverConnection', 'user'])->find($this->order_id);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function summary(): ?array
    {
        $order = $this->order;

        if (! $order || ! Server::usesPlesk($order) || (! $order->external_id && empty($order->data['domain_id']))) {
            return null;
        }

        try {
            return PleskManager::for($order->package->serverConnection)->summary($order);
        } catch (Throwable) {
            return ['error' => true];
        }
    }

    public function refreshPanel(): void
    {
        unset($this->order, $this->summary);
    }

    public function changePassword(): void
    {
        Server::actions()->changePasswordAsClient([
            'order_id' => $this->order_id,
            'user_id' => auth()->id(),
            'password' => $this->password,
        ]);

        $this->reset('password');
        unset($this->order);
        $this->dispatch('toast', type: 'success', message: __('server-plesk::messages.password_updated'), title: 'Success');
    }
}

?>

<div wire:poll.60s="refreshPanel">
    @php
        $order = $this->order;
        $summary = $this->summary;
        $provisioned = $order && ($order->external_id || !empty($order->data['domain_id']));
        $canManage = $provisioned && $order->status === 'active';
        $account = $order?->getExternalUser();
        $password = $account && $account->password !== 'unknown' ? $account->password : null;
        $enabled = fn (string $flag) => (string) $order?->option($flag, '1') === '1';
    @endphp

    @if($order)
        <x-theme::card class="mb-4">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">{{ __('server-plesk::messages.subscription') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $order->data['domain'] ?? $order->package->name }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($order->status === 'suspended' || ($summary['suspended'] ?? false))
                        <x-theme::badge.warning :text="__('server-plesk::messages.suspended_badge')" />
                    @elseif($summary && empty($summary['error']))
                        <x-theme::badge.success :text="__('server-plesk::messages.active')" />
                    @else
                        <x-theme::badge.primary :text="__('server-plesk::messages.unknown')" />
                    @endif

                    @if($canManage && $enabled('allow_login'))
                        <x-theme::button.primary :href="route('plesk.login', $order)" target="_blank" :text="__('server-plesk::messages.login')" />
                    @endif
                </div>
            </div>

            @if($order->status === 'suspended')
                <x-theme::alert.warning class="mb-4" :text="__('server-plesk::messages.suspended')" />
            @endif

            @if(! $provisioned)
                <x-theme::alert.primary :text="__('server-plesk::messages.not_provisioned')" />
            @elseif(($summary['error'] ?? false) === true)
                <x-theme::alert.warning :text="__('server-plesk::messages.unavailable')" />
            @else
                <x-theme::datagrid.grid :cols="3" :gap="4">
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-plesk::messages.domain') }}</x-slot:label>
                        {{ $summary['domain'] ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-plesk::messages.ip') }}</x-slot:label>
                        {{ $summary['ip'] ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-plesk::messages.plan') }}</x-slot:label>
                        {{ $order->data['plan'] ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-plesk::messages.username') }}</x-slot:label>
                        {{ $account->username ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-plesk::messages.password') }}</x-slot:label>
                        @if($password)
                            <span class="inline-flex items-center gap-2">
                                <span>{{ $showPassword ? $password : str_repeat('•', 10) }}</span>
                                <button type="button" wire:click="$toggle('showPassword')" class="text-xs text-primary-700 hover:underline dark:text-primary-400">
                                    {{ $showPassword ? __('server-plesk::messages.hide') : __('server-plesk::messages.show') }}
                                </button>
                            </span>
                        @else
                            —
                        @endif
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-plesk::messages.ftp_login') }}</x-slot:label>
                        {{ $order->data['ftp_login'] ?? '—' }}
                    </x-theme::datagrid.item>
                </x-theme::datagrid.grid>
            @endif
        </x-theme::card>

        @if($canManage && $enabled('allow_password_change'))
            <x-theme::card class="mb-4">
                <h4 class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('server-plesk::messages.change_password') }}</h4>
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('server-plesk::messages.password_note') }}</p>
                <div class="mb-3 max-w-md">
                    <x-theme::form.label for="plesk-password" :text="__('server-plesk::messages.new_password')" />
                    <x-theme::form.input id="plesk-password" type="password" wire:model="password" />
                    @error('password')
                        <x-theme::form.error :text="$message" />
                    @enderror
                </div>
                <x-theme::button.primary type="button" wire:click="changePassword" :text="__('server-plesk::messages.save_password')" />
            </x-theme::card>
        @endif
    @endif
</div>
