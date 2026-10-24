<?php

namespace Database\Seeders;

use App\Desk\DeskException;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (config('reply-desk.store') !== 'memory' || (string) config('reply-desk.portal_id') === '145255358') {
            throw new DeskException('The demo seed only runs on the memory store, and never against portal 145255358.');
        }

        User::query()->where('email', 'mike@automica.io')->delete();

        User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'password' => 'seed-PShhT4DYcUyMfzO5',
            ],
        );

        if (DB::table('fake_notes')->count() === 0) {
            $this->call(DemoSeeder::class);
        }
    }
}
