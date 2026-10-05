@if(isset($order) && \Extensions\Servers\Plesk\Server::usesPlesk($order))
    @livewire('admin_area.default.orders.livewire.plesk-subscription-sidebar', ['order_id' => $order->id])
@endif
