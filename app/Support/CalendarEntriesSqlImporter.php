<?php

namespace App\Support;

use App\Models\CalendarEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CalendarEntriesSqlImporter
{
    public function import(string $path): int
    {
        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException("Could not read SQL file: {$path}");
        }

        if (! preg_match('/INSERT INTO `calendar_entries` .*? VALUES\s*(.*?);/s', $sql, $match)) {
            throw new RuntimeException('calendar_entries INSERT block was not found.');
        }

        $pattern = "/\\((\\d+),\\s*'([^']+)',\\s*(\\d+),\\s*'([^']+)',\\s*(NULL|'((?:[^']|'')*)'),\\s*'([^']*)',\\s*'([^']*)'\\)/";
        preg_match_all($pattern, $match[1], $rows, PREG_SET_ORDER);

        DB::transaction(function () use ($rows): void {
            CalendarEntry::query()->delete();

            foreach ($rows as $row) {
                CalendarEntry::query()->create([
                    'id' => (int) $row[1],
                    'date' => $row[2],
                    'processed' => (bool) $row[3],
                    'type' => $row[4],
                    'description' => $row[5] === 'NULL' ? null : str_replace("''", "'", $row[6]),
                    'created_at' => $row[7] ?: now(),
                    'updated_at' => $row[8] ?: now(),
                ]);
            }
        });

        return count($rows);
    }
}
