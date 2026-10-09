<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AssignTransactionCategory;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\GetTransaction;
use App\Mcp\Tools\GetYearlyPaymentPlan;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListTransactions;
use App\Mcp\Tools\SetTransactionCategorySplit;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Money Assistant')]
#[Version('2.0.0')]
class FinancialDataServer extends Server
{
    protected array $tools = [ListTransactions::class, GetTransaction::class, ListCategories::class, GetYearlyPaymentPlan::class, CreateTransaction::class, CreateCategory::class, AssignTransactionCategory::class, SetTransactionCategorySplit::class];

    protected array $capabilities = ['tools' => ['listChanged' => false]];

    protected function boot(): void
    {
        $this->instructions = 'Read and write the authenticated owner financial data using the same agent token for every tool. Explicit, unambiguous requests save immediately. The agent interprets intent and clarifies ambiguous amounts, records, Categories, Income Source, Transfer Purpose, and Transfer direction. Resolve exact record and Category IDs through the read tools. Use clearly matching existing Categories; ask when multiple matches remain. Create a missing Category only with explicit authorization. A request to create a Category and use it authorizes both calls. Do not invent an Uncategorized remainder for a split. Before replacing an existing Receipt Breakdown, explain that its item descriptions, quantities, and unit prices will be discarded and establish explicit replacement intent. Before conversion to Income or Transfer, explain removal of Categories and Receipt Breakdowns and establish the owner intent. Never edit related Refunds to bypass a blocked conversion. After an uncertain creation result, verify with reads before retrying. Every request needs its bearer token; no session is required. Keep currencies separate. Amounts are exact minor-unit decimal strings. Movement Direction and Transaction Kind are independent. Category allocations replace the direct Category contribution when a Receipt Breakdown exists. Transaction pages contain at most 50 records. Reuse the same filters with each cursor. On the last page, save checkpoint for the next inclusive updated_since scan and deduplicate by ID and updated_at. Use void_state=all for synchronization, so voided records remain visible. The yearly-payment plan is separate from confirmed Transactions. Its combined PEN estimate uses only the owner manual planning exchange rate; targets are not saved money, paid bills, or available funds. Limit: 120 requests per minute per token. Reporting timezone for relative dates: '.config('app.reporting_timezone').'.';
    }
}
