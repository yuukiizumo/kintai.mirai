<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'default_clock_in' => '09:00',
            'default_clock_out' => '18:00',
            'default_break_minutes' => 60,
            'password' => Hash::make('password'),
        ]);

        $users = collect([
            ['name' => '佐藤 花子', 'email' => 'hanako@example.com'],
            ['name' => '田中 太郎', 'email' => 'taro@example.com'],
            ['name' => '鈴木 美咲', 'email' => 'misaki@example.com'],
        ])->map(fn (array $user) => User::factory()->create([
            ...$user,
            'role' => 'user',
            'default_clock_in' => '09:00',
            'default_clock_out' => '18:00',
            'default_break_minutes' => 60,
            'password' => Hash::make('password'),
        ]));

        $start = CarbonImmutable::now()->startOfMonth();

        $users->each(function (User $user, int $userIndex) use ($start): void {
            foreach (range(0, min(13, now()->day - 1)) as $day) {
                $date = $start->addDays($day);

                if ($date->isWeekend()) {
                    AttendanceRecord::create([
                        'user_id' => $user->id,
                        'work_date' => $date->toDateString(),
                        'status' => 'holiday',
                        'break_minutes' => 0,
                    ]);

                    continue;
                }

                $clockIn = sprintf('09:%02d', ($day + $userIndex * 7) % 20);
                $clockOut = sprintf('18:%02d', (10 + $day * 3 + $userIndex) % 45);

                AttendanceRecord::create([
                    'user_id' => $user->id,
                    'work_date' => $date->toDateString(),
                    'clock_in' => $clockIn,
                    'clock_out' => $date->isToday() ? null : $clockOut,
                    'break_minutes' => 60,
                    'status' => $date->isToday() ? 'working' : 'completed',
                    'note' => $day % 5 === 0 ? '顧客対応のため退勤が少し遅め' : null,
                ]);
            }
        });
    }
}
