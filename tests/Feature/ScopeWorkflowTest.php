<?php

namespace Tests\Feature;

use App\Models\CustomerOrder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScopeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_walk_in_billing_and_manual_payment_work_without_external_services(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Notification::fake();
        $this->seed();
        $this->post('/register', ['name' => 'Scope Audit Customer', 'email' => 'scope@example.test', 'phone' => '09123456789', 'password' => 'ScopeTest123!', 'password_confirmation' => 'ScopeTest123!'])->assertRedirect(route('account'));
        $customerUser = User::where('email', 'scope@example.test')->firstOrFail();
        $customer = $customerUser->customer;
        $this->assertNotNull($customer);
        $staff = User::where('role', 'Staff')->firstOrFail();
        $this->actingAs($staff)->get(route('records', 'customers'))->assertOk()->assertSee('Scope Audit Customer');
        $this->post(route('orders.store'), ['customer_id' => $customer->id, 'fulfillment' => 'Pickup', 'request_key' => (string) Str::uuid(), 'items' => [['product_id' => Product::first()->id, 'quantity' => 1]]])->assertRedirect();
        $order = CustomerOrder::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('Walk-in', $order->type);
        $this->assertNull($order->billing);
        $this->post(route('orders.status', $order), ['action' => 'process'])->assertSessionHasNoErrors();
        $this->post(route('billing.store', $order), [])->assertSessionHasNoErrors();
        $bill = $order->billing()->firstOrFail();
        $this->assertSame('Unpaid', $bill->status);
        $this->post(route('payments.store', $bill), ['amount' => number_format($bill->total_cents / 100, 2, '.', ''), 'method' => 'GCash', 'reference' => 'MANUALLY-CHECKED-001', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('Paid', $bill->fresh()->status);
        $this->assertSame(0, $bill->fresh()->balance_cents);
        $this->actingAs($customerUser)->get(route('my.order', $order))->assertOk()->assertSee($order->number)->assertSee('Our team is preparing your order.');
        $this->get(route('invoice', $bill))->assertOk()->assertSee($bill->number);
        $payment = $bill->payments()->firstOrFail();
        $this->get(route('receipt', $payment))->assertOk()->assertSee($payment->number);
        $this->post(route('payments.store', $bill), [])->assertForbidden();
        $admin = User::where('role', 'Admin')->firstOrFail();
        $this->actingAs($admin)->get(route('reports'))->assertOk()->assertSee('Order breakdown');
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }
}
