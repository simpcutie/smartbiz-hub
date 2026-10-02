@extends('layouts.app')
@section('title','Your shopping cart')
@section('content')
<div class="container section-space shopping-bag" data-shopping-bag>
    <div class="bag-heading"><div><span class="eyebrow">ONE OPTICS / YOUR SELECTION</span><h1>Your shopping cart</h1><p>Choose the pairs you’re ready to order. Keep the rest for later.</p></div><ol class="bag-steps" aria-label="Order progress"><li aria-current="step"><b>1</b> Cart</li><li><b>2</b> Checkout</li><li><b>3</b> Order</li></ol></div>
    @if($products->isEmpty())
        <div class="empty-state"><x-icon name="bag"/><h2>Your next pair is waiting.</h2><p>Explore our collection and find a frame that feels like you.</p><a class="btn btn-primary" href="{{ route('catalog') }}">Browse eyewear →</a></div>
    @else
    <div class="bag-layout">
        <div class="bag-items">
            <form id="bag-selection" method="POST" action="{{ route('cart.update') }}" class="bag-toolbar" data-confirm-actions>
                @csrf @method('PUT')
                <label class="bag-check"><input type="checkbox" data-select-all><span>Select all <small>({{ $products->count() }} {{ $products->count()===1 ? 'style' : 'styles' }})</small></span></label>
                <div><button class="bag-text-button" name="intent" value="save">Save selection</button><button class="bag-text-button bag-remove" name="intent" value="remove" data-remove-selected data-confirm="Remove the selected items from your cart?">Remove selected</button></div>
            </form>
            @foreach($products as $p)
            @php($eligible=$p->active && $cart[$p->id]<=$p->available)
            <article class="bag-item {{ in_array($p->id,$selected) ? 'is-selected' : '' }}" data-bag-item>
                <input class="bag-item-check" type="checkbox" form="bag-selection" name="selected[]" value="{{ $p->id }}" data-bag-select data-price="{{ $p->price_cents }}" data-quantity="{{ $cart[$p->id] }}" aria-label="Select {{ $p->name }}" @checked(in_array($p->id,$selected)) @disabled(!$eligible)>
                <a class="bag-product-image" href="{{ $p->active ? route('product',$p) : '#' }}" aria-label="View {{ $p->name }}"><img src="{{ asset($p->image ?: 'assets/frames-1.svg') }}" alt="{{ $p->name }}"></a>
                <div class="bag-product-info"><span class="eyebrow">{{ $p->brand }}</span><h2>{{ $p->name }}</h2><p>{{ collect([$p->frame_color,$p->frame_shape,$p->frame_size])->filter()->implode(' · ') }}</p><small>Product {{ $p->code }}</small>
                    <div class="bag-price"><span>{{ \App\Support\Money::format($p->price_cents) }} / pair</span><span>Qty {{ $cart[$p->id] }}</span></div>
                    @if(!$eligible)<p class="bag-stock-warning">{{ !$p->active ? 'This frame is no longer available.' : 'Only '.$p->available.' available. Update the quantity to select this item.' }}</p>@endif
                    <div class="bag-item-actions">
                        @if($p->active && $p->available>0)
                        <details data-bag-editor><summary>Edit quantity</summary><form method="POST" action="{{ route('cart.edit',$p) }}" data-bag-action class="bag-edit-form">@csrf @method('PUT')<input type="hidden" name="selection_present" value="1"><div data-selection-fields>@foreach($selected as $id)<input type="hidden" name="selected[]" value="{{ $id }}">@endforeach</div>
                            <label for="quantity-{{ $p->id }}">Quantity <small>({{ $p->available }} available)</small></label><input id="quantity-{{ $p->id }}" class="form-control" type="number" min="1" max="{{ min(10000,$p->available) }}" name="quantity" value="{{ $cart[$p->id] }}" required data-edit-quantity>
                            <div><button class="btn btn-primary btn-sm">Save quantity</button><button class="bag-text-button" type="button" data-cancel-edit>Cancel</button></div>
                        </form></details>
                        @endif
                        <form method="POST" action="{{ route('cart.remove',$p) }}" data-bag-action data-confirm="Remove {{ $p->name }} from your cart?">@csrf @method('DELETE')<input type="hidden" name="selection_present" value="1"><div data-selection-fields>@foreach($selected as $id)<input type="hidden" name="selected[]" value="{{ $id }}">@endforeach</div><button class="bag-text-button bag-remove" aria-label="Remove {{ $p->name }}">Remove</button></form>
                    </div>
                </div><strong class="bag-line-total">{{ \App\Support\Money::format($p->price_cents*$cart[$p->id]) }}</strong>
            </article>
            @endforeach
            <a class="text-link bag-continue" href="{{ route('catalog') }}">← Continue shopping</a>
        </div>
        <aside class="panel bag-summary"><span class="eyebrow">READY WHEN YOU ARE</span><h2>Order summary</h2><p data-selected-count aria-live="polite">{{ $selectedQuantity }} {{ $selectedQuantity===1 ? 'pair' : 'pairs' }} selected</p><div class="summary-line"><span>Selected items</span><strong data-selected-total>{{ \App\Support\Money::format($subtotal) }}</strong></div><div class="summary-line"><span>Pickup / delivery</span><small>Choose at checkout</small></div><p class="bag-summary-note">Pickup at One Optics Clinic is free. Delivery fees are shown before you place your order.</p><button class="btn btn-primary w-100" form="bag-selection" name="intent" value="checkout" data-bag-checkout>Checkout selected →</button><p class="bag-selection-hint" data-selection-hint aria-live="polite">Unselected items stay in your cart.</p><div class="bag-clinic"><x-icon name="location"/><div><strong>One Optics Clinic</strong><small>2598 Singalong Street, P. Ocampo St., Manila</small></div></div></aside>
    </div>
    @endif
</div>
@endsection
@push('scripts')
<script src="{{ asset('assets/bag.js') }}"></script>
@endpush
