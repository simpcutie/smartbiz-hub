<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\ShopService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function purchases(Request $r)
    {
        $q = PurchaseOrder::with('supplier', 'user');
        if ($r->query('status')) {
            $q->where('status', $r->query('status'));
        }

        return view('purchases.index', ['purchases' => $q->latest('id')->paginate(12)->withQueryString()]);
    }

    public function purchaseForm()
    {
        return view('purchases.create', ['suppliers' => Supplier::where('active', true)->orderBy('name')->get(), 'products' => Product::where('active', true)->orderBy('name')->get()]);
    }

    public function createPurchase(Request $r, ShopService $shop)
    {
        $d = $r->validate(['supplier_id' => 'required|integer|exists:suppliers,id', 'order_date' => 'required|date', 'expected_date' => 'nullable|date|after_or_equal:order_date', 'discount' => 'nullable|numeric|min:0', 'shipping' => 'nullable|numeric|min:0', 'remarks' => 'nullable|string|max:1000', 'items' => 'required|array|min:1|max:50', 'items.*.product_id' => 'required|integer|distinct|exists:products,id', 'items.*.quantity' => 'required|integer|min:1|max:10000', 'items.*.unit_cost' => 'required|numeric|gt:0']);
        $po = $shop->purchase($d, $r->user());

        return redirect()->route('purchases.show', $po)->with('success', 'Purchase order created.');
    }

    public function purchase(PurchaseOrder $purchase)
    {
        return view('purchases.show', ['purchase' => $purchase->load('supplier', 'user', 'items.product')]);
    }

    public function purchaseStatus(Request $r, PurchaseOrder $purchase, ShopService $shop)
    {
        $d = $r->validate(['action' => ['required', Rule::in(['approve', 'order', 'cancel'])]]);
        $shop->purchaseStatus($purchase, $d['action'], $r->user());

        return back()->with('success', 'Purchase status updated.');
    }

    public function receive(Request $r, PurchaseOrder $purchase, ShopService $shop)
    {
        $d = $r->validate(['request_key' => 'required|uuid', 'quantities' => 'required|array', 'quantities.*' => 'required|integer|min:0|max:10000']);
        $shop->receive($purchase, $d['quantities'], $r->user(), $d['request_key']);

        return back()->with('success', 'Delivery received and inventory updated.');
    }

    public function orders(Request $r)
    {
        $q = CustomerOrder::with('customer', 'billing');
        if ($r->query('status')) {
            $q->where('status', $r->query('status'));
        }
        if ($s = $r->query('search')) {
            $q->where(fn ($q) => $q->where('number', 'like', '%'.substr($s, 0, 100).'%')->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.substr($s, 0, 100).'%')));
        }

        return view('orders.index', ['orders' => $q->latest('id')->paginate(12)->withQueryString()]);
    }

    public function orderForm()
    {
        return view('orders.create', ['customers' => Customer::where('active', true)->orderBy('name')->get(), 'products' => Product::with('stock')->where('active', true)->orderBy('name')->get(), 'token' => Str::uuid()->toString()]);
    }

    public function createOrder(Request $r, ShopService $shop)
    {
        $d = $r->validate(['customer_id' => 'required|integer|exists:customers,id', 'fulfillment' => ['required', Rule::in(['Pickup', 'Delivery'])], 'delivery_address' => 'required_if:fulfillment,Delivery|nullable|string|max:1000', 'shipping' => 'nullable|numeric|min:0', 'pickup_date' => 'nullable|date|after_or_equal:today', 'notes' => 'nullable|string|max:1000', 'request_key' => 'required|uuid', 'items' => 'required|array|min:1|max:50', 'items.*.product_id' => 'required|integer|distinct|exists:products,id', 'items.*.quantity' => 'required|integer|min:1|max:10000']);
        $order = $shop->order($d, $r->user(), Customer::findOrFail($d['customer_id']));

        return redirect()->route('orders.show', $order)->with('success', 'Walk-in order created.');
    }

    public function order(CustomerOrder $order)
    {
        return view('orders.show', ['order' => $order->load('customer', 'items.product', 'billing.payments', 'user'), 'public' => false]);
    }

    public function orderStatus(Request $r, CustomerOrder $order, ShopService $shop)
    {
        $d = $r->validate(['action' => ['required', Rule::in(['process', 'ready', 'dispatch', 'complete', 'cancel'])], 'courier' => 'nullable|string|max:100', 'tracking_number' => 'nullable|string|max:100']);
        $shop->orderStatus($order, $d['action'], $r->user(), $d);

        return back()->with('success', 'Order status updated.');
    }

    public function billing(Request $r)
    {
        $q = Billing::with('order.customer');
        if ($r->query('status')) {
            $q->where('status', $r->query('status'));
        }

        return view('billing.index', ['billings' => $q->latest('id')->paginate(12)->withQueryString()]);
    }

    public function billForm(CustomerOrder $order)
    {
        abort_if($order->status === 'Cancelled', 422);

        return view('billing.create', compact('order'));
    }

    public function createBill(Request $r, CustomerOrder $order, ShopService $shop)
    {
        $d = $r->validate(['discount' => 'nullable|numeric|min:0', 'tax' => 'nullable|numeric|min:0', 'additional' => 'nullable|numeric|min:0', 'shipping' => 'nullable|numeric|min:0', 'due_date' => 'nullable|date|after_or_equal:today', 'remarks' => 'nullable|string|max:1000']);
        $bill = $shop->bill($order, $d, $r->user());

        return redirect()->route('billing.show', $bill)->with('success', 'Bill saved. Payments can now be recorded.');
    }

    public function bill(Billing $billing)
    {
        return view('billing.show', ['billing' => $billing->load('order.customer', 'order.items', 'payments.user'), 'token' => Str::uuid()->toString()]);
    }

    public function payments(Request $r)
    {
        $q = Payment::with('billing.order.customer', 'user');
        if ($r->query('status')) {
            $q->where('status', $r->query('status'));
        }

        return view('billing.payments', ['payments' => $q->latest('id')->paginate(12)->withQueryString()]);
    }

    public function pay(Request $r, Billing $billing, ShopService $shop)
    {
        $d = $r->validate(['amount' => 'required|numeric|gt:0', 'method' => ['required', Rule::in(['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Card'])], 'reference' => 'nullable|string|max:100', 'remarks' => 'nullable|string|max:500', 'request_key' => 'required|uuid']);
        $p = $shop->pay($billing, $d, $r->user());

        return redirect()->route('billing.show', $billing)->with('success', 'Payment recorded: '.$p->number.'.');
    }

    public function void(Request $r, Payment $payment, ShopService $shop)
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:500']);
        $shop->void($payment, $d['reason'], $r->user());

        return back()->with('success', 'Payment voided. The bill balance has been recalculated.');
    }
}
