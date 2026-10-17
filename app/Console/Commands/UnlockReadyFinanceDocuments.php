<?php

namespace App\Console\Commands;

use App\Services\FinanceDocumentWorkflowService;
use Illuminate\Console\Command;

class UnlockReadyFinanceDocuments extends Command
{
    protected $signature = 'honorarium:unlock-finance-documents';
    protected $description = 'Unlock panel breakdown documents due today and notify Administrative Assistants.';

    public function handle(FinanceDocumentWorkflowService $workflow): int
    {
        $count = $workflow->unlockDueDocuments();
        $this->info("Unlocked {$count} document(s).");
        return self::SUCCESS;
    }
}
