<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\ReadCategoryTaxonomy;
use App\Actions\Categorization\ReadMerchantRules;
use App\Actions\Categorization\SaveMerchantRule;
use App\Currency;
use App\Http\Requests\DeleteMerchantRuleRequest;
use App\Http\Requests\SaveMerchantRuleRequest;
use App\Models\MerchantRule;
use App\TransactionKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MerchantRuleController extends Controller
{
    public function __construct(
        private ReadMerchantRules $readMerchantRules,
        private ReadCategoryTaxonomy $readCategoryTaxonomy,
        private SaveMerchantRule $saveMerchantRule,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('merchant-rules/index', [
            'rules' => $this->readMerchantRules->handle($request->user()),
            'category_options' => $this->readCategoryTaxonomy->activeOptions($request->user()),
        ]);
    }

    public function store(SaveMerchantRuleRequest $request): RedirectResponse
    {
        $this->save($request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Merchant Rule created.')]);

        return to_route('merchant_rules.index');
    }

    public function update(SaveMerchantRuleRequest $request, MerchantRule $merchantRule): RedirectResponse
    {
        $this->save($request, $merchantRule);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Merchant Rule updated.')]);

        return to_route('merchant_rules.index');
    }

    public function destroy(DeleteMerchantRuleRequest $request, MerchantRule $merchantRule): RedirectResponse
    {
        $merchantRule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Merchant Rule deleted.')]);

        return to_route('merchant_rules.index');
    }

    private function save(SaveMerchantRuleRequest $request, ?MerchantRule $merchantRule = null): MerchantRule
    {
        $validated = $request->validated();

        return $this->saveMerchantRule->handle(
            owner: $request->user(),
            merchant: $validated['merchant'],
            categoryId: (int) $validated['category_id'],
            transactionKind: isset($validated['transaction_kind'])
                ? TransactionKind::from($validated['transaction_kind'])
                : null,
            currency: isset($validated['currency']) ? Currency::from($validated['currency']) : null,
            enabled: (bool) $validated['enabled'],
            merchantRule: $merchantRule,
        );
    }
}
