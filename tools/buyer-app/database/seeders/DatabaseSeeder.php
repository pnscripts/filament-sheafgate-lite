<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->create(['name' => 'Demo Editor', 'email' => 'editor@demo.test', 'password' => bcrypt(str()->random(40))]);

        $categories = collect([['Home', 'HOM'], ['Kitchen', 'KIT'], ['Outdoor', 'OUT'], ['Stationery', 'STA']])
            ->mapWithKeys(fn (array $c) => [$c[0] => Category::query()->create(['name' => $c[0], 'code' => $c[1]])->id]);

        $products = [
            ['PN-1040', 'Linen Cushion Cover', 'Home', 24.00, 120, '2026-02-01', true, false],
            ['PN-1047', 'Oak Serving Board', 'Kitchen', 39.50, 42, '2026-01-15', true, false],
            ['PN-1054', 'Enamel Camp Mug', 'Outdoor', 14.90, 300, null, false, false],
            ['PN-1061', 'Dot Grid Notebook A5', 'Stationery', 12.00, 510, '2025-11-20', true, false],
            ['PN-1068', 'Stoneware Pasta Bowl', 'Kitchen', 18.00, 96, null, false, false],
            ['PN-1075', 'Wool Picnic Blanket', 'Outdoor', 79.00, 18, null, false, true],
            ['PN-1082', 'Brass Desk Lamp', 'Home', 129.00, 11, '2025-10-05', true, false],
        ];

        foreach ($products as [$sku, $name, $category, $price, $stock, $launch, $active, $locked]) {
            Product::query()->create([
                'sku' => $sku, 'name' => $name, 'category_id' => $categories[$category], 'price' => $price,
                'stock' => $stock, 'launch_date' => $launch, 'is_active' => $active, 'locked' => $locked,
            ]);
        }
    }
}
