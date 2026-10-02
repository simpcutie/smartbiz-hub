<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('Customer');
            $t->string('phone')->nullable();
            $t->boolean('active')->default(true);
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->text('address')->nullable();
            $t->date('birth_date')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('contact_person')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->text('address')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('brand')->nullable();
            $t->string('category');
            $t->string('frame_type')->nullable();
            $t->string('frame_shape')->nullable();
            $t->string('frame_material')->nullable();
            $t->string('frame_color')->nullable();
            $t->string('frame_size')->nullable();
            $t->string('lens_type')->nullable();
            $t->string('lens_width')->nullable();
            $t->string('bridge_width')->nullable();
            $t->string('temple_length')->nullable();
            $t->text('description')->nullable();
            $t->string('image')->nullable();
            $t->unsignedBigInteger('cost_cents')->default(0);
            $t->unsignedBigInteger('price_cents')->default(0);
            $t->unsignedInteger('reorder_level')->default(5);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('stock_management', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity_in')->default(0);
            $t->unsignedInteger('quantity_out')->default(0);
            $t->unsignedInteger('on_hand')->default(0);
            $t->unsignedInteger('reserved')->default(0);
            $t->timestamp('last_stock_in')->nullable();
            $t->timestamp('last_stock_out')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('remarks')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->nullable()->unique();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('order_date');
            $t->date('expected_date')->nullable();
            $t->date('received_date')->nullable();
            $t->json('receipt_keys')->nullable();
            $t->string('status')->default('Pending');
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('discount_cents')->default(0);
            $t->unsignedBigInteger('shipping_cents')->default(0);
            $t->unsignedBigInteger('total_cents');
            $t->text('remarks')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('received')->default(0);
            $t->unsignedBigInteger('unit_cost_cents');
            $t->unsignedBigInteger('subtotal_cents');
            $t->unique(['purchase_order_id', 'product_id']);
        });
        Schema::create('customer_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->nullable()->unique();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type')->default('Online');
            $t->string('fulfillment')->default('Pickup');
            $t->text('delivery_address')->nullable();
            $t->string('courier')->nullable();
            $t->string('tracking_number')->nullable();
            $t->date('pickup_date')->nullable();
            $t->string('status')->default('Pending');
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('discount_cents')->default(0);
            $t->unsignedBigInteger('shipping_cents')->default(0);
            $t->unsignedBigInteger('total_cents');
            $t->text('notes')->nullable();
            $t->string('request_key')->unique();
            $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('customer_orders')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('product_name');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_cents');
            $t->unsignedBigInteger('subtotal_cents');
            $t->unique(['order_id', 'product_id']);
        });
        Schema::create('billings', function (Blueprint $t) {
            $t->id();
            $t->string('number')->nullable()->unique();
            $t->foreignId('order_id')->unique()->constrained('customer_orders')->restrictOnDelete();
            $t->date('billing_date');
            $t->date('due_date')->nullable();
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('discount_cents')->default(0);
            $t->unsignedBigInteger('tax_cents')->default(0);
            $t->unsignedBigInteger('additional_cents')->default(0);
            $t->unsignedBigInteger('shipping_cents')->default(0);
            $t->unsignedBigInteger('total_cents');
            $t->unsignedBigInteger('paid_cents')->default(0);
            $t->unsignedBigInteger('balance_cents');
            $t->string('status')->default('Unpaid');
            $t->text('remarks')->nullable();
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->string('number')->nullable()->unique();
            $t->foreignId('billing_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_cents');
            $t->string('method');
            $t->string('reference')->nullable();
            $t->string('status')->default('Recorded');
            $t->text('remarks')->nullable();
            $t->text('void_reason')->nullable();
            $t->string('request_key')->unique();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payments', 'billings', 'order_items', 'customer_orders', 'purchase_order_items', 'purchase_orders', 'stock_management', 'products', 'suppliers', 'customers'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'phone', 'active']));
    }
};
