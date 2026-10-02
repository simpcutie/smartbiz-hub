<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockManagement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use App\Support\TransactionLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopService
{
    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['transaction' => $message]);
        }
    }

    private function move(StockManagement $stock, string $type, int $quantity, string $reference, ?int $actor, ?string $remarks = null): void
    {
        $changes = ['updated_by' => $actor, 'remarks' => $type.' · '.$reference.($remarks ? ' · '.$remarks : '')];
        if ($type === 'Received' || ($type === 'Adjustment' && $quantity > 0)) {
            $changes['last_stock_in'] = now();
        }
        if ($type === 'Sold' || ($type === 'Adjustment' && $quantity < 0)) {
            $changes['last_stock_out'] = now();
        }
        $stock->update($changes);
    }

    public function purchase(array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->check($actor->role === 'Admin', 'Only Admin can create purchases.');
            $this->check(Supplier::whereKey($data['supplier_id'])->where('active', true)->exists(), 'Choose an active supplier.');
            $lines = [];
            $subtotal = 0;
            foreach ($data['items'] as $line) {
                $product = Product::whereKey($line['product_id'])->where('active', true)->first();
                $this->check((bool) $product, 'A selected product is unavailable.');
                $this->check(! isset($lines[$product->id]), 'Select each product only once.');
                $quantity = (int) $line['quantity'];
                $cost = Money::cents($line['unit_cost']);
                $this->check($quantity > 0 && $quantity <= 10000 && $cost > 0, 'Enter positive quantities and costs.');
                $total = $quantity * $cost;
                $subtotal += $total;
                $lines[$product->id] = ['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost_cents' => $cost, 'subtotal_cents' => $total];
            }
            $this->check(count($lines) > 0, 'Add at least one product.');
            $discount = Money::cents($data['discount'] ?? '0');
            $shipping = Money::cents($data['shipping'] ?? '0');
            $this->check($discount <= $subtotal, 'Discount cannot exceed subtotal.');
            $po = PurchaseOrder::create(['supplier_id' => $data['supplier_id'], 'user_id' => $actor->id, 'order_date' => $data['order_date'], 'expected_date' => $data['expected_date'] ?? null, 'subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'shipping_cents' => $shipping, 'total_cents' => $subtotal - $discount + $shipping, 'remarks' => $data['remarks'] ?? null]);
            $po->update(['number' => sprintf('PO-%06d', $po->id)]);
            $po->items()->createMany(array_values($lines));
            TransactionLog::record('Purchase created', $po->number, $actor->id);

            return $po;
        }, 3);
    }

    public function purchaseStatus(PurchaseOrder $purchase, string $action, User $actor): void
    {
        DB::transaction(function () use ($purchase, $action, $actor) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($purchase->id);
            $this->check($actor->role === 'Admin', 'Only Admin can manage purchasing.');
            $target = match ($action) {
                'approve' => 'Approved', 'order' => 'Ordered', 'cancel' => 'Cancelled', default => ''
            };
            $allowed = ($action === 'approve' && $po->status === 'Pending') || ($action === 'order' && $po->status === 'Approved') || ($action === 'cancel' && in_array($po->status, ['Pending', 'Approved', 'Ordered']) && ! $po->items()->where('received', '>', 0)->exists());
            $this->check($allowed, 'This purchase status change is not allowed.');
            $po->update(['status' => $target]);
            TransactionLog::record('Purchase '.$target, $po->number, $actor->id);
        }, 3);
    }

    // Receiving increases physical stock; partial receipts keep the remaining purchase open.
    public function receive(PurchaseOrder $purchase, array $quantities, User $actor, ?string $requestKey = null): void
    {
        DB::transaction(function () use ($purchase, $quantities, $actor, $requestKey) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($purchase->id);
            $this->check($actor->role === 'Admin', 'Only Admin can receive purchases.');
            if ($requestKey && in_array($requestKey, $po->receipt_keys ?? [], true)) {
                return;
            }
            $this->check(in_array($po->status, ['Ordered', 'Partially Received']), 'Only ordered purchases can be received.');
            $items = $po->items()->orderBy('product_id')->get();
            $receivedTotal = 0;
            foreach ($items as $item) {
                $quantity = (int) ($quantities[$item->id] ?? 0);
                $this->check($quantity >= 0 && $quantity <= ($item->quantity - $item->received), 'Receipt exceeds the remaining quantity.');
                if (! $quantity) {
                    continue;
                }
                $stock = StockManagement::where('product_id', $item->product_id)->lockForUpdate()->firstOrFail();
                $stock->on_hand += $quantity;
                $stock->quantity_in += $quantity;
                $stock->save();
                $item->received += $quantity;
                $item->save();
                $receivedTotal += $quantity;
                $this->move($stock, 'Received', $quantity, $po->number, $actor->id);
            }
            $this->check($receivedTotal > 0, 'Enter at least one quantity received.');
            $complete = ! $po->items()->whereColumn('received', '<', 'quantity')->exists();
            $po->update(['status' => $complete ? 'Received' : 'Partially Received', 'received_date' => $complete ? today()->toDateString() : null]);
            TransactionLog::record('Delivery received', $po->number, $actor->id);
            if ($requestKey) {
                $po->update(['receipt_keys' => [...($po->receipt_keys ?? []), $requestKey]]);
            }
        }, 3);
    }

    public function order(array $data, User $actor, Customer $customer): CustomerOrder
    {
        return DB::transaction(function () use ($data, $actor, $customer) {
            // Lock the customer before the idempotency lookup, serializing repeated submissions.
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);
            $this->check($customer->active, 'This customer is inactive.');
            $this->check($actor->role !== 'Customer' || $customer->user_id === $actor->id, 'This customer account does not belong to you.');
            if ($existing = CustomerOrder::where('request_key', $data['request_key'])->first()) {
                $this->check($existing->customer_id === $customer->id, 'Invalid request token.');

                return $existing;
            }
            $lines = collect($data['items'])->sortBy('product_id');
            $items = [];
            $subtotal = 0;
            foreach ($lines as $line) {
                $product = Product::whereKey($line['product_id'])->where('active', true)->first();
                $this->check((bool) $product, 'A selected product is unavailable.');
                $this->check(! isset($items[$product->id]), 'Select each product only once.');
                $stock = StockManagement::where('product_id', $product->id)->lockForUpdate()->firstOrFail();
                $quantity = (int) $line['quantity'];
                $this->check($quantity > 0 && $quantity <= 10000 && $quantity <= ($stock->on_hand - $stock->reserved), 'Insufficient available stock for '.$product->name.'.');
                // Reserve items now; physical on-hand stock is deducted only on completion.
                $stock->reserved += $quantity;
                $stock->save();
                $total = $quantity * $product->price_cents;
                $subtotal += $total;
                $items[$product->id] = ['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity, 'unit_price_cents' => $product->price_cents, 'subtotal_cents' => $total];
            }
            $this->check(count($items) > 0, 'Add at least one product.');
            $shipping = $data['fulfillment'] === 'Delivery' ? Money::cents($data['shipping'] ?? '0') : 0;
            $order = CustomerOrder::create(['customer_id' => $customer->id, 'user_id' => $actor->role === 'Customer' ? null : $actor->id, 'type' => $actor->role === 'Customer' ? 'Online' : 'Walk-in', 'fulfillment' => $data['fulfillment'], 'delivery_address' => $data['fulfillment'] === 'Delivery' ? ($data['delivery_address'] ?? '') : null, 'pickup_date' => $data['pickup_date'] ?? null, 'subtotal_cents' => $subtotal, 'shipping_cents' => $shipping, 'total_cents' => $subtotal + $shipping, 'notes' => $data['notes'] ?? null, 'request_key' => $data['request_key']]);
            $order->update(['number' => sprintf('ORD-%06d', $order->id)]);
            $order->items()->createMany(array_values($items));
            foreach ($items as $item) {
                $this->move(StockManagement::where('product_id', $item['product_id'])->firstOrFail(), 'Reserved', $item['quantity'], $order->number, $actor->id);
            }
            TransactionLog::record('Order placed', $order->number, $actor->id);

            return $order;
        }, 3);
    }

    public function orderStatus(CustomerOrder $record, string $action, User $actor, array $data = []): void
    {
        DB::transaction(function () use ($record, $action, $actor, $data) {
            $order = CustomerOrder::lockForUpdate()->findOrFail($record->id);
            $isCustomer = $actor->role === 'Customer';
            $this->check(! $isCustomer || $order->customer->user_id === $actor->id, 'This is not your order.');
            $this->check(! $isCustomer || ($action === 'cancel' && $order->status === 'Pending'), 'Customers can cancel only pending orders.');
            $billing = $order->billing()->lockForUpdate()->first();
            if ($action === 'cancel') {
                $this->check(in_array($order->status, ['Pending', 'Processing']), 'This order cannot be cancelled at its current stage.');
                $this->check(! $billing || $billing->paid_cents === 0, 'Reverse recorded payments before cancellation.');
                foreach ($order->items()->orderBy('product_id')->get() as $item) {
                    $stock = StockManagement::where('product_id', $item->product_id)->lockForUpdate()->firstOrFail();
                    $this->check($stock->reserved >= $item->quantity, 'Stock reservation mismatch.');
                    // Cancellation makes the reserved items available to other customers again.
                    $stock->reserved -= $item->quantity;
                    $stock->save();
                    $this->move($stock, 'Released', $item->quantity, $order->number, $actor->id);
                }
                $billing?->update(['status' => 'Void', 'balance_cents' => 0]);
                $target = 'Cancelled';
            } else {
                $target = match ($action) {
                    'process' => 'Processing','ready' => $order->fulfillment === 'Pickup' ? 'Ready for Pickup' : 'Ready for Delivery','dispatch' => 'Out for Delivery','complete' => 'Completed',default => ''
                };
                $allowed = ($action === 'process' && $order->status === 'Pending') || ($action === 'ready' && $order->status === 'Processing') || ($action === 'dispatch' && $order->status === 'Ready for Delivery') || ($action === 'complete' && in_array($order->status, ['Ready for Pickup', 'Out for Delivery']));
                $this->check($allowed, 'This order status change is not allowed.');
                if (in_array($action, ['dispatch', 'complete'])) {
                    $this->check($billing && $billing->status === 'Paid', 'Record full payment before release or completion.');
                }
                if ($action === 'dispatch') {
                    $this->check(! empty($data['courier']) && ! empty($data['tracking_number']), 'Enter courier and tracking details.');
                    $order->courier = $data['courier'];
                    $order->tracking_number = $data['tracking_number'];
                }
                // The allowed-status check above prevents completing and deducting the same order twice.
                if ($action === 'complete') {
                    foreach ($order->items()->orderBy('product_id')->get() as $item) {
                        $stock = StockManagement::where('product_id', $item->product_id)->lockForUpdate()->firstOrFail();
                        $this->check($stock->on_hand >= $item->quantity && $stock->reserved >= $item->quantity, 'Stock reservation mismatch.');
                        $stock->on_hand -= $item->quantity;
                        $stock->reserved -= $item->quantity;
                        $stock->quantity_out += $item->quantity;
                        $stock->save();
                        $this->move($stock, 'Sold', -$item->quantity, $order->number, $actor->id);
                    }
                }
            }
            $order->status = $target;
            if (! $isCustomer) {
                $order->user_id = $actor->id;
            } $order->save();
            TransactionLog::record('Order '.$target, $order->number, $actor->id);
        }, 3);
    }

    public function bill(CustomerOrder $record, array $data, User $actor): Billing
    {
        return DB::transaction(function () use ($record, $data, $actor) {
            $order = CustomerOrder::lockForUpdate()->findOrFail($record->id);
            $this->check(in_array($actor->role, ['Admin', 'Staff']), 'Only staff can generate bills.');
            $this->check(! in_array($order->status, ['Cancelled', 'Completed', 'Out for Delivery']), 'This order cannot be billed or revised now.');
            $billing = $order->billing()->lockForUpdate()->first();
            $this->check(! $billing || $billing->paid_cents === 0, 'A bill with recorded payments cannot be changed.');
            $discount = Money::cents($data['discount'] ?? '0');
            $tax = Money::cents($data['tax'] ?? '0');
            $additional = Money::cents($data['additional'] ?? '0');
            $shipping = $order->fulfillment === 'Delivery' ? Money::cents($data['shipping'] ?? '0') : 0;
            $this->check($discount <= $order->subtotal_cents, 'Discount cannot exceed product subtotal.');
            $total = $order->subtotal_cents - $discount + $tax + $additional + $shipping;
            $billing = Billing::updateOrCreate(['order_id' => $order->id], ['billing_date' => today()->toDateString(), 'due_date' => $data['due_date'] ?? null, 'subtotal_cents' => $order->subtotal_cents, 'discount_cents' => $discount, 'tax_cents' => $tax, 'additional_cents' => $additional, 'shipping_cents' => $shipping, 'total_cents' => $total, 'balance_cents' => $total, 'status' => $total === 0 ? 'Paid' : 'Unpaid', 'remarks' => $data['remarks'] ?? null]);
            $billing->update(['number' => sprintf('BILL-%06d', $billing->id)]);
            $order->update(['discount_cents' => $discount, 'shipping_cents' => $shipping, 'total_cents' => $total]);
            TransactionLog::record('Bill generated', $billing->number, $actor->id);

            return $billing;
        }, 3);
    }

    // Staff records a payment already received; this does not charge a bank or payment gateway.
    public function pay(Billing $record, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($record, $data, $actor) {
            // Consistent lock order across payment, cancellation, completion and voiding.
            $order = CustomerOrder::lockForUpdate()->findOrFail($record->order_id);
            $billing = Billing::lockForUpdate()->findOrFail($record->id);
            $this->check(in_array($actor->role, ['Admin', 'Staff']), 'Only staff can record payments.');
            if ($existing = Payment::where('request_key', $data['request_key'])->first()) {
                $this->check($existing->billing_id === $billing->id, 'Invalid request token.');

                return $existing;
            }
            $this->check($order->status !== 'Cancelled' && $billing->status !== 'Void', 'Cannot pay a cancelled order.');
            $amount = Money::cents($data['amount']);
            $this->check($amount > 0 && $amount <= $billing->balance_cents, 'Payment must be positive and cannot exceed the remaining balance.');
            $this->check(in_array($data['method'], ['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Card']), 'Invalid payment method.');
            $this->check($data['method'] === 'Cash' || ! empty($data['reference']), 'Enter a reference for non-cash payments.');
            $payment = Payment::create(['billing_id' => $billing->id, 'user_id' => $actor->id, 'amount_cents' => $amount, 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'remarks' => $data['remarks'] ?? null, 'request_key' => $data['request_key']]);
            $payment->update(['number' => sprintf('RCPT-%06d', $payment->id)]);
            $this->recalculate($billing);
            TransactionLog::record('Payment recorded', $payment->number, $actor->id);

            return $payment;
        }, 3);
    }

    public function void(Payment $record, string $reason, User $actor): void
    {
        DB::transaction(function () use ($record, $reason, $actor) {
            $order = CustomerOrder::lockForUpdate()->findOrFail($record->billing->order_id);
            $billing = Billing::lockForUpdate()->findOrFail($record->billing_id);
            $payment = Payment::lockForUpdate()->findOrFail($record->id);
            $this->check($actor->role === 'Admin', 'Only Admin may void a payment.');
            $this->check(! in_array($order->status, ['Completed', 'Out for Delivery']), 'Released orders cannot have their payments voided.');
            $this->check($payment->status === 'Recorded' && mb_strlen(trim($reason)) >= 5, 'Enter a reason for voiding an active payment.');
            $payment->update(['status' => 'Voided', 'void_reason' => $reason]);
            $this->recalculate($billing);
            TransactionLog::record('Payment voided', $payment->number, $actor->id);
        }, 3);
    }

    private function recalculate(Billing $billing): void
    {
        $paid = (int) $billing->payments()->where('status', 'Recorded')->sum('amount_cents');
        $balance = $billing->total_cents - $paid;
        $billing->update(['paid_cents' => $paid, 'balance_cents' => $balance, 'status' => $balance === 0 ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid')]);
    }

    public function adjust(Product $product, int $quantity, string $reason, User $actor): void
    {
        DB::transaction(function () use ($product, $quantity, $reason, $actor) {
            $this->check($actor->role === 'Admin' && $quantity !== 0 && mb_strlen(trim($reason)) >= 5, 'Admin must provide a nonzero adjustment and a reason.');
            $stock = StockManagement::where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            $this->check($stock->on_hand + $quantity >= $stock->reserved, 'Adjustment would remove reserved stock.');
            $stock->on_hand += $quantity;
            if ($quantity > 0) {
                $stock->quantity_in += $quantity;
            } else {
                $stock->quantity_out += abs($quantity);
            }
            $stock->save();
            $this->move($stock, 'Adjustment', $quantity, 'ADJ-'.$stock->id.'-'.now()->format('YmdHis'), $actor->id, $reason);
            TransactionLog::record('Stock adjusted', $product->code, $actor->id);
        }, 3);
    }
}
