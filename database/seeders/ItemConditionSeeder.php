<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ItemConditionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $data = [
            ['name' => '新品、未使用'],
            ['name' => '未使用に近い'],
            ['name' => '目立った傷や汚れなし'],
            ['name' => 'やや傷や汚れあり'],
            ['name' => '傷や汚れあり'],
            ['name' => '全体的に状態が悪い']
        ];
        DB::table('item_conditions')->insert($data);
        /**仮で入れているので変更してください */
    }
}
