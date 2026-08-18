<?php

use App\Branch;
use App\Order;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run()
    {
        $branches = [
            ['kdigr' => '44', 'nama' => 'Cabang Bandung'],
            ['kdigr' => '12', 'nama' => 'Cabang Jakarta'],
            ['kdigr' => '77', 'nama' => 'Cabang Surabaya'],
        ];

        foreach ($branches as $data) {
            $branch = Branch::firstOrCreate(['kdigr' => $data['kdigr']], $data);

            for ($i = 1; $i <= 8; $i++) {
                Order::firstOrCreate([
                    'branch_id' => $branch->id,
                    'no_order'  => $data['kdigr'] . '-ORD-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                ], [
                    'total' => rand(50, 500) * 1000,
                ]);
            }
        }
    }
}