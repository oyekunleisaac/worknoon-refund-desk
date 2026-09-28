<?php

use App\Models\Customer;
use App\Models\RefundRequest;
use Database\Seeders\CrmSeeder;
use Illuminate\Support\Facades\Artisan;

Artisan::command('demo:reset {--force : Skip confirmation}', function () {
    if (! $this->option('force') && ! $this->confirm('Delete all refund requests and restore the mock CRM?')) {
        return;
    }

    RefundRequest::query()->delete();   // audit events cascade
    $this->call('db:seed', ['--class' => CrmSeeder::class, '--force' => true]);
    $this->info('Demo data restored.');
})->purpose('Restore mock CRM data and clear refund requests');

Artisan::command('demo:seed-if-empty', function () {
    if (Customer::query()->exists()) {
        $this->info('Mock CRM already seeded.');

        return;
    }

    $this->call('db:seed', ['--class' => CrmSeeder::class, '--force' => true]);
})->purpose('Seed the mock CRM on first boot only');
