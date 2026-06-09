<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Support\LegacyUsersSqlImporter;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('users:import-sql {path} {--dry-run : Count rows without saving} {--update-existing : Update users that already exist by email} {--with-deleted : Include rows with deleted_at}', function (LegacyUsersSqlImporter $importer) {
    $summary = $importer->import(
        path: $this->argument('path'),
        dryRun: (bool) $this->option('dry-run'),
        updateExisting: (bool) $this->option('update-existing'),
        withDeleted: (bool) $this->option('with-deleted'),
    );

    $this->table(['Item', 'Count'], collect($summary)->map(fn ($count, $item) => [$item, $count]));

    if ($this->option('dry-run')) {
        $this->info('Dry run only. No users were saved.');
    }
})->purpose('Import legacy users from a phpMyAdmin users.sql dump');
