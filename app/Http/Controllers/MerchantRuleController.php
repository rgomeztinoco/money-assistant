<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\MatchingMerchantTransactions;
use App\Actions\Categorization\ReadCategoryTaxonomy;
use App\Actions\Categorization\ReadMerchantRules;
use App\Actions\Categorization\SaveMerchantRule;
use App\Currency;
use App\Http\Requests\DeleteMerchantRuleRequest;
use App\Http\Requests\IndexMerchantRulesRequest;
use App\Http\Requests\SaveMerchantRuleRequest;
use App\MerchantNormalizer;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\TransactionKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class MerchantRuleController extends Controller
{
    public function __construct(
        private ReadMerchantRules $readMerchantRules,
        private ReadCategoryTaxonomy $readCategoryTaxonomy,
        private SaveMerchantRule $saveMerchantRule,
        private MerchantNormalizer $merchantNormalizer,
        private MatchingMerchantTransactions $matchingMerchantTransactions,
    ) {}

    public function index(IndexMerchantRulesRequest $request): Response
    {
        $filters = [
            'search' => $request->validated('search') ?? '',
            'category_id' => $request->validated('category_id') === null
                ? null
                : (int) $request->validated('category_id'),
            'status' => $request->validated('status') ?? 'all',
            'kind' => $request->validated('kind') ?? 'all',
            'currency' => $request->validated('currency') ?? 'all',
            'sort' => $request->validated('sort') ?? 'category',
            'direction' => $request->validated('direction') ?? 'asc',
        ];

        return Inertia::render('merchant-rules/index', [
            'rules' => $this->readMerchantRules->handle($request->user(), $filters),
            'category_groups' => $this->readMerchantRules->categoryGroups($request->user()),
            'category_options' => $this->readCategoryTaxonomy->activeOptions($request->user()),
            'filters' => $filters,
            'prefill' => $this->prefill($request),
        ]);
    }

    public function store(SaveMerchantRuleRequest $request): RedirectResponse
    {
        $appliedCount = DB::transaction(function () use ($request): int {
            $rule = $this->save($request);

            return $request->boolean('apply_existing') && $rule->enabled
                ? $this->matchingMerchantTransactions->apply($request->user(), $rule)
                : 0;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $appliedCount > 0
            ? trans_choice('{1} Merchant Rule created and 1 Transaction updated.|[2,*] Merchant Rule created and :count Transactions updated.', $appliedCount, ['count' => $appliedCount])
            : __('Merchant Rule created.')]);

        return back(fallback: route('merchant_rules.index'));
    }

    public function matches(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant' => ['required', 'string', 'max:255'],
            'transaction_kind' => ['nullable', 'in:spending,refund'],
            'currency' => ['nullable', 'in:PEN,USD'],
        ]);

        try {
            return response()->json($this->matchingMerchantTransactions->preview(
                $request->user(),
                $validated['merchant'],
                isset($validated['transaction_kind']) ? TransactionKind::from($validated['transaction_kind']) : null,
                isset($validated['currency']) ? Currency::from($validated['currency']) : null,
            ));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['merchant' => $exception->getMessage()]);
        }
    }

    public function update(SaveMerchantRuleRequest $request, MerchantRule $merchantRule): RedirectResponse
    {
        $this->save($request, $merchantRule);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Merchant Rule updated.')]);

        return back(fallback: route('merchant_rules.index'));
    }

    public function destroy(DeleteMerchantRuleRequest $request, MerchantRule $merchantRule): RedirectResponse
    {
        $merchantRule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Merchant Rule deleted.')]);

        return back(fallback: route('merchant_rules.index'));
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

    /** @return array{transaction_id: int, merchant: string, merchant_key: string, transaction_kind: string, currency: string}|null */
    private function prefill(IndexMerchantRulesRequest $request): ?array
    {
        $transactionId = $request->validated('transaction');

        if ($transactionId === null) {
            return null;
        }

        $transaction = Transaction::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->findOrFail((int) $transactionId);
        $merchant = Str::squish($transaction->description);

        return [
            'transaction_id' => $transaction->id,
            'merchant' => $merchant,
            'merchant_key' => $this->merchantNormalizer->normalize($merchant),
            'transaction_kind' => $transaction->kind->value,
            'currency' => $transaction->currency->value,
        ];
    }
}
