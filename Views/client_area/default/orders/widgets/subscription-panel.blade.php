@if(isset($order) && \Extensions\Servers\Plesk\Server::usesPlesk($order))
    @livewire('client_area.default.orders.livewire.plesk-subscription-panel', ['order_id' => $order->id])
@endif
