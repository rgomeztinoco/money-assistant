import { Form, Head } from '@inertiajs/react';
import { Check, Sparkles, WandSparkles, X } from 'lucide-react';
import { store as acceptSuggestion } from '@/actions/App/Http/Controllers/LearnedRuleSuggestionAcceptanceController';
import { destroy as dismissSuggestion } from '@/actions/App/Http/Controllers/LearnedRuleSuggestionController';
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
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/learned_rules';
import type {
    LearnedRule,
    LearnedRuleDefinition,
    LearnedRuleSuggestion,
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

export default function LearnedRulesIndex({
    rules,
    suggestions,
}: {
    rules: LearnedRule[];
    suggestions: LearnedRuleSuggestion[];
}) {
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
                                            {...acceptSuggestion.form(
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
                                                        Activate rule
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
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Active rules</CardTitle>
                        <CardDescription>
                            Active rules affect future matching only. Existing
                            Transactions are never reclassified automatically.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        {rules.length === 0 ? (
                            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                No Learned Rules are active.
                            </p>
                        ) : (
                            rules.map((rule) => (
                                <div
                                    key={rule.id}
                                    className="grid gap-3 rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <Badge>Active</Badge>
                                        <span className="text-xs text-muted-foreground">
                                            Rule #{rule.id} · Revision{' '}
                                            {rule.revision}
                                        </span>
                                    </div>
                                    <RuleDefinition definition={rule} />
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
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
