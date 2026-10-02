<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\CustomerOrder;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ShopService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShopWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ShopService $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->shop = app(ShopService::class);
    }

    private function admin(): User
    {
        return User::where('role', 'Admin')->firstOrFail();
    }

    private function staff(): User
    {
        return User::where('role', 'Staff')->firstOrFail();
    }

    private function customerUser(): User
    {
        return User::where('role', 'Customer')->firstOrFail();
    }

    private function newOrder(int $quantity = 2, string $fulfillment = 'Pickup'): CustomerOrder
    {
        $u = $this->customerUser();

        return $this->shop->order(['items' => [['product_id' => Product::first()->id, 'quantity' => $quantity]], 'fulfillment' => $fulfillment, 'delivery_address' => 'Test delivery address', 'shipping' => '150.00', 'request_key' => (string) Str::uuid()], $u, $u->customer);
    }

    private function rejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected business validation rejection');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_purchase_partial_receiving_and_over_receipt_rollback(): void
    {
        $p = Product::first();
        $before = $p->stock->on_hand;
        $a = $this->admin();
        $po = $this->shop->purchase(['supplier_id' => Supplier::first()->id, 'order_date' => date('Y-m-d'), 'items' => [['product_id' => $p->id, 'quantity' => 10, 'unit_cost' => '700.25']], 'discount' => '2.50', 'shipping' => '50.00'], $a);
        $this->assertSame(705000, $po->total_cents);
        $item = $po->items()->first();
        $this->rejected(fn () => $this->shop->receive($po, [$item->id => 1], $a));
        $this->shop->purchaseStatus($po, 'approve', $a);
        $this->shop->purchaseStatus($po, 'order', $a);
        $receiptKey = (string) Str::uuid();
        $this->shop->receive($po, [$item->id => 4], $a, $receiptKey);
        $this->shop->receive($po, [$item->id => 4], $a, $receiptKey);
        $this->assertSame('Partially Received', $po->fresh()->status);
        $this->rejected(fn () => $this->shop->receive($po, [$item->id => 7], $a));
        $this->assertSame($before + 4, $p->stock()->first()->on_hand);
        $this->shop->receive($po, [$item->id => 6], $a);
        $this->assertSame('Received', $po->fresh()->status);
        $this->assertSame($before + 10, $p->stock()->first()->on_hand);
        $this->rejected(fn () => $this->shop->receive($po, [$item->id => 1], $a));
    }

    public function test_pickup_order_bill_partial_payments_and_single_stock_deduction(): void
    {
        $p = Product::first();
        $before = $p->stock()->first();
        $onHand = $before->on_hand;
        $reserved = $before->reserved;
        $o = $this->newOrder();
        $s = $this->staff();
        $this->assertSame($onHand, $p->stock()->first()->on_hand);
        $this->assertSame($reserved + 2, $p->stock()->first()->reserved);
        $this->shop->orderStatus($o, 'process', $s);
        $this->shop->orderStatus($o, 'ready', $s);
        $this->rejected(fn () => $this->shop->orderStatus($o, 'complete', $s));
        $b = $this->shop->bill($o, ['discount' => '100.25', 'tax' => '20.50', 'additional' => '10.00'], $s);
        $this->assertSame(372825, $b->total_cents);
        $this->shop->pay($b, ['amount' => '1000.00', 'method' => 'Cash', 'request_key' => (string) Str::uuid()], $s);
        $this->assertSame('Partially Paid', $b->fresh()->status);
        $this->assertSame(272825, $b->fresh()->balance_cents);
        $this->rejected(fn () => $this->shop->bill($o, ['discount' => '1000.00'], $s));
        $this->rejected(fn () => $this->shop->pay($b, ['amount' => '5000.00', 'method' => 'Cash', 'request_key' => (string) Str::uuid()], $s));
        $this->shop->pay($b, ['amount' => '2728.25', 'method' => 'GCash', 'reference' => 'DEMO-PAY-01', 'request_key' => (string) Str::uuid()], $s);
        $this->assertSame('Paid', $b->fresh()->status);
        $this->shop->orderStatus($o, 'complete', $s);
        $this->assertSame('Completed', $o->fresh()->status);
        $this->assertSame($onHand - 2, $p->stock()->first()->on_hand);
        $this->assertSame($reserved, $p->stock()->first()->reserved);
        $this->rejected(fn () => $this->shop->orderStatus($o, 'complete', $s));
        $this->assertSame($onHand - 2, $p->stock()->first()->on_hand);
    }

    public function test_delivery_requires_payment_and_tracking_before_dispatch(): void
    {
        $o = $this->newOrder(1, 'Delivery');
        $s = $this->staff();
        $this->shop->orderStatus($o, 'process', $s);
        $this->shop->orderStatus($o, 'ready', $s);
        $this->assertSame('Ready for Delivery', $o->fresh()->status);
        $this->rejected(fn () => $this->shop->orderStatus($o, 'dispatch', $s));
        $b = $this->shop->bill($o, ['shipping' => '150.00'], $s);
        $this->shop->pay($b, ['amount' => number_format($b->total_cents / 100, 2, '.', ''), 'method' => 'Cash', 'request_key' => (string) Str::uuid()], $s);
        $this->rejected(fn () => $this->shop->orderStatus($o, 'dispatch', $s));
        $this->shop->orderStatus($o, 'dispatch', $s, ['courier' => 'Demo Courier', 'tracking_number' => 'TEST-001']);
        $this->assertSame('Out for Delivery', $o->fresh()->status);
        $this->shop->orderStatus($o, 'complete', $s);
        $this->assertSame('Completed', $o->fresh()->status);
    }

    public function test_cancellation_releases_reservation_and_voids_unpaid_bill(): void
    {
        $p = Product::first();
        $before = $p->stock()->first();
        $reserved = $before->reserved;
        $onHand = $before->on_hand;
        $o = $this->newOrder();
        $s = $this->staff();
        $b = $this->shop->bill($o, [], $s);
        $this->shop->orderStatus($o, 'cancel', $this->customerUser());
        $this->assertSame('Cancelled', $o->fresh()->status);
        $this->assertSame('Void', $b->fresh()->status);
        $this->assertSame(0, $b->fresh()->balance_cents);
        $this->assertSame($reserved, $p->stock()->first()->reserved);
        $this->assertSame($onHand, $p->stock()->first()->on_hand);
        $this->rejected(fn () => $this->shop->orderStatus($o, 'cancel', $s));
    }

    public function test_paid_order_cannot_cancel_until_admin_voids_payment(): void
    {
        $o = $this->newOrder();
        $s = $this->staff();
        $b = $this->shop->bill($o, [], $s);
        $p = $this->shop->pay($b, ['amount' => '500.00', 'method' => 'Cash', 'request_key' => (string) Str::uuid()], $s);
        $this->rejected(fn () => $this->shop->orderStatus($o, 'cancel', $s));
        $this->rejected(fn () => $this->shop->void($p, 'Wrong demo transaction', $s));
        $this->shop->void($p, 'Wrong demo transaction', $this->admin());
        $this->assertSame('Voided', $p->fresh()->status);
        $this->assertSame('Unpaid', $b->fresh()->status);
        $this->shop->orderStatus($o, 'cancel', $s);
        $this->assertSame('Cancelled', $o->fresh()->status);
    }

    public function test_overselling_rolls_back_all_reservations(): void
    {
        $u = $this->customerUser();
        $p = Product::first();
        $reserved = $p->stock->reserved;
        $other = Product::orderByDesc('id')->first();
        $this->rejected(fn () => $this->shop->order(['items' => [['product_id' => $p->id, 'quantity' => 1], ['product_id' => $other->id, 'quantity' => 999]], 'fulfillment' => 'Pickup', 'request_key' => (string) Str::uuid()], $u, $u->customer));
        $this->assertSame($reserved, $p->stock()->first()->reserved);
    }

    public function test_repeat_order_and_payment_submissions_are_idempotent(): void
    {
        $u = $this->customerUser();
        $s = $this->staff();
        $d = ['items' => [['product_id' => Product::first()->id, 'quantity' => 1]], 'fulfillment' => 'Pickup', 'request_key' => (string) Str::uuid()];
        $o = $this->shop->order($d, $u, $u->customer);
        $again = $this->shop->order($d, $u, $u->customer);
        $this->assertSame($o->id, $again->id);
        $b = $this->shop->bill($o, [], $s);
        $data = ['amount' => number_format($b->total_cents / 100, 2, '.', ''), 'method' => 'Cash', 'request_key' => (string) Str::uuid()];
        $p = $this->shop->pay($b, $data, $s);
        $again = $this->shop->pay($b, $data, $s);
        $this->assertSame($p->id, $again->id);
        $this->assertSame(1, $b->payments()->count());
    }

    public function test_stock_adjustment_cannot_consume_reserved_quantity(): void
    {
        $p = Product::first();
        $this->newOrder(2);
        $before = $p->stock()->first()->on_hand;
        $this->rejected(fn () => $this->shop->adjust($p, -$before, 'Demo correction', $this->admin()));
        $this->assertSame($before, $p->stock()->first()->on_hand);
        $this->rejected(fn () => $this->shop->adjust($p, 2, 'Demo correction', $this->staff()));
    }

    public function test_role_access_and_customer_record_ownership(): void
    {
        $this->get('/manage')->assertRedirect('/login');
        $this->actingAs($this->staff());
        foreach (['/manage/purchases', '/manage/reports', '/manage/settings', '/manage/records/users', '/manage/records/suppliers', '/manage/records/products/create'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post('/manage/purchases', ['supplier_id' => 1])->assertForbidden();
        $this->actingAs($this->customerUser());
        $this->get('/manage')->assertForbidden();
        $other = CustomerOrder::where('type', 'Walk-in')->first();
        $this->get('/my-orders/'.$other->id)->assertForbidden();
        $this->get('/invoices/'.$other->billing->id)->assertForbidden();
        $this->get('/receipts/'.$other->billing->payments->first()->id)->assertForbidden();
        $this->post('/my-orders/'.$other->id.'/cancel')->assertSessionHasErrors();
    }

    public function test_all_management_screens_render_and_exports_work(): void
    {
        $this->actingAs($this->admin());
        $order = CustomerOrder::where('status', 'Processing')->first();
        $po = PurchaseOrder::first();
        $bill = Billing::first();
        $payment = Payment::first();
        $paths = ['/manage', '/manage/stock', '/manage/stock?low=1', '/manage/orders', '/manage/orders/create', '/manage/orders/'.$order->id, '/manage/orders/'.$order->id.'/bill', '/manage/purchases', '/manage/purchases/create', '/manage/purchases/'.$po->id, '/manage/billing', '/manage/billing/'.$bill->id, '/manage/payments', '/manage/settings', '/manage/reports', '/invoices/'.$bill->id, '/receipts/'.$payment->id];
        foreach (['customers', 'products', 'suppliers', 'users'] as $resource) {
            $paths[] = '/manage/records/'.$resource;
            $paths[] = '/manage/records/'.$resource.'/create';
            $paths[] = '/manage/records/'.$resource.'/1/edit';
        }
        foreach ($paths as $path) {
            $this->get($path)->assertOk();
        }
        foreach (['orders', 'payments', 'inventory', 'purchases'] as $type) {
            $this->get('/manage/reports?export='.$type)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        }
    }

    public function test_customer_cart_checkout_and_profile_work_over_http_routes(): void
    {
        $this->get('/')->assertOk();
        $this->get('/eyewear?search=Round')->assertOk()->assertSee('Round');
        $this->get('/eyewear/1')->assertOk();
        $this->post('/cart/1', ['quantity' => 2])->assertRedirect('/cart');
        $this->get('/cart')->assertOk()->assertSee('The Everyday Round');
        $this->actingAs($this->customerUser());
        $this->get('/checkout')->assertOk();
        $key = session('checkout_draft.request_key');
        $this->post('/checkout', ['fulfillment' => 'Delivery', 'delivery_address' => 'Demo Manila address', 'request_key' => $key])->assertRedirect();
        $o = CustomerOrder::where('request_key', $key)->firstOrFail();
        $this->assertSame(15000, $o->shipping_cents);
        $this->get('/my-orders/'.$o->id)->assertOk();
        $this->get('/account')->assertOk();
        $this->put('/account', ['name' => 'Updated Demo Customer', 'phone' => '09170000001', 'address' => 'Manila'])->assertRedirect();
        $this->assertSame('Updated Demo Customer', $this->customerUser()->customer->name);
    }

    public function test_registration_login_inactive_account_and_input_safety(): void
    {
        $this->post('/register', ['name' => 'New Demo', 'email' => 'new@example.test', 'phone' => '09170000000', 'password' => 'NewDemo123!', 'password_confirmation' => 'NewDemo123!'])->assertRedirect('/account');
        $u = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('Customer', $u->role);
        $this->assertNotNull($u->customer);
        $this->post('/logout')->assertRedirect('/');
        $this->post('/login', ['email' => $u->email, 'password' => 'incorrect'])->assertSessionHasErrors();
        $u->update(['active' => false]);
        $this->post('/login', ['email' => $u->email, 'password' => 'NewDemo123!'])->assertSessionHasErrors();
        $this->actingAs($this->admin());
        $this->post('/manage/records/products', ['name' => '<script>alert(1)</script>', 'code' => 'TEST', 'category' => 'Eyeglass Frame', 'cost' => '1.00', 'price' => '2.00', 'reorder_level' => 2, 'active' => 1])->assertRedirect();
        $this->get('/manage/records/products?search=script')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->put('/manage/records/users/'.$this->admin()->id, ['name' => 'Admin', 'email' => 'admin@oneoptics.test', 'role' => 'Staff', 'active' => 1])->assertSessionHasErrors('role');
    }

    public function test_money_calculations_are_exact_and_reject_excess_precision(): void
    {
        $this->assertSame(101, Money::cents('1.01'));
        $this->assertSame(10, Money::cents('0.1'));
        $this->assertSame(0, Money::cents(null));
        $this->rejected(fn () => Money::cents('1.001'));
        $this->rejected(fn () => Money::cents('-1.00'));
    }
}
