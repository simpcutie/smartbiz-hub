<?php

namespace Tests\Feature;

use App\Models\CustomerOrder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingBagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(): User
    {
        return User::where('role', 'Customer')->firstOrFail();
    }

    public function test_selected_checkout_keeps_other_items_and_repeat_submit_is_safe(): void
    {
        [$first, $second] = Product::orderBy('id')->take(2)->get()->all();
        $before = CustomerOrder::count();
        $this->actingAs($this->customer())->withSession(['cart' => [$first->id => 2, $second->id => 1]])
            ->put(route('cart.update'), ['selected' => [$first->id], 'intent' => 'checkout'])->assertRedirect(route('checkout'));
        $this->get(route('checkout'))->assertOk()->assertViewHas('cart', [$first->id => 2]);
        $data = ['request_key' => session('checkout_draft.request_key'), 'fulfillment' => 'Pickup'];
        $this->post(route('checkout'), $data)->assertRedirect()->assertSessionHas('cart', [$second->id => 1]);
        $order = CustomerOrder::latest('id')->first();
        $this->assertSame($before + 1, CustomerOrder::count());
        $this->assertSame([$first->id], $order->items->pluck('product_id')->all());
        $this->assertSame(2, $order->items->first()->quantity);
        $this->post(route('checkout'), $data)->assertRedirect(route('my.order', $order))->assertSessionHas('cart', [$second->id => 1]);
        $this->assertSame($before + 1, CustomerOrder::count());
    }

    public function test_quantity_edit_and_removals_do_not_change_inventory_or_products(): void
    {
        [$first, $second] = Product::orderBy('id')->take(2)->get()->all();
        $stock = $first->stock->getAttributes();
        $count = Product::count();
        $this->withSession(['cart' => [$first->id => 1, $second->id => 2], 'cart_selected' => [$first->id]])
            ->put(route('cart.edit', $first), ['quantity' => 3])->assertSessionHas('cart', [$first->id => 3, $second->id => 2]);
        $this->get(route('cart'))->assertOk()->assertViewHas('subtotal', $first->price_cents * 3);
        foreach ([0, -1, $first->available + 1] as $quantity) {
            $this->put(route('cart.edit', $first), ['quantity' => $quantity])->assertSessionHasErrors('quantity');
        }
        $this->delete(route('cart.remove', $first))->assertSessionHas('cart', [$second->id => 2]);
        $this->put(route('cart.edit', $first), ['quantity' => 1])->assertNotFound();
        $this->put(route('cart.update'), ['selected' => [$second->id], 'intent' => 'remove'])->assertSessionHas('cart', []);
        $this->assertSame($count, Product::count());
        $this->assertSame($stock, $first->stock()->first()->getAttributes());
    }

    public function test_empty_or_forged_selection_cannot_checkout(): void
    {
        [$first, $second] = Product::orderBy('id')->take(2)->get()->all();
        $this->withSession(['cart' => [$first->id => 1]])->put(route('cart.update'), ['selected' => [$second->id], 'intent' => 'checkout'])->assertSessionHasErrors('selected');
        $this->put(route('cart.update'), ['intent' => 'checkout'])->assertRedirect(route('cart'))->assertSessionHasErrors('selected');
        $this->actingAs($this->customer())->get(route('checkout'))->assertRedirect(route('cart'));
    }

    public function test_guest_returns_to_selected_checkout_after_login(): void
    {
        $first = Product::first();
        $this->withSession(['cart' => [$first->id => 1]])->put(route('cart.update'), ['selected' => [$first->id], 'intent' => 'checkout'])->assertRedirect(route('checkout'));
        $this->get(route('checkout'))->assertRedirect(route('login'));
        $this->post(route('login'), ['email' => 'customer@oneoptics.test', 'password' => 'DemoCustomer123!'])->assertRedirect(route('checkout'));
        $this->get(route('checkout'))->assertOk()->assertViewHas('cart', [$first->id => 1]);
    }

    public function test_changed_bag_and_prices_require_new_checkout_review(): void
    {
        $first = Product::first();
        $before = CustomerOrder::count();
        $this->actingAs($this->customer())->withSession(['cart' => [$first->id => 1]])->get(route('checkout'))->assertOk();
        $data = ['request_key' => session('checkout_draft.request_key'), 'fulfillment' => 'Pickup'];
        $this->put(route('cart.edit', $first), ['quantity' => 2]);
        $this->post(route('checkout'), $data)->assertRedirect(route('cart'))->assertSessionHasErrors('cart');
        $this->get(route('checkout'))->assertOk();
        $data['request_key'] = session('checkout_draft.request_key');
        $first->increment('price_cents', 100);
        $this->post(route('checkout'), $data)->assertRedirect(route('cart'))->assertSessionHasErrors('cart');
        $this->assertSame($before, CustomerOrder::count());
    }

    public function test_unavailable_products_cannot_be_selected_or_ordered(): void
    {
        $first = Product::first();
        $this->actingAs($this->customer())->withSession(['cart' => [$first->id => 1]])->get(route('checkout'))->assertOk();
        $data = ['request_key' => session('checkout_draft.request_key'), 'fulfillment' => 'Pickup'];
        $first->stock->update(['reserved' => $first->stock->on_hand]);
        $before = CustomerOrder::count();
        $this->post(route('checkout'), $data)->assertSessionHasErrors();
        $this->assertSame($before, CustomerOrder::count());
        $this->get(route('cart'))->assertOk()->assertViewHas('selected', []);
        $this->withSession(['cart_selected' => [$first->id]])->get(route('checkout'))->assertRedirect(route('cart'))->assertSessionHasErrors('cart');
        $first->update(['active' => false]);
        $this->get(route('cart'))->assertSee('This frame is no longer available.');
    }
}
