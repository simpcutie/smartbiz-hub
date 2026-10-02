@props(['order'])
@php
    $delivery=$order->fulfillment==='Delivery';
    $stages=$delivery ? ['Pending'=>'Order received','Processing'=>'Processing','Ready for Delivery'=>'Ready for delivery','Out for Delivery'=>'Out for delivery','Completed'=>'Completed'] : ['Pending'=>'Order received','Processing'=>'Processing','Ready for Pickup'=>'Ready for pickup','Completed'=>'Completed'];
    $current=array_search($order->status,array_keys($stages),true);
    $message=match($order->status){
        'Pending'=>'Your order has been received. Our team will review it and prepare your bill.',
        'Processing'=>'Our team is preparing your order. Check your bill and payment balance below.',
        'Ready for Pickup'=>'Your order is ready for pickup. Any remaining balance must be settled before collection.',
        'Ready for Delivery'=>'Your order is ready for delivery. Staff will arrange dispatch after full payment is recorded.',
        'Out for Delivery'=>'Your order has been dispatched. See the courier reference below for delivery details.',
        'Completed'=>'Your order is complete. Your bill and recorded payment receipts remain available below.',
        'Cancelled'=>'This order has been cancelled. No further preparation or delivery will take place.',
        default=>'Check this page for the latest order details.'
    };
@endphp
<section class="panel mb-4 order-update" aria-labelledby="order-update-title">
    <span class="eyebrow">ORDER UPDATE</span><h2 id="order-update-title">{{ $order->status==='Pending' ? 'Order received' : $order->status }}</h2>
    <p>{{ $message }}</p><small>Reference: <strong>{{ $order->number }}</strong> · Last order update: {{ $order->updated_at->format('M d, Y · h:i A') }}</small>
    @if($current!==false)
    <ol class="order-tracker" aria-label="Order progress">
        @foreach($stages as $status=>$label)<li class="{{ $loop->index<$current?'is-done':($loop->index===$current?'is-current':'') }}" @if($loop->index===$current) aria-current="step" @endif><span aria-hidden="true">{{ $loop->index<$current?'✓':$loop->iteration }}</span><div>{{ $label }}<small>{{ $loop->index<$current?'Done':($loop->index===$current?'Current step':'Next') }}</small></div></li>@endforeach
    </ol>
    @endif
    <p class="small text-muted mb-0 mt-3">Check My Account for order updates. Delivery status and courier references are updated by clinic staff.</p>
</section>
