<?php

namespace Database\Seeders;

use App\Models\Rider;
use Illuminate\Database\Seeder;

/**
 * Local development only: a rider who can sign in to the rider app.
 *
 *   php artisan db:seed --class=RiderAppTestSeeder
 *
 * Phone 01700000099, password riderTest123. Never run this on production.
 */
class RiderAppTestSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Refusing to create a test rider on production.');

            return;
        }
        Rider::updateOrCreate(['phone' => '01700000099'], ['name' => 'Test Rider', 'status' => 'active', 'password' => 'riderTest123']);
    }
}
