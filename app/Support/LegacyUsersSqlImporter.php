<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LegacyUsersSqlImporter
{
    public function import(string $path, bool $dryRun = true, bool $updateExisting = false, bool $withDeleted = false): array
    {
        $rows = $this->readRows($path);
        $summary = [
            'total' => count($rows),
            'created' => 0,
            'updated' => 0,
            'skipped_existing' => 0,
            'skipped_deleted' => 0,
            'skipped_invalid' => 0,
        ];

        $processRows = function () use ($rows, $dryRun, $updateExisting, $withDeleted, &$summary) {
            foreach ($rows as $row) {
                if (! $withDeleted && filled($row['deleted_at'] ?? null)) {
                    $summary['skipped_deleted']++;

                    continue;
                }

                if (blank($row['email'] ?? null) || blank($row['name'] ?? null)) {
                    $summary['skipped_invalid']++;

                    continue;
                }

                $existingUser = User::query()->where('email', $row['email'])->first();

                if ($existingUser && ! $updateExisting) {
                    $summary['skipped_existing']++;

                    continue;
                }

                if ($dryRun) {
                    $summary[$existingUser ? 'updated' : 'created']++;

                    continue;
                }

                $attributes = $this->mapRowToUserAttributes($row, $existingUser);
                $user = User::query()->updateOrCreate(
                    ['email' => $row['email']],
                    $attributes,
                );

                if ($user->paid_leave_remaining_days === null) {
                    $user->update([
                        'paid_leave_remaining_days' => $user->calculatedPaidLeaveRemainingDays(),
                    ]);
                }

                $summary[$existingUser ? 'updated' : 'created']++;
            }
        };

        if ($dryRun) {
            $processRows();
        } else {
            DB::transaction($processRows);
        }

        return $summary;
    }

    private function readRows(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("SQL file not found: {$path}");
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("SQL file could not be read: {$path}");
        }

        $rows = [];
        preg_match_all('/INSERT INTO `users` \((.*?)\) VALUES\s*(.*?);/s', $sql, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $columns = array_map(
                fn (string $column) => trim($column, " `\r\n\t"),
                explode(',', $match[1]),
            );

            foreach ($this->splitValues($match[2]) as $valuesSql) {
                $values = array_map(
                    fn (?string $value) => $value === null || strtoupper(trim($value)) === 'NULL' ? null : $value,
                    $this->parseValues($valuesSql),
                );

                if (count($columns) !== count($values)) {
                    continue;
                }

                $rows[] = array_combine($columns, $values);
            }
        }

        return $rows;
    }

    private function splitValues(string $valuesSql): array
    {
        $rows = [];
        $buffer = '';
        $depth = 0;
        $inString = false;
        $length = strlen($valuesSql);

        for ($index = 0; $index < $length; $index++) {
            $char = $valuesSql[$index];
            $previous = $index > 0 ? $valuesSql[$index - 1] : '';

            if ($char === "'" && $previous !== '\\') {
                $inString = ! $inString;
            }

            if (! $inString && $char === '(') {
                $depth++;
            }

            if ($depth > 0) {
                $buffer .= $char;
            }

            if (! $inString && $char === ')') {
                $depth--;

                if ($depth === 0) {
                    $rows[] = $buffer;
                    $buffer = '';
                }
            }
        }

        return $rows;
    }

    private function parseValues(string $valuesSql): array
    {
        $valuesSql = trim($valuesSql, " \r\n\t()");
        $values = [];
        $buffer = '';
        $inString = false;
        $length = strlen($valuesSql);

        for ($index = 0; $index < $length; $index++) {
            $char = $valuesSql[$index];
            $previous = $index > 0 ? $valuesSql[$index - 1] : '';

            if ($char === "'" && $previous !== '\\') {
                $inString = ! $inString;

                continue;
            }

            if (! $inString && $char === ',') {
                $values[] = $this->normalizeSqlValue($buffer);
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $values[] = $this->normalizeSqlValue($buffer);

        return $values;
    }

    private function normalizeSqlValue(string $value): ?string
    {
        $value = trim($value);

        if (strtoupper($value) === 'NULL') {
            return null;
        }

        return stripcslashes($value);
    }

    private function mapRowToUserAttributes(array $row, ?User $existingUser): array
    {
        $clockIn = $this->timeOrDefault($row['start_time'] ?? null, '09:00');
        $clockOut = $this->timeOrDefault($row['end_time'] ?? null, '18:00');
        $breakMinutes = (int) ($row['break_minutes'] ?? 60);
        $workingDays = (int) ($row['working_days'] ?? 63);

        return [
            'name' => $row['name'],
            'role' => (int) ($row['is_admin'] ?? 0) === 1 ? 'admin' : 'user',
            'email_verified_at' => $row['email_verified_at'] ?? null,
            'password' => $row['password'] ?: ($existingUser?->password ?? Str::password(32)),
            'remember_token' => $row['remember_token'] ?? null,
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => $row['updated_at'] ?? now(),
            'default_clock_in' => $clockIn,
            'default_clock_out' => $clockOut,
            'default_break_minutes' => $breakMinutes,
            'workday_settings' => $this->workdaySettings($clockIn, $clockOut, $breakMinutes, $workingDays),
            'hire_date' => $row['hire_date'] ?? null,
            'management_number' => $row['management_number'] ?? null,
            'hourly_wage' => filled($row['hourly_wage'] ?? null) ? (int) $row['hourly_wage'] : null,
            'department' => $row['department'] ?? null,
            'business_category' => $row['department_detail'] ?? null,
            'work_style' => $row['work_type'] ?? null,
            'height_cm' => filled($row['height'] ?? null) ? (float) $row['height'] : null,
            'weight_kg' => filled($row['weight'] ?? null) ? (float) $row['weight'] : null,
            'gender' => $row['gender'] ?? null,
            'commute_limit_days' => $row['max_attendance_days'] ?? null,
            'paid_leave_remaining_days' => null,
        ];
    }

    private function timeOrDefault(?string $value, string $default): string
    {
        if (blank($value)) {
            return $default;
        }

        return substr($value, 0, 5);
    }

    private function workdaySettings(string $clockIn, string $clockOut, int $breakMinutes, int $workingDays): array
    {
        return collect(range(1, 6))
            ->mapWithKeys(fn (int $weekday) => [
                (string) $weekday => [
                    'default_clock_in' => $clockIn,
                    'default_clock_out' => $clockOut,
                    'default_break_minutes' => $breakMinutes,
                    'is_working_day' => ($workingDays & (1 << ($weekday - 1))) !== 0,
                ],
            ])
            ->all();
    }
}
