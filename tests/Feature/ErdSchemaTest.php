<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ErdSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_contains_only_the_eleven_erd_business_entities(): void
    {
        $entities = ['users', 'suppliers', 'purchase_orders', 'purchase_order_items', 'products', 'stock_management', 'customers', 'customer_orders', 'order_items', 'billings', 'payments'];
        $tables = array_values(array_diff(Schema::getTableListing(null, false), ['migrations', 'sqlite_sequence']));
        $this->assertCount(11, $tables);
        $this->assertEqualsCanonicalizing($entities, $tables);
        $this->assertCount(11, glob(app_path('Models/*.php')));
        $this->assertTrue(Schema::hasColumns('stock_management', ['quantity_in', 'quantity_out', 'on_hand', 'last_stock_in', 'last_stock_out', 'updated_by', 'remarks']));
    }
}
