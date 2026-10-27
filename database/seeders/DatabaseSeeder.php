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
        if (config('reply-desk.store') !== 'memory') {
            throw new DeskException('The demo seed only runs on the memory store.');
        }

        User::query()->where('email', 'mike@automica.io')->delete();

        $administrator = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'password' => 'seed-PShhT4DYcUyMfzO5',
            ],
        );
        $administrator->forceFill(['password_changed_at' => null])->save();

        if (DB::table('emails')->count() === 0) {
            $this->call(DemoSeeder::class);
        }
    }
}
