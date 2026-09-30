<?php

namespace App\Console\Commands;

use App\Services\BillingCollectionService;
use App\Services\ModuleRecurringBillingService;
use Illuminate\Console\Command;

class ProcessModuleBillingCommand extends Command
{
    protected $signature = 'billing:process-module-cycles {--date=}';

    protected $description = 'Create anniversary recurring invoices, charge due invoices on renew day, enforce grace penalties';

    public function handle(
        ModuleRecurringBillingService $recurring,
        BillingCollectionService $collection
    ): int {
        $on = $this->option('date')
            ? \Carbon\Carbon::parse($this->option('date'))->startOfDay()
            : now()->startOfDay();

        $anniversary = $recurring->processAnniversaryInvoices($on);
        $this->info('Anniversary invoices created: '.count($anniversary));

        $flat = $recurring->processFlatRecurringOnRenewDay($on);
        $this->info('Flat recurring invoices created: '.count($flat));

        $charged = $collection->collectDueInvoices($on);
        $this->info(sprintf(
            'Renew-day collection: hubs=%d invoices=%d amount=%.2f',
            $charged['hubs'],
            $charged['invoices_charged'],
            $charged['amount']
        ));
        foreach ($charged['errors'] as $err) {
            $this->warn($err);
        }

        $penalties = $recurring->enforceGracePenalties($on);
        $this->info(sprintf(
            'Grace penalties: suspended_users=%d disabled_modules=%d',
            $penalties['suspended_users'],
            $penalties['disabled_modules']
        ));

        return self::SUCCESS;
    }
}
