import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    Archive,
    Check,
    History,
    PencilLine,
    RotateCcw,
    SearchCheck,
    Sparkles,
    WandSparkles,
    X,
} from 'lucide-react';
import { store as confirmHistoricalApplication } from '@/actions/App/Http/Controllers/LearnedRuleBulkActionConfirmationController';
import { destroy as undoHistoricalApplication } from '@/actions/App/Http/Controllers/LearnedRuleBulkActionController';
import {
    store as createRule,
    update as reviseRule,
} from '@/actions/App/Http/Controllers/LearnedRuleController';
import { store as previewHistoricalApplication } from '@/actions/App/Http/Controllers/LearnedRuleHistoricalApplicationController';
import { store as previewRule } from '@/actions/App/Http/Controllers/LearnedRulePreviewController';
import {
    destroy as reactivateRule,
    store as retireRule,
} from '@/actions/App/Http/Controllers/LearnedRuleRetirementController';
import { destroy as dismissSuggestion } from '@/actions/App/Http/Controllers/LearnedRuleSuggestionController';
import { store as previewSuggestion } from '@/actions/App/Http/Controllers/LearnedRuleSuggestionPreviewController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/learned_rules';
import type {
    LearnedRule,
    LearnedRuleBulkAction,
    LearnedRuleChangePreview,
    LearnedRuleDefinition,
    LearnedRuleHistoricalApplicationPreview,
    LearnedRuleSuggestion,
    CategoryOption,
} from '@/types';

function modeLabel(mode: LearnedRuleDefinition['match_mode']): string {
    if (mode === 'starts_with') {
        return 'starts with';
    }

    return mode;
}

function RuleDefinition({ definition }: { definition: LearnedRuleDefinition }) {
    const scopes = [
        definition.transaction_kind,
        definition.currency,
        definition.payment_instrument_label,
        definition.payment_instrument_last_four
            ? `ending ${definition.payment_instrument_last_four}`
            : null,
    ].filter((scope): scope is string => scope !== null);

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="outline">
                    {modeLabel(definition.match_mode)}
                </Badge>
                <span className="font-mono text-sm font-medium">
                    {definition.merchant_pattern}
                </span>
                <span className="text-sm text-muted-foreground">→</span>
                <span className="text-sm font-medium">
                    {definition.category_name}
                </span>
            </div>
            <p className="text-xs text-muted-foreground">
                Deterministic key: {definition.merchant_key}
            </p>
            <div className="flex flex-wrap gap-2">
                {scopes.length === 0 ? (
                    <Badge variant="secondary">All Transactions</Badge>
                ) : (
                    scopes.map((scope) => (
                        <Badge
                            key={scope}
                            variant="secondary"
                            className="capitalize"
                        >
                            {scope}
                        </Badge>
                    ))
                )}
            </div>
        </div>
    );
}

function RuleDefinitionFields({
    categories,
    rule,
}: {
    categories: CategoryOption[];
    rule?: LearnedRule;
}) {
    return (
        <div className="grid gap-4 md:grid-cols-2">
            {rule && (
                <>
                    <input
                        type="hidden"
                        name="learned_rule_id"
                        value={rule.id}
                    />
                    <input
                        type="hidden"
                        name="expected_revision"
                        value={rule.revision}
                    />
                </>
            )}
            <div className="grid gap-2">
                <Label htmlFor={`rule-${rule?.id ?? 'new'}-category`}>
                    Category
                </Label>
                <NativeSelect
                    id={`rule-${rule?.id ?? 'new'}-category`}
                    name="category_id"
                    defaultValue={rule?.category_id.toString() ?? ''}
                    options={[
                        { value: '', label: 'Choose a Category' },
                        ...categories.map((category) => ({
                            value: category.id.toString(),
                            label: category.path,
                        })),
                    ]}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`rule-${rule?.id ?? 'new'}-merchant`}>
                    Merchant pattern
                </Label>
                <Input
                    id={`rule-${rule?.id ?? 'new'}-merchant`}
                    name="merchant_pattern"
                    defaultValue={rule?.merchant_pattern}
                    placeholder="Market Lima"
                    required
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`rule-${rule?.id ?? 'new'}-mode`}>
                    Match mode
                </Label>
                <NativeSelect
                    id={`rule-${rule?.id ?? 'new'}-mode`}
                    name="match_mode"
                    defaultValue={rule?.match_mode ?? 'exact'}
                    options={[
                        { value: 'exact', label: 'Exact' },
                        { value: 'starts_with', label: 'Starts with' },
                        { value: 'contains', label: 'Contains' },
                    ]}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`rule-${rule?.id ?? 'new'}-kind`}>
                    Transaction kind
                </Label>
                <NativeSelect
                    id={`rule-${rule?.id ?? 'new'}-kind`}
                    name="transaction_kind"
                    defaultValue={rule?.transaction_kind ?? ''}
                    options={[
                        { value: '', label: 'Any kind' },
                        { value: 'purchase', label: 'Purchase' },
                        { value: 'refund', label: 'Refund' },
                    ]}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`rule-${rule?.id ?? 'new'}-currency`}>
                    Currency
                </Label>
                <NativeSelect
                    id={`rule-${rule?.id ?? 'new'}-currency`}
                    name="currency"
                    defaultValue={rule?.currency ?? ''}
                    options={[
                        { value: '', label: 'Any currency' },
                        { value: 'USD', label: 'USD' },
                        { value: 'PEN', label: 'PEN' },
                    ]}
                />
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor={`rule-${rule?.id ?? 'new'}-instrument`}>
                        Instrument label
                    </Label>
                    <Input
                        id={`rule-${rule?.id ?? 'new'}-instrument`}
                        name="payment_instrument_label"
                        defaultValue={rule?.payment_instrument_label ?? ''}
                        placeholder="Visa"
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`rule-${rule?.id ?? 'new'}-last-four`}>
                        Last four
                    </Label>
                    <Input
                        id={`rule-${rule?.id ?? 'new'}-last-four`}
                        name="payment_instrument_last_four"
                        defaultValue={rule?.payment_instrument_last_four ?? ''}
                        inputMode="numeric"
                        pattern="[0-9]{4}"
                        maxLength={4}
                        placeholder="1234"
                    />
                </div>
            </div>
        </div>
    );
}

function ChangePreview({ preview }: { preview: LearnedRuleChangePreview }) {
    const confirmationRoute = preview.learned_rule_id
        ? reviseRule.form(preview.learned_rule_id)
        : createRule.form();

    return (
        <Card className="border-primary/40 bg-primary/5">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <SearchCheck className="size-4" /> Rule change preview
                </CardTitle>
                <CardDescription>
                    This preview is finite and expires after 30 minutes.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
                <RuleDefinition definition={preview.definition} />
                <div className="grid gap-2 rounded-lg border bg-background p-4 text-sm sm:grid-cols-2 lg:grid-cols-5">
                    <p>
                        <strong>{preview.existing_match_count}</strong> existing
                        matches
                    </p>
                    <p>
                        <strong>{preview.new_match_count}</strong> newly covered
                    </p>
                    <p>
                        <strong>{preview.lost_match_count}</strong> no longer
                        covered
                    </p>
                    <p>
                        Wins over{' '}
                        <strong>{preview.future_behavior.wins_over}</strong>{' '}
                        overlaps
                    </p>
                    <p>
                        Loses to{' '}
                        <strong>{preview.future_behavior.loses_to}</strong>{' '}
                        overlaps
                    </p>
                </div>
                {preview.overlaps.length > 0 && (
                    <div className="grid gap-2">
                        <p className="text-sm font-medium">Overlapping rules</p>
                        {preview.overlaps.map((overlap) => (
                            <div
                                key={overlap.rule_id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-md border bg-background p-3 text-sm"
                            >
                                <span>
                                    Rule #{overlap.rule_id}, revision{' '}
                                    {overlap.revision}:{' '}
                                    {overlap.merchant_pattern} →{' '}
                                    {overlap.category_name}
                                </span>
                                <Badge
                                    variant={
                                        overlap.precedence === 'equal_conflict'
                                            ? 'destructive'
                                            : 'secondary'
                                    }
                                >
                                    {overlap.precedence.replaceAll('_', ' ')}
                                </Badge>
                            </div>
                        ))}
                    </div>
                )}
                {preview.existing_matches.length > 0 && (
                    <details className="rounded-lg border bg-background p-4">
                        <summary className="cursor-pointer text-sm font-medium">
                            Inspect {preview.existing_match_count} existing
                            matches
                        </summary>
                        <div className="mt-3 grid max-h-52 gap-2 overflow-y-auto">
                            {preview.existing_matches.map((transaction) => (
                                <p key={transaction.id} className="text-sm">
                                    #{transaction.id}{' '}
                                    {transaction.merchant_description} ·{' '}
                                    {transaction.category_name ??
                                        'Uncategorized'}
                                </p>
                            ))}
                        </div>
                    </details>
                )}
                {preview.lost_matches.length > 0 && (
                    <details className="rounded-lg border border-amber-300 bg-amber-50/70 p-4 dark:border-amber-800 dark:bg-amber-950/20">
                        <summary className="cursor-pointer text-sm font-medium">
                            Inspect {preview.lost_match_count} Transactions no
                            longer covered
                        </summary>
                        <div className="mt-3 grid max-h-52 gap-2 overflow-y-auto">
                            {preview.lost_matches.map((transaction) => (
                                <p key={transaction.id} className="text-sm">
                                    #{transaction.id}{' '}
                                    {transaction.merchant_description} ·{' '}
                                    {transaction.category_name ??
                                        'Uncategorized'}
                                </p>
                            ))}
                        </div>
                    </details>
                )}
                {preview.blocked ? (
                    <p className="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
                        Confirmation is blocked because an equally specific rule
                        assigns a different Category.
                    </p>
                ) : (
                    <Form
                        {...confirmationRoute}
                        options={{ preserveScroll: true }}
                    >
                        {({ errors, processing }) => (
                            <div className="grid w-fit gap-1">
                                <input
                                    type="hidden"
                                    name="preview_id"
                                    value={preview.id}
                                />
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : <Check />}
                                    Confirm rule change
                                </Button>
                                <InputError message={errors.preview_id} />
                            </div>
                        )}
                    </Form>
                )}
            </CardContent>
        </Card>
    );
}

function HistoricalPreview({
    preview,
}: {
    preview: LearnedRuleHistoricalApplicationPreview;
}) {
    return (
        <Card className="border-amber-400/60 bg-amber-50/70 dark:border-amber-700 dark:bg-amber-950/20">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <History className="size-4" /> Historical application
                    preview
                </CardTitle>
                <CardDescription>
                    Rule #{preview.rule_id}, revision {preview.rule_revision}{' '}
                    will create {preview.transaction_count} authoritative{' '}
                    {preview.transaction_count === 1
                        ? 'Correction'
                        : 'Corrections'}
                    .
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
                <div className="grid max-h-64 gap-2 overflow-y-auto rounded-lg border bg-background p-3">
                    {preview.items.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No existing Transactions would change.
                        </p>
                    ) : (
                        preview.items.map((item) => (
                            <p key={item.transaction_id} className="text-sm">
                                #{item.transaction_id}{' '}
                                {item.merchant_description} ·{' '}
                                {item.previous_category_name ?? 'Uncategorized'}
                            </p>
                        ))
                    )}
                </div>
                <Form
                    {...confirmHistoricalApplication.form(preview.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <div className="grid w-fit gap-1">
                            <Button type="submit" disabled={processing}>
                                {processing ? <Spinner /> : <Check />}
                                Confirm {preview.transaction_count} changes
                            </Button>
                            <InputError
                                message={errors.historical_application}
                            />
                        </div>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

type PaginationState = {
    current_page: number;
    last_page: number;
    previous_page: number | null;
    next_page: number | null;
};

type LearnedRulePagination = {
    rules: PaginationState;
    suggestions: PaginationState;
    bulk_actions: PaginationState;
};

function PaginationControls({
    section,
    pagination,
}: {
    section: keyof LearnedRulePagination;
    pagination: LearnedRulePagination;
}) {
    const state = pagination[section];

    if (state.last_page <= 1) {
        return null;
    }

    const routeForPage = (page: number) =>
        index({
            query: {
                rules_page:
                    section === 'rules' ? page : pagination.rules.current_page,
                suggestions_page:
                    section === 'suggestions'
                        ? page
                        : pagination.suggestions.current_page,
                bulk_actions_page:
                    section === 'bulk_actions'
                        ? page
                        : pagination.bulk_actions.current_page,
            },
        });

    return (
        <div className="flex items-center justify-between gap-3 border-t pt-4">
            <p className="text-xs text-muted-foreground">
                Page {state.current_page} of {state.last_page}
            </p>
            <div className="flex gap-2">
                {state.previous_page !== null && (
                    <Button asChild size="sm" variant="outline">
                        <Link
                            href={routeForPage(state.previous_page)}
                            preserveScroll
                        >
                            Previous
                        </Link>
                    </Button>
                )}
                {state.next_page !== null && (
                    <Button asChild size="sm" variant="outline">
                        <Link
                            href={routeForPage(state.next_page)}
                            preserveScroll
                        >
                            Next
                        </Link>
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function LearnedRulesIndex({
    rules,
    suggestions,
    category_options,
    bulk_actions,
    pagination,
}: {
    rules: LearnedRule[];
    suggestions: LearnedRuleSuggestion[];
    category_options: CategoryOption[];
    bulk_actions: LearnedRuleBulkAction[];
    pagination: LearnedRulePagination;
}) {
    const { flash } = usePage();
    const changePreview = flash.rule_change_preview as
        LearnedRuleChangePreview | undefined;
    const historicalPreview = flash.historical_application_preview as
        LearnedRuleHistoricalApplicationPreview | undefined;

    return (
        <>
            <Head title="Learned Rules" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <div className="flex items-center gap-2">
                        <WandSparkles className="size-5 text-muted-foreground" />
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Learned Rules
                        </h1>
                    </div>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Visible merchant rules derived from your Corrections.
                        Nothing is trained or activated without your
                        confirmation.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Create a visible rule</CardTitle>
                        <CardDescription>
                            Define only deterministic merchant matching and
                            preview existing matches, overlaps, and precedence
                            before activation.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...previewRule.form()}
                            options={{ preserveScroll: true }}
                            className="grid gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <RuleDefinitionFields
                                        categories={category_options}
                                    />
                                    <InputError
                                        message={
                                            errors.category_id ??
                                            errors.merchant_pattern ??
                                            errors.match_mode ??
                                            errors.payment_instrument_last_four
                                        }
                                    />
                                    <Button
                                        type="submit"
                                        className="w-fit"
                                        disabled={processing}
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <SearchCheck />
                                        )}
                                        Preview new rule
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                {changePreview && <ChangePreview preview={changePreview} />}
                {historicalPreview && (
                    <HistoricalPreview preview={historicalPreview} />
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Sparkles className="size-4" /> Suggestions
                        </CardTitle>
                        <CardDescription>
                            A suggestion appears only after two separate
                            Corrections agree on the same exact pattern, scope,
                            and Category.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        {suggestions.length === 0 ? (
                            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                No Learned Rule suggestions have met the
                                threshold.
                            </p>
                        ) : (
                            suggestions.map((suggestion) => (
                                <div
                                    key={suggestion.id}
                                    className="grid gap-4 rounded-lg border p-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center"
                                >
                                    <div className="grid gap-2">
                                        <RuleDefinition
                                            definition={suggestion}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Supported by{' '}
                                            {suggestion.evidence_count} separate
                                            Corrections.
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2 lg:justify-end">
                                        <Form
                                            {...previewSuggestion.form(
                                                suggestion.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ errors, processing }) => (
                                                <div className="grid gap-1">
                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        {processing ? (
                                                            <Spinner />
                                                        ) : (
                                                            <Check />
                                                        )}
                                                        Preview suggested rule
                                                    </Button>
                                                    <InputError
                                                        message={
                                                            errors.suggestion
                                                        }
                                                    />
                                                </div>
                                            )}
                                        </Form>
                                        <Form
                                            {...dismissSuggestion.form(
                                                suggestion.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ errors, processing }) => (
                                                <div className="grid gap-1">
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        disabled={processing}
                                                    >
                                                        {processing ? (
                                                            <Spinner />
                                                        ) : (
                                                            <X />
                                                        )}
                                                        Dismiss
                                                    </Button>
                                                    <InputError
                                                        message={
                                                            errors.suggestion
                                                        }
                                                    />
                                                </div>
                                            )}
                                        </Form>
                                    </div>
                                </div>
                            ))
                        )}
                        <PaginationControls
                            section="suggestions"
                            pagination={pagination}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Rule lifecycle</CardTitle>
                        <CardDescription>
                            Revision, retirement, and reactivation affect future
                            matching only. Historical changes always use a
                            separate finite preview.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        {rules.length === 0 ? (
                            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                No Learned Rules have been created.
                            </p>
                        ) : (
                            rules.map((rule) => (
                                <div
                                    key={rule.id}
                                    className="grid gap-3 rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <Badge
                                            variant={
                                                rule.retired_at
                                                    ? 'secondary'
                                                    : 'default'
                                            }
                                        >
                                            {rule.retired_at
                                                ? 'Retired'
                                                : 'Active'}
                                        </Badge>
                                        <span className="text-xs text-muted-foreground">
                                            Rule #{rule.id} · Revision{' '}
                                            {rule.revision}
                                        </span>
                                    </div>
                                    <RuleDefinition definition={rule} />
                                    <div className="flex flex-wrap gap-2">
                                        {rule.retired_at ? (
                                            <Form
                                                {...reactivateRule.form(
                                                    rule.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ errors, processing }) => (
                                                    <div className="grid gap-1">
                                                        <input
                                                            type="hidden"
                                                            name="expected_revision"
                                                            value={
                                                                rule.revision
                                                            }
                                                        />
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            {processing ? (
                                                                <Spinner />
                                                            ) : (
                                                                <RotateCcw />
                                                            )}
                                                            Reactivate
                                                        </Button>
                                                        <InputError
                                                            message={
                                                                errors.learned_rule ??
                                                                errors.expected_revision
                                                            }
                                                        />
                                                    </div>
                                                )}
                                            </Form>
                                        ) : (
                                            <>
                                                <Form
                                                    {...retireRule.form(
                                                        rule.id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({
                                                        errors,
                                                        processing,
                                                    }) => (
                                                        <div className="grid gap-1">
                                                            <input
                                                                type="hidden"
                                                                name="expected_revision"
                                                                value={
                                                                    rule.revision
                                                                }
                                                            />
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                {processing ? (
                                                                    <Spinner />
                                                                ) : (
                                                                    <Archive />
                                                                )}
                                                                Retire
                                                            </Button>
                                                            <InputError
                                                                message={
                                                                    errors.expected_revision
                                                                }
                                                            />
                                                        </div>
                                                    )}
                                                </Form>
                                                <Form
                                                    {...previewHistoricalApplication.form(
                                                        rule.id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({
                                                        errors,
                                                        processing,
                                                    }) => (
                                                        <div className="grid gap-1">
                                                            <input
                                                                type="hidden"
                                                                name="expected_revision"
                                                                value={
                                                                    rule.revision
                                                                }
                                                            />
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                {processing ? (
                                                                    <Spinner />
                                                                ) : (
                                                                    <History />
                                                                )}
                                                                Preview
                                                                historical
                                                                application
                                                            </Button>
                                                            <InputError
                                                                message={
                                                                    errors.expected_revision
                                                                }
                                                            />
                                                        </div>
                                                    )}
                                                </Form>
                                            </>
                                        )}
                                    </div>
                                    {!rule.retired_at && (
                                        <details className="rounded-lg border p-3">
                                            <summary className="flex cursor-pointer items-center gap-2 text-sm font-medium">
                                                <PencilLine className="size-4" />
                                                Revise rule
                                            </summary>
                                            <Form
                                                {...previewRule.form()}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="mt-4 grid gap-4"
                                            >
                                                {({ errors, processing }) => (
                                                    <>
                                                        <RuleDefinitionFields
                                                            categories={
                                                                category_options
                                                            }
                                                            rule={rule}
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.category_id ??
                                                                errors.merchant_pattern ??
                                                                errors.expected_revision
                                                            }
                                                        />
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            className="w-fit"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            {processing ? (
                                                                <Spinner />
                                                            ) : (
                                                                <SearchCheck />
                                                            )}
                                                            Preview revision
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        </details>
                                    )}
                                </div>
                            ))
                        )}
                        <PaginationControls
                            section="rules"
                            pagination={pagination}
                        />
                    </CardContent>
                </Card>

                {bulk_actions.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Historical action history</CardTitle>
                            <CardDescription>
                                Group undo restores only Transactions that have
                                not changed since application.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {bulk_actions.map((action) => (
                                <div
                                    key={action.id}
                                    className="flex flex-col justify-between gap-3 rounded-lg border p-4 sm:flex-row sm:items-center"
                                >
                                    <div className="grid gap-1 text-sm">
                                        <p className="font-medium">
                                            Rule #{action.rule_id}, revision{' '}
                                            {action.rule_revision} ·{' '}
                                            {action.transaction_count}{' '}
                                            Transactions
                                        </p>
                                        <p className="text-muted-foreground capitalize">
                                            {action.status}
                                            {action.status === 'undone' &&
                                                ` · ${action.restored_count} restored · ${action.skipped_count} skipped`}
                                        </p>
                                    </div>
                                    {action.status === 'applied' && (
                                        <Form
                                            {...undoHistoricalApplication.form(
                                                action.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ errors, processing }) => (
                                                <div className="grid gap-1">
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={processing}
                                                    >
                                                        {processing ? (
                                                            <Spinner />
                                                        ) : (
                                                            <RotateCcw />
                                                        )}
                                                        Undo group
                                                    </Button>
                                                    <InputError
                                                        message={
                                                            errors.historical_application
                                                        }
                                                    />
                                                </div>
                                            )}
                                        </Form>
                                    )}
                                </div>
                            ))}
                            <PaginationControls
                                section="bulk_actions"
                                pagination={pagination}
                            />
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

LearnedRulesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Learned Rules',
            href: index(),
        },
    ],
};
