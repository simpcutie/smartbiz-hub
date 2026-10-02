<?php

namespace Tests\Feature;

use App\Models\CustomerOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sees_current_step_and_cancelled_orders_have_no_active_progress(): void
    {
        $this->seed();
        $user = User::where('role', 'Customer')->firstOrFail();
        $order = CustomerOrder::where('customer_id', $user->customer->id)->firstOrFail();
        $this->actingAs($user);
        foreach (['Pickup' => ['Pending', 'Processing', 'Ready for Pickup', 'Completed'], 'Delivery' => ['Pending', 'Processing', 'Ready for Delivery', 'Out for Delivery', 'Completed']] as $fulfillment => $statuses) {
            foreach ($statuses as $status) {
                $order->update(compact('fulfillment', 'status'));
                $response = $this->get(route('my.order', $order))->assertOk()->assertSee($order->number)->assertSee('aria-current="step"', false);
                $this->assertSame(1, substr_count($response->getContent(), 'aria-current="step"'));
                if ($fulfillment === 'Delivery') {
                    $response->assertSee('Ready for delivery')->assertSee('Out for delivery');
                }
            }
        }
        $order->update(['status' => 'Cancelled']);
        $this->get(route('my.order', $order))->assertOk()->assertSee('This order has been cancelled.')->assertDontSee('aria-current="step"', false);

    }
}
