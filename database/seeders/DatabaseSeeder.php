<?php

namespace Database\Seeders;

use App\Models\LandlordUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = config('landlord.default_admin.email');
        $password = config('landlord.default_admin.password');

        if ($email && $password) {
            LandlordUser::updateOrCreate(
                ['email' => $email],
                [
                    'name' => config('landlord.default_admin.name'),
                    'password' => Hash::make($password),
                ]
            );
        }
    }
}
