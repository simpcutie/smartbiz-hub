<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockManagement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ShopService;
use App\Support\ClinicSettings;
use App\Support\Money;
use App\Support\TransactionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManagementController extends Controller
{
    public function dashboard()
    {
        return view('manage.dashboard', [
            'weeklyCollections' => collect(range(6, 0))->map(fn ($offset) => ['day' => today()->subDays($offset)->format('D'), 'date' => today()->subDays($offset)->toDateString(), 'amount' => (int) Payment::where('status', 'Recorded')->whereDate('created_at', today()->subDays($offset))->sum('amount_cents')]),
            'orderCount' => CustomerOrder::whereNotIn('status', ['Completed', 'Cancelled'])->count(),
            'collections' => (int) Payment::where('status', 'Recorded')->sum('amount_cents'),
            'receivable' => (int) Billing::where('status', '!=', 'Void')->sum('balance_cents'),
            'lowStock' => StockManagement::join('products', 'products.id', '=', 'stock_management.product_id')->where('products.active', true)->whereRaw('(stock_management.on_hand - stock_management.reserved) <= products.reorder_level')->count(),
            'recent' => CustomerOrder::with('customer', 'billing')->latest('id')->take(6)->get(),
            'recentPayments' => Payment::with('billing.order.customer')->latest('id')->take(4)->get(),
            'orderStages' => CustomerOrder::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'purchases' => PurchaseOrder::with('supplier')->whereIn('status', ['Pending', 'Ordered', 'Partially Received'])->latest('id')->take(4)->get(),
        ]);
    }

    private function resource(string $resource): array
    {
        return match ($resource) {
            'customers' => ['model' => Customer::class, 'title' => 'Customers', 'singular' => 'Customer', 'fields' => ['name' => 'Name', 'email' => 'Email', 'phone' => 'Contact number', 'address' => 'Address', 'birth_date' => 'Birth date']],
            'products' => ['model' => Product::class, 'title' => 'Products', 'singular' => 'Product', 'fields' => ['code' => 'Product code', 'name' => 'Product name', 'brand' => 'Brand', 'category' => 'Category', 'frame_type' => 'Frame type', 'frame_shape' => 'Frame shape', 'frame_material' => 'Frame material', 'frame_color' => 'Frame color', 'frame_size' => 'Frame size', 'lens_type' => 'Lens type', 'lens_width' => 'Lens width (mm)', 'bridge_width' => 'Bridge width (mm)', 'temple_length' => 'Temple length (mm)', 'cost' => 'Cost price (PHP)', 'price' => 'Selling price (PHP)', 'reorder_level' => 'Reorder level', 'description' => 'Description']],
            'suppliers' => ['model' => Supplier::class, 'title' => 'Suppliers', 'singular' => 'Supplier', 'fields' => ['name' => 'Supplier name', 'contact_person' => 'Contact person', 'email' => 'Email', 'phone' => 'Contact number', 'address' => 'Address']],
            'users' => ['model' => User::class, 'title' => 'User Management', 'singular' => 'User', 'fields' => ['name' => 'Full name', 'email' => 'Email', 'phone' => 'Contact number', 'role' => 'Role', 'password' => 'Password']],
            default => abort(404),
        };
    }

    private function access(Request $r, string $resource, bool $write = false): void
    {
        if (in_array($resource, ['suppliers', 'users']) || ($resource === 'products' && $write)) {
            abort_unless($r->user()->role === 'Admin', 403);
        }
    }

    public function records(Request $r, string $resource)
    {
        $this->access($r, $resource);
        $spec = $this->resource($resource);
        $q = $spec['model']::query();
        if ($resource === 'products') {
            $q->with('stock');
        }
        if ($resource === 'users') {
            $q->whereIn('role', ['Admin', 'Staff']);
        }
        if ($r->query('search')) {
            $q->where('name', 'like', '%'.substr($r->query('search'), 0, 100).'%');
        }
        if (in_array($r->query('active'), ['0', '1'], true)) {
            $q->where('active', $r->query('active'));
        }

        return view('manage.records', ['spec' => $spec, 'resource' => $resource, 'records' => $q->latest('id')->paginate(12)->withQueryString()]);
    }

    public function form(Request $r, string $resource, ?int $id = null)
    {
        $this->access($r, $resource, true);
        $spec = $this->resource($resource);
        $record = $id ? $spec['model']::findOrFail($id) : new $spec['model'];
        if ($resource === 'users' && $id) {
            abort_unless(in_array($record->role, ['Admin', 'Staff']), 404);
        }

        return view('manage.form', compact('spec', 'resource', 'record'));
    }

    public function save(Request $r, string $resource, ?int $id = null)
    {
        $this->access($r, $resource, true);
        $spec = $this->resource($resource);
        $record = $id ? $spec['model']::findOrFail($id) : new $spec['model'];
        if ($resource === 'users' && $id) {
            abort_unless(in_array($record->role, ['Admin', 'Staff']), 404);
        }
        $rules = ['name' => 'required|string|max:150', 'active' => 'required|boolean'];
        foreach ($spec['fields'] as $field => $label) {
            if (! isset($rules[$field])) {
                $rules[$field] = 'nullable|string|max:255';
            }
        }
        if (isset($rules['email'])) {
            $rules['email'] = 'nullable|email|max:150';
        }
        foreach (['address', 'description'] as $field) {
            if (isset($rules[$field])) {
                $rules[$field] = 'nullable|string|max:2000';
            }
        }
        if ($resource === 'customers') {
            $rules['birth_date'] = 'nullable|date|before_or_equal:today';
        }
        if ($resource === 'products') {
            $rules['code'] = ['required', 'string', 'max:50', Rule::unique('products', 'code')->ignore($id)];
            $rules['category'] = ['required', Rule::in(['Eyeglass Frame', 'Contact Lenses & Solution', 'Reading Glasses', 'Sunglasses', 'Lens', 'Accessory'])];
            $rules['reorder_level'] = 'required|integer|min:0|max:10000';
            $rules['cost'] = $rules['price'] = 'required|numeric|min:0|max:99999999.99';
            $rules['image'] = 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048';
        }
        if ($resource === 'users') {
            $rules['email'] = ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($id)];
            $rules['password'] = ($id ? 'nullable' : 'required').'|string|min:8|max:100';
            $rules['role'] = ['required', Rule::in(['Admin', 'Staff'])];
        }
        $data = $r->validate($rules);
        if ($resource === 'products') {
            $data['cost_cents'] = Money::cents($data['cost']);
            $data['price_cents'] = Money::cents($data['price']);
            unset($data['cost'],$data['price'],$data['image']);
            if ($r->hasFile('image')) {
                $file = $r->file('image');
                $name = Str::uuid().'.'.$file->extension();
                $file->move(public_path('media'), $name);
                $data['image'] = 'media/'.$name;
            }
        }
        if ($resource === 'users' && empty($data['password'])) {
            unset($data['password']);
        }
        DB::transaction(function () use ($r, $resource, $record, $data) {
            if ($resource === 'users') {
                $admins = User::where('role', 'Admin')->where('active', true)->lockForUpdate()->get();
                if ($record->id === $r->user()->id && (! $data['active'] || $data['role'] !== 'Admin')) {
                    throw ValidationException::withMessages(['role' => 'You cannot deactivate or demote your own account.']);
                }
                if ($record->role === 'Admin' && $record->active && (! $data['active'] || $data['role'] !== 'Admin') && $admins->count() <= 1) {
                    throw ValidationException::withMessages(['role' => 'Keep at least one active Admin.']);
                }
            }
            if ($resource === 'customers' && $record->user_id) {
                $data['email'] = $record->user->email;
                $record->user->update(['name' => $data['name'], 'phone' => $data['phone'] ?? null, 'active' => $data['active']]);
            }
            $record->fill($data)->save();
            if ($resource === 'products') {
                StockManagement::firstOrCreate(['product_id' => $record->id]);
            }
            TransactionLog::record(ucfirst($resource).' saved', (string) $record->name);
        });

        return redirect()->route('records', $resource)->with('success', $spec['singular'].' saved successfully.');
    }

    public function stock(Request $r)
    {
        $q = Product::with('stock.updatedBy');
        if ($r->query('search')) {
            $q->where('name', 'like', '%'.substr($r->query('search'), 0, 100).'%');
        }
        if ($r->query('low')) {
            $q->whereHas('stock', fn ($s) => $s->whereRaw('on_hand - reserved <= products.reorder_level'));
        }

        return view('manage.stock', ['products' => $q->orderBy('name')->paginate(12)->withQueryString()]);
    }

    public function adjust(Request $r, Product $product, ShopService $shop)
    {
        $d = $r->validate(['quantity' => 'required|integer|between:-10000,10000', 'reason' => 'required|string|min:5|max:500']);
        $shop->adjust($product, $d['quantity'], $d['reason'], $r->user());

        return back()->with('success', 'Stock adjustment recorded.');
    }

    public function settings()
    {
        return view('manage.settings', ['settings' => ClinicSettings::all()]);
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['shop_name' => 'required|string|max:150', 'address' => 'required|string|max:500', 'phone' => 'nullable|string|max:50', 'email' => 'nullable|email|max:150', 'delivery_fee' => 'required|numeric|min:0|max:10000', 'receipt_note' => 'nullable|string|max:500']);
        Money::cents($d['delivery_fee']);
        ClinicSettings::save($d);
        TransactionLog::record('Settings updated', 'Clinic');

        return back()->with('success', 'Clinic settings saved.');
    }

    public function reports(Request $r)
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'export' => ['nullable', Rule::in(['orders', 'payments', 'inventory', 'purchases'])]]);
        $from = $d['from'] ?? now()->startOfMonth()->toDateString();
        $to = $d['to'] ?? today()->toDateString();
        $orders = CustomerOrder::with('customer')->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->get();
        $payments = Payment::with('billing.order.customer')->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->get();
        $purchases = PurchaseOrder::with('supplier')->whereDate('order_date', '>=', $from)->whereDate('order_date', '<=', $to)->get();
        $products = Product::with('stock')->get();
        if ($type = $d['export'] ?? null) {
            $rows = match ($type) {
                'orders' => $orders->map(fn ($o) => [$o->number, $o->customer->name, $o->created_at->toDateString(), $o->status, number_format($o->total_cents / 100, 2, '.', '')]),
                'payments' => $payments->map(fn ($p) => [$p->number, $p->billing->number, $p->created_at->toDateString(), $p->method, $p->status, number_format($p->amount_cents / 100, 2, '.', '')]),
                'purchases' => $purchases->map(fn ($p) => [$p->number, $p->supplier->name, $p->order_date, $p->status, number_format($p->total_cents / 100, 2, '.', '')]),
                'inventory' => $products->map(fn ($p) => [$p->code, $p->name, $p->stock?->on_hand ?? 0, $p->stock?->reserved ?? 0, $p->available, $p->reorder_level]),
            };
            $headers = match ($type) {
                'orders' => ['Order', 'Customer', 'Date', 'Status', 'Total PHP'],'payments' => ['Receipt', 'Bill', 'Date', 'Method', 'Status', 'Amount PHP'],'purchases' => ['PO', 'Supplier', 'Date', 'Status', 'Total PHP'],'inventory' => ['Code', 'Product', 'On hand', 'Reserved', 'Available', 'Reorder level']
            };

            return response()->streamDownload(function () use ($headers, $rows) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $headers);
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($v) => preg_match('/^[=+@\-\t\r]/', (string) $v) ? "'".$v : $v, $row));
                } fclose($out);
            }, $type.'-'.$from.'-'.$to.'.csv', ['Content-Type' => 'text/csv']);
        }

        return view('manage.reports', compact('orders', 'payments', 'purchases', 'products', 'from', 'to'));
    }
}
