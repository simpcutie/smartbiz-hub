<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\CustomerOrder;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\ShopService;
use App\Support\ClinicSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreController extends Controller
{
    public function home()
    {
        return view('store.home', ['products' => Product::with('stock')->where('active', true)->latest('id')->take(4)->get()]);
    }

    public function catalog(Request $r)
    {
        $q = Product::with('stock')->where('active', true);
        if ($search = $r->query('search')) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.substr($search, 0, 100).'%')->orWhere('brand', 'like', '%'.substr($search, 0, 100).'%')->orWhere('code', 'like', '%'.substr($search, 0, 100).'%'));
        }
        foreach (['category', 'frame_shape', 'frame_type'] as $field) {
            if ($r->query($field)) {
                $q->where($field, $r->query($field));
            }
        }
        $q = match ($r->query('sort')) {
            'price_low' => $q->orderBy('price_cents'),'price_high' => $q->orderByDesc('price_cents'),default => $q->latest('id')
        };

        return view('store.catalog', ['products' => $q->paginate(12)->withQueryString()]);
    }

    public function product(Product $product)
    {
        abort_unless($product->active, 404);

        return view('store.product', compact('product'));
    }

    public function info(string $page)
    {
        abort_unless(in_array($page, ['about', 'contact']), 404);

        return view('store.info', compact('page'));
    }

    public function login()
    {
        return view('auth.login');
    }

    public function authenticate(Request $r)
    {
        $data = $r->validate(['email' => 'required|string|max:150', 'password' => 'required|string']);
        $login = strtolower(trim($data['email']));
        // Short usernames identify only the two designated demonstration accounts.
        $account = match ($login) {
            'admin' => ['email' => 'admin@oneoptics.test', 'role' => 'Admin'],
            'staff' => ['email' => 'staff@oneoptics.test', 'role' => 'Staff'],
            default => ['email' => $login],
        };
        if (! Auth::attempt([...$account, 'password' => $data['password'], 'active' => true])) {
            return back()->withErrors(['email' => 'The username, email, or password is incorrect, or the account is inactive.'])->onlyInput('email');
        }
        $r->session()->regenerate();

        return Auth::user()->role === 'Customer' ? redirect()->intended(route('account')) : redirect()->route('dashboard');
    }

    public function register()
    {
        return view('auth.register');
    }

    public function signup(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:150|unique:users', 'phone' => 'required|string|max:30', 'address' => 'nullable|string|max:500', 'password' => 'required|confirmed|min:8|max:100']);
        // Save the login and customer profile together so registration also appears in Customers.
        $user = DB::transaction(function () use ($data) {
            $user = User::create([...collect($data)->only(['name', 'email', 'phone', 'password'])->all(), 'role' => 'Customer']);
            $user->customer()->create(collect($data)->only(['name', 'email', 'phone', 'address'])->all());

            return $user;
        });
        Auth::login($user);
        $r->session()->regenerate();

        return redirect()->intended(route('account'))->with('success', 'Welcome to One Optics Clinic. Your account is ready.');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function selectedCart(Request $r): array
    {
        $cart = $r->session()->get('cart', []);
        $ids = $r->session()->get('cart_selected', array_keys($cart));
        $selected = array_intersect_key($cart, array_flip($ids));
        ksort($selected);

        return $selected;
    }

    private function saveSelection(Request $r, array $cart): array
    {
        $data = $r->validate(['selected' => 'nullable|array|max:50', 'selected.*' => 'required|integer|distinct']);
        $ids = array_map('intval', $data['selected'] ?? []);
        if (array_diff($ids, array_keys($cart))) {
            throw ValidationException::withMessages(['selected' => 'Choose only items that are already in your cart.']);
        }
        $r->session()->put('cart_selected', $ids);
        $r->session()->forget('checkout_draft');

        return $ids;
    }

    public function cart(Request $r)
    {
        $cart = $r->session()->get('cart', []);
        $products = Product::with('stock')->whereIn('id', array_keys($cart))->get();
        $cart = array_intersect_key($cart, array_flip($products->modelKeys()));
        $r->session()->put('cart', $cart);
        $eligible = $products->filter(fn ($p) => $p->active && $cart[$p->id] <= $p->available)->modelKeys();
        $selected = array_values(array_intersect(array_keys($this->selectedCart($r)), $eligible));
        $r->session()->put('cart_selected', $selected);
        $subtotal = $products->whereIn('id', $selected)->sum(fn ($p) => $p->price_cents * $cart[$p->id]);
        $selectedQuantity = array_sum(array_intersect_key($cart, array_flip($selected)));

        return view('store.cart', compact('cart', 'products', 'selected', 'subtotal', 'selectedQuantity'));
    }

    public function addCart(Request $r, Product $product)
    {
        $data = $r->validate(['quantity' => 'required|integer|min:1|max:10000']);
        $cart = $r->session()->get('cart', []);
        $selected = array_keys($this->selectedCart($r));
        $quantity = ($cart[$product->id] ?? 0) + (int) $data['quantity'];
        if (! $product->active || $quantity > $product->available) {
            return back()->withErrors(['quantity' => 'This quantity is not available.']);
        }
        if (! isset($cart[$product->id]) && count($cart) >= 50) {
            return back()->withErrors(['cart' => 'Your cart can contain up to 50 different products.']);
        }
        $cart[$product->id] = $quantity;
        $r->session()->put('cart', $cart);
        $r->session()->put('cart_selected', array_values(array_unique([...$selected, $product->id])));
        $r->session()->forget('checkout_draft');

        return redirect()->route('cart')->with('success', 'Eyewear added to your cart.');
    }

    public function updateCart(Request $r)
    {
        $data = $r->validate(['intent' => ['nullable', Rule::in(['save', 'checkout', 'remove'])]]);
        $cart = $r->session()->get('cart', []);
        $ids = $this->saveSelection($r, $cart);
        $intent = $data['intent'] ?? 'save';
        if ($intent !== 'save' && ! $ids) {
            return redirect()->route('cart')->withErrors(['selected' => 'Select at least one item first.']);
        }
        if ($intent === 'remove') {
            $r->session()->put('cart', array_diff_key($cart, array_flip($ids)));
            $r->session()->put('cart_selected', []);

            return redirect()->route('cart')->with('success', 'Selected items removed from your cart.');
        }
        if ($intent === 'checkout') {
            return redirect()->route('checkout');
        }

        return redirect()->route('cart')->with('success', 'Your selection has been saved.');
    }

    public function editCartItem(Request $r, Product $product)
    {
        $data = $r->validate(['quantity' => 'required|integer|min:1|max:10000']);
        $cart = $r->session()->get('cart', []);
        abort_unless(isset($cart[$product->id]), 404);
        if (! $product->active || (int) $data['quantity'] > $product->available) {
            return redirect()->route('cart')->withErrors(['quantity' => 'Requested quantity is not available for '.$product->name.'.']);
        }
        if ($r->has('selection_present')) {
            $this->saveSelection($r, $cart);
        }
        $cart[$product->id] = (int) $data['quantity'];
        $r->session()->put('cart', $cart);
        $r->session()->forget('checkout_draft');

        return redirect()->route('cart')->with('success', 'Quantity updated for '.$product->name.'.');
    }

    public function removeCartItem(Request $r, Product $product)
    {
        $cart = $r->session()->get('cart', []);
        if ($r->has('selection_present')) {
            $this->saveSelection($r, $cart);
        }
        $selected = array_keys($this->selectedCart($r));
        unset($cart[$product->id]);
        $r->session()->put('cart', $cart);
        $r->session()->put('cart_selected', array_values(array_diff($selected, [$product->id])));
        $r->session()->forget('checkout_draft');

        return redirect()->route('cart')->with('success', 'Item removed from your cart.');
    }

    public function checkout(Request $r)
    {
        $cart = $this->selectedCart($r);
        if (! $cart) {
            return redirect()->route('cart')->withErrors(['selected' => 'Select at least one item to check out.']);
        }
        $products = Product::with('stock')->whereIn('id', array_keys($cart))->get();
        if ($products->count() !== count($cart) || $products->contains(fn ($p) => ! $p->active || $cart[$p->id] > $p->available)) {
            return redirect()->route('cart')->withErrors(['cart' => 'Availability has changed. Review your selected items before checkout.']);
        }
        $token = Str::uuid()->toString();
        $r->session()->put('checkout_draft', ['request_key' => $token, 'cart' => $cart, 'prices' => $products->pluck('price_cents', 'id')->all()]);

        return view('store.checkout', ['products' => $products, 'cart' => $cart, 'customer' => $r->user()->customer, 'token' => $token, 'deliveryFee' => ClinicSettings::valueFor('delivery_fee', '150.00')]);
    }

    public function place(Request $r, ShopService $shop)
    {
        $data = $r->validate(['fulfillment' => ['required', Rule::in(['Pickup', 'Delivery'])], 'delivery_address' => 'required_if:fulfillment,Delivery|nullable|string|max:1000', 'pickup_date' => 'nullable|date|after_or_equal:today', 'notes' => 'nullable|string|max:1000', 'request_key' => 'required|uuid']);
        $existing = CustomerOrder::where('request_key', $data['request_key'])->where('customer_id', $r->user()->customer->id)->first();
        if ($existing) {
            return redirect()->route('my.order', $existing);
        }
        $draft = $r->session()->get('checkout_draft');
        $selected = $this->selectedCart($r);
        if (! $draft || $draft['request_key'] !== $data['request_key'] || $draft['cart'] !== $selected) {
            return redirect()->route('cart')->withErrors(['cart' => 'Your cart changed or this checkout expired. Review your selected items again.']);
        }
        $prices = Product::whereIn('id', array_keys($selected))->pluck('price_cents', 'id')->all();
        if ($prices !== $draft['prices']) {
            return redirect()->route('cart')->withErrors(['cart' => 'A product price changed. Please review the updated prices before checkout.']);
        }
        $data['items'] = collect($selected)->map(fn ($qty, $id) => ['product_id' => $id, 'quantity' => $qty])->values()->all();
        $data['shipping'] = ClinicSettings::valueFor('delivery_fee', '150.00');
        $order = $shop->order($data, $r->user(), $r->user()->customer);
        $remaining = array_diff_key($r->session()->get('cart', []), $selected);
        $r->session()->put('cart', $remaining);
        $r->session()->put('cart_selected', []);
        $r->session()->forget('checkout_draft');

        return redirect()->route('my.order', $order)->with('success', 'Order '.$order->number.' received. You can follow its progress in My Account.');
    }

    public function account(Request $r)
    {
        return view('store.account', ['customer' => $r->user()->customer, 'orders' => $r->user()->customer->orders()->with('billing')->latest('id')->paginate(10)]);
    }

    public function profile(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:150', 'phone' => 'required|string|max:30', 'address' => 'nullable|string|max:500', 'birth_date' => 'nullable|date_format:Y-m-d|after_or_equal:1900-01-01|before_or_equal:today', 'current_password' => 'required_with:password|nullable|current_password', 'password' => 'nullable|confirmed|min:8|max:100']);
        DB::transaction(function () use ($r, $data) {
            $r->user()->update(collect($data)->only(['name', 'phone'])->all());
            $r->user()->customer->update(collect($data)->only(['name', 'phone', 'address', 'birth_date'])->all());
            if (! empty($data['password'])) {
                $r->user()->update(['password' => $data['password']]);
            }
        });

        return back()->with('success', 'Your profile has been updated.');
    }

    public function myOrder(Request $r, CustomerOrder $order)
    {
        abort_unless($order->customer->user_id === $r->user()->id, 403);

        return view('orders.show', ['order' => $order->load('items.product', 'billing.payments', 'customer', 'user'), 'public' => true]);
    }

    public function cancel(Request $r, CustomerOrder $order, ShopService $shop)
    {
        $shop->orderStatus($order, 'cancel', $r->user());

        return back()->with('success', 'Order cancelled and reservation released.');
    }

    public function invoice(Request $r, Billing $billing)
    {
        if ($r->user()->role === 'Customer') {
            abort_unless($billing->order->customer->user_id === $r->user()->id, 403);
        }

        return view('billing.invoice', ['billing' => $billing->load('order.items', 'order.customer', 'payments')]);
    }

    public function receipt(Request $r, Payment $payment)
    {
        if ($r->user()->role === 'Customer') {
            abort_unless($payment->billing->order->customer->user_id === $r->user()->id, 403);
        }

        return view('billing.receipt', ['payment' => $payment->load('billing.order.customer', 'user')]);
    }
}
