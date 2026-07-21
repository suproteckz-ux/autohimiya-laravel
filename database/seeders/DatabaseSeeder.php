<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@autohimiya.kz')],
            [
                'name' => env('ADMIN_NAME', 'Autohimiya Admin'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'is_admin' => true,
            ],
        );

        foreach (\App\Support\SiteSettings::defaults() as $key => $value) {
            SiteSetting::query()->firstOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'type' => 'string',
                    'group' => str($key)->before('.')->toString(),
                    'is_public' => true,
                ],
            );
        }
    }
}
