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

    #[Computed]
    public function order(): ?Order
    {
        return Order::query()->with('package.serverConnection')->find($this->order_id);
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
            return null;
        }
    }
}

?>

@php
    $order = $this->order;
    $summary = $this->summary;
    $account = $order?->getExternalUser();
@endphp

@if($order)
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">{{ __('server-plesk::messages.admin_title') }}</h3>
        </div>
        <div class="card-body">
            <div class="datagrid">
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-plesk::messages.status') }}</div>
                    <div class="datagrid-content">
                        @if($order->status === 'suspended' || ($summary['suspended'] ?? false))
                            <span class="badge bg-yellow">{{ __('server-plesk::messages.suspended_badge') }}</span>
                        @elseif($summary)
                            <span class="badge bg-green">{{ __('server-plesk::messages.active') }}</span>
                        @else
                            <span class="badge bg-secondary">{{ $order->external_id ? __('server-plesk::messages.unknown') : __('server-plesk::messages.not_provisioned') }}</span>
                        @endif
                    </div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-plesk::messages.remote_id') }}</div>
                    <div class="datagrid-content">{{ $order->external_id ?? '—' }}</div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-plesk::messages.domain') }}</div>
                    <div class="datagrid-content">{{ $summary["domain"] ?? ($order->data["domain"] ?? '—') }}</div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-plesk::messages.ip') }}</div>
                    <div class="datagrid-content">{{ $summary['ip'] ?? ($order->data['ip'] ?? '—') }}</div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-plesk::messages.plan') }}</div>
                    <div class="datagrid-content">{{ $order->data['plan'] ?? '—' }}</div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-plesk::messages.username') }}</div>
                    <div class="datagrid-content">{{ $account->username ?? '—' }}</div>
                </div>
            </div>

            @if(!empty($order->data['last_error']))
                <div class="alert alert-danger mt-3 mb-0">
                    <div class="alert-title">{{ __('server-plesk::messages.last_error') }}</div>
                    {{ $order->data['last_error'] }}
                </div>
            @endif

            @if($order->external_id)
                <div class="mt-3">
                    <a href="{{ route('admin.plesk.login', $order) }}" target="_blank" class="btn btn-primary btn-sm">
                        {{ __('server-plesk::messages.login_as') }}
                    </a>
                </div>
            @endif
        </div>
    </div>
@endif
