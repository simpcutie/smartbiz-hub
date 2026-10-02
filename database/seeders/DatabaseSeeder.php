<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockManagement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ShopService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (User::exists()) {
            $this->command?->warn('Database already contains users; demo seed skipped to preserve data.');

            return;
        }
        DB::transaction(function () {
            $admin = User::create(['name' => 'Clinic Administrator', 'email' => 'admin@oneoptics.test', 'password' => 'DemoAdmin123!', 'role' => 'Admin']);
            $staff = User::create(['name' => 'Jamie Santos', 'email' => 'staff@oneoptics.test', 'password' => 'DemoStaff123!', 'role' => 'Staff']);
            $user = User::create(['name' => 'Alex Reyes', 'email' => 'customer@oneoptics.test', 'password' => 'DemoCustomer123!', 'role' => 'Customer', 'phone' => '09170000001']);
            $customer = $user->customer()->create(['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'address' => 'Demo address, Malate, Manila']);
            $walkin = Customer::create(['name' => 'Morgan Cruz (Demo)', 'phone' => '09170000002', 'address' => 'Demo address, Manila']);
            $supplier = Supplier::create(['name' => 'Metro Frames Supply (Demo)', 'contact_person' => 'Demo Supplier Contact', 'phone' => '09000000000', 'email' => 'supplier@example.test', 'address' => 'Sample supplier address, Manila']);
            Supplier::create(['name' => 'Clearline Optical Supply (Demo)', 'contact_person' => 'Demo Contact', 'email' => 'clearline@example.test']);
            $names = ['The Everyday Round', 'Metro Rectangle', 'Audrey Cat Eye', 'Weekend Aviator', 'Classic Square', 'Studio Round'];
            $shapes = ['Round', 'Rectangle', 'Cat Eye', 'Aviator', 'Square', 'Round'];
            $colors = ['Tortoiseshell', 'Forest Green', 'Rose Brown', 'Gold', 'Midnight Blue', 'Warm Brown'];
            $prices = [189900, 219900, 249900, 279900, 169900, 199900];
            $products = [];
            foreach ($names as $i => $name) {
                $p = Product::create(['code' => 'OPT-'.str_pad($i + 1, 3, '0', STR_PAD_LEFT), 'name' => $name, 'brand' => 'ONE OPTICS', 'category' => $i === 3 ? 'Sunglasses' : 'Eyeglass Frame', 'frame_type' => 'Full Rim', 'frame_shape' => $shapes[$i], 'frame_material' => $i === 3 ? 'Metal' : 'Acetate', 'frame_color' => $colors[$i], 'frame_size' => 'Medium', 'lens_width' => '50', 'bridge_width' => '18', 'temple_length' => '140', 'description' => 'A considered frame for a clearer everyday. Comfortable proportions and a distinctive finish make this pair easy to wear from morning to evening. Product and pricing shown are demonstration data.', 'cost_cents' => 70000 + $i * 10000, 'price_cents' => $prices[$i], 'reorder_level' => 5, 'image' => 'assets/frames-'.($i + 1).'.svg']);
                StockManagement::create(['product_id' => $p->id]);
                $products[] = $p;
            }
            $shop = app(ShopService::class);
            $po = $shop->purchase(['supplier_id' => $supplier->id, 'order_date' => today()->toDateString(), 'expected_date' => today()->addDays(3)->toDateString(), 'items' => array_map(fn ($p) => ['product_id' => $p->id, 'quantity' => $p->id === $products[5]->id ? 4 : 20, 'unit_cost' => number_format($p->cost_cents / 100, 2, '.', '')], $products), 'remarks' => 'Opening inventory for the prototype demonstration.'], $admin);
            $shop->purchaseStatus($po, 'approve', $admin);
            $shop->purchaseStatus($po, 'order', $admin);
            $shop->receive($po, $po->items()->get()->mapWithKeys(fn ($i) => [$i->id => $i->quantity])->all(), $admin);
            $shop->purchase(['supplier_id' => $supplier->id, 'order_date' => today()->toDateString(), 'expected_date' => today()->addDays(7)->toDateString(), 'items' => [['product_id' => $products[5]->id, 'quantity' => 15, 'unit_cost' => '1200.00']], 'remarks' => 'Demo replenishment request waiting for approval.'], $admin);
            foreach ([0, 1, 2, 3, 4] as $i) {
                $o = $shop->order(['items' => [['product_id' => $products[$i]->id, 'quantity' => 1]], 'fulfillment' => $i === 3 ? 'Delivery' : 'Pickup', 'delivery_address' => 'Demo delivery address, Manila', 'shipping' => '150.00', 'request_key' => Str::uuid()->toString(), 'notes' => 'Sample order for prototype demonstration.'], $i === 4 ? $staff : $user, $i === 4 ? $walkin : $customer);
                if ($i === 2) {
                    continue;
                }
                $shop->orderStatus($o, 'process', $staff);
                $b = $shop->bill($o, ['shipping' => $i === 3 ? '150.00' : '0.00', 'due_date' => today()->addDays(7)->toDateString()], $staff);
                if ($i === 4) {
                    $shop->pay($b, ['amount' => '500.00', 'method' => 'Cash', 'request_key' => Str::uuid()->toString(), 'remarks' => 'Demo partial payment'], $staff);

                    continue;
                }
                $shop->pay($b, ['amount' => number_format($b->total_cents / 100, 2, '.', ''), 'method' => 'Cash', 'request_key' => Str::uuid()->toString(), 'remarks' => 'Demo payment only'], $staff);
                $shop->orderStatus($o, 'ready', $staff);
                if ($i === 0) {
                    $shop->orderStatus($o, 'complete', $staff);
                }
                if ($i === 3) {
                    $shop->orderStatus($o, 'dispatch', $staff, ['courier' => 'Demo Courier', 'tracking_number' => 'DEMO-DELIVERY-001']);
                }
            }
        });
    }
}
