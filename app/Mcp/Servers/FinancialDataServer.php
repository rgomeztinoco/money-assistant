<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetTransaction;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListTransactions;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Money Assistant')]
#[Version('1.0.0')]
#[Instructions('Read the authenticated owner financial data. Every request needs its bearer token; no session is required. Keep currencies separate. Amounts are exact minor-unit decimal strings. Movement Direction and Transaction Kind are independent. Category allocations replace the direct Category contribution when a Receipt Breakdown exists. Transaction pages contain at most 50 records. Reuse the same filters with each cursor. On the last page, save checkpoint for the next inclusive updated_since scan and deduplicate by ID and updated_at. Use void_state=all for synchronization, so voided records remain visible. Limit: 120 requests per minute per token.')]
class FinancialDataServer extends Server
{
    protected array $tools = [ListTransactions::class, GetTransaction::class, ListCategories::class];

    protected array $capabilities = ['tools' => ['listChanged' => false]];
}
