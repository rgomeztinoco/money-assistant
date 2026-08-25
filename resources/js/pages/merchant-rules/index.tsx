import { Form, Head } from '@inertiajs/react';
import { PencilLine, Plus, Store, Trash2 } from 'lucide-react';
import {
    destroy as deleteRule,
    store as createRule,
    update as updateRule,
} from '@/actions/App/Http/Controllers/MerchantRuleController';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/merchant_rules';
import type { CategoryOption, MerchantRule } from '@/types';

function RuleFields({
    idPrefix,
    categoryOptions,
    rule,
}: {
    idPrefix: string;
    categoryOptions: CategoryOption[];
    rule?: MerchantRule;
}) {
    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-merchant`}>Merchant</Label>
                <Input
                    id={`${idPrefix}-merchant`}
                    name="merchant"
                    defaultValue={rule?.merchant ?? ''}
                    maxLength={255}
                    required
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-category`}>Category</Label>
                <NativeSelect
                    id={`${idPrefix}-category`}
                    name="category_id"
                    defaultValue={rule?.category_id.toString() ?? ''}
                    options={[
                        { value: '', label: 'Choose a Category' },
                        ...categoryOptions.map((category) => ({
                            value: category.id.toString(),
                            label: category.path,
                        })),
                    ]}
                    required
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-kind`}>Transaction kind</Label>
                <NativeSelect
                    id={`${idPrefix}-kind`}
                    name="transaction_kind"
                    defaultValue={rule?.transaction_kind ?? ''}
                    options={[
                        { value: '', label: 'Any kind' },
                        { value: 'spending', label: 'Spending' },
                        { value: 'refund', label: 'Refund' },
                    ]}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-currency`}>Currency</Label>
                <NativeSelect
                    id={`${idPrefix}-currency`}
                    name="currency"
                    defaultValue={rule?.currency ?? ''}
                    options={[
                        { value: '', label: 'Any currency' },
                        { value: 'PEN', label: 'PEN' },
                        { value: 'USD', label: 'USD' },
                    ]}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-enabled`}>Status</Label>
                <NativeSelect
                    id={`${idPrefix}-enabled`}
                    name="enabled"
                    defaultValue={rule?.enabled === false ? '0' : '1'}
                    options={[
                        { value: '1', label: 'Enabled' },
                        { value: '0', label: 'Disabled' },
                    ]}
                />
            </div>
        </>
    );
}

function EditRuleDialog({
    rule,
    categoryOptions,
}: {
    rule: MerchantRule;
    categoryOptions: CategoryOption[];
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <PencilLine /> Edit
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[calc(100vh-2rem)] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Edit {rule.merchant}</DialogTitle>
                    <DialogDescription>
                        Changes affect only Transactions created after saving.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...updateRule.form(rule.id)}
                    options={{ preserveScroll: true }}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <RuleFields
                                idPrefix={`rule-${rule.id}`}
                                categoryOptions={categoryOptions}
                                rule={rule}
                            />
                            <InputError
                                message={
                                    errors.merchant ??
                                    errors.category_id ??
                                    errors.transaction_kind ??
                                    errors.currency ??
                                    errors.enabled
                                }
                            />
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Save Merchant Rule
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function MerchantRulesIndex({
    rules,
    category_options: categoryOptions,
}: {
    rules: MerchantRule[];
    category_options: CategoryOption[];
}) {
    return (
        <>
            <Head title="Merchant Rules" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <div className="flex items-center gap-2">
                        <Store className="size-5 text-muted-foreground" />
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Merchant Rules
                        </h1>
                    </div>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Categorize future Transactions by an exact normalized
                        merchant match. Existing Transactions never change.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Create a Merchant Rule</CardTitle>
                        <CardDescription>
                            Optionally narrow the rule by kind or currency.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...createRule.form()}
                            resetOnSuccess
                            className="grid gap-4 md:grid-cols-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <RuleFields
                                        idPrefix="new-rule"
                                        categoryOptions={categoryOptions}
                                    />
                                    <InputError
                                        message={
                                            errors.merchant ??
                                            errors.category_id ??
                                            errors.transaction_kind ??
                                            errors.currency ??
                                            errors.enabled
                                        }
                                    />
                                    <div className="md:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing ? (
                                                <Spinner />
                                            ) : (
                                                <Plus />
                                            )}
                                            Create Merchant Rule
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <div className="grid gap-4 xl:grid-cols-2">
                    {rules.map((rule) => (
                        <Card key={rule.id}>
                            <CardContent className="flex flex-col justify-between gap-4 p-4 sm:flex-row sm:items-start">
                                <div className="grid min-w-0 gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="font-medium">
                                            {rule.merchant}
                                        </h2>
                                        <Badge
                                            variant={
                                                rule.enabled
                                                    ? 'outline'
                                                    : 'secondary'
                                            }
                                        >
                                            {rule.enabled
                                                ? 'Enabled'
                                                : 'Disabled'}
                                        </Badge>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {rule.category_name} ·{' '}
                                        {rule.transaction_kind ?? 'any kind'} ·{' '}
                                        {rule.currency ?? 'any currency'}
                                    </p>
                                    <p className="font-mono text-xs text-muted-foreground">
                                        Exact key: {rule.merchant_key}
                                    </p>
                                </div>
                                <div className="flex shrink-0 flex-wrap gap-2">
                                    <EditRuleDialog
                                        rule={rule}
                                        categoryOptions={categoryOptions}
                                    />
                                    <Form
                                        {...deleteRule.form(rule.id)}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                {processing ? (
                                                    <Spinner />
                                                ) : (
                                                    <Trash2 />
                                                )}
                                                Delete
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </>
    );
}

MerchantRulesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Merchant Rules',
            href: index(),
        },
    ],
};
