import { Form, Head, Link } from '@inertiajs/react';
import {
    Activity,
    FileSearch,
    RefreshCw,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import { show as showSourceMessage } from '@/actions/App/Http/Controllers/ParserProfileSourceMessageController';
import { store as recoverNotification } from '@/actions/App/Http/Controllers/SpendingNotificationRecoveryController';
import { store as retryNotification } from '@/actions/App/Http/Controllers/SpendingNotificationRetryController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { index } from '@/routes/parser_profiles';

type ProfileHealth = {
    state: 'healthy' | 'degraded';
    counts: {
        created: number;
        created_with_review: number;
        unsupported: number;
        failed: number;
        ignored: number;
    };
    last_success: string | null;
    oldest_unresolved_failure: string | null;
};

type Profile = {
    id: number;
    name: string;
    current_version: number;
    enabled_at: string | null;
    health: ProfileHealth;
};

type AlertReference = {
    id: number;
    discovery_id: number | null;
    outcome: 'authentication_failed' | 'unsupported' | 'failed';
    created_at: string | null;
};

type ParserAlert = {
    profile_id: number;
    profile_name: string;
    kind: 'security' | 'drift';
    count: number;
    oldest_failure: string | null;
    references: AlertReference[];
};

type SourceMessage = {
    id: number;
    message_id: string;
    received_at: string;
    from_address: string;
    subject: string;
};

function HealthCounts({ health }: { health: ProfileHealth }) {
    const counts = [
        ['created', health.counts.created],
        ['created with review', health.counts.created_with_review],
        ['unsupported', health.counts.unsupported],
        ['failed', health.counts.failed],
        ['ignored', health.counts.ignored],
    ] as const;

    return (
        <div className="flex flex-wrap gap-1.5 text-xs text-muted-foreground">
            {counts.map(([label, count]) => (
                <span key={label} className="rounded-md bg-muted px-2 py-1">
                    {count} {label}
                </span>
            ))}
        </div>
    );
}

function ManualRecoveryForm({ reference }: { reference: AlertReference }) {
    return (
        <Form
            {...recoverNotification.form(reference.id)}
            options={{ preserveScroll: true }}
            className="grid gap-3 rounded-lg border bg-background p-3"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div className="grid gap-1.5">
                            <Label
                                htmlFor={`recovery-${reference.id}-occurred-on`}
                            >
                                Date
                            </Label>
                            <Input
                                id={`recovery-${reference.id}-occurred-on`}
                                name="occurred_on"
                                type="date"
                                required
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`recovery-${reference.id}-amount`}>
                                Amount (minor units)
                            </Label>
                            <Input
                                id={`recovery-${reference.id}-amount`}
                                name="amount_minor"
                                type="number"
                                min="1"
                                required
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label
                                htmlFor={`recovery-${reference.id}-currency`}
                            >
                                Currency
                            </Label>
                            <NativeSelect
                                id={`recovery-${reference.id}-currency`}
                                name="currency"
                                defaultValue="PEN"
                                options={[
                                    { value: 'PEN', label: 'PEN' },
                                    { value: 'USD', label: 'USD' },
                                ]}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`recovery-${reference.id}-kind`}>
                                Kind
                            </Label>
                            <NativeSelect
                                id={`recovery-${reference.id}-kind`}
                                name="kind"
                                defaultValue="purchase"
                                options={[
                                    { value: 'purchase', label: 'Purchase' },
                                    { value: 'refund', label: 'Refund' },
                                ]}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label
                                htmlFor={`recovery-${reference.id}-merchant`}
                            >
                                Merchant or description
                            </Label>
                            <Input
                                id={`recovery-${reference.id}-merchant`}
                                name="merchant_description"
                                required
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <InputError
                            message={
                                errors.recovery ??
                                errors.occurred_on ??
                                errors.amount_minor ??
                                errors.currency ??
                                errors.kind ??
                                errors.merchant_description
                            }
                        />
                        <Button type="submit" size="sm" disabled={processing}>
                            {processing && <Spinner />}
                            Record and link Transaction
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function DriftReferenceActions({ reference }: { reference: AlertReference }) {
    return (
        <div className="grid gap-3 rounded-lg border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="text-sm">
                    <span className="font-medium">
                        Message reference #{reference.id}
                    </span>{' '}
                    <span className="text-muted-foreground">
                        · {reference.outcome.replaceAll('_', ' ')}
                    </span>
                </div>
                <div className="flex flex-wrap gap-2">
                    {reference.discovery_id !== null && (
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={showSourceMessage(reference.discovery_id)}
                            >
                                <FileSearch />
                                Review or ignore format
                            </Link>
                        </Button>
                    )}
                    {reference.outcome === 'unsupported' && (
                        <Form
                            {...retryNotification.form(reference.id)}
                            options={{ preserveScroll: true }}
                        >
                            {({ errors, processing }) => (
                                <div className="grid gap-1">
                                    <Button
                                        type="submit"
                                        variant="secondary"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <RefreshCw />
                                        )}
                                        Retry current profile
                                    </Button>
                                    <InputError message={errors.retry} />
                                </div>
                            )}
                        </Form>
                    )}
                </div>
            </div>
            <ManualRecoveryForm reference={reference} />
        </div>
    );
}

function GroupedAlert({ alert }: { alert: ParserAlert }) {
    const isSecurity = alert.kind === 'security';

    return (
        <Alert variant="destructive">
            {isSecurity ? <ShieldAlert /> : <Activity />}
            <AlertTitle>
                {isSecurity
                    ? 'Spending Notification security failure'
                    : 'Parser drift detected'}
            </AlertTitle>
            <AlertDescription className="grid gap-3">
                <p>
                    {alert.profile_name} has {alert.count}{' '}
                    {isSecurity
                        ? 'authentication failure'
                        : 'unresolved format failure'}
                    {alert.count === 1 ? '' : 's'}. Messages are grouped here
                    without retaining their subject or body.
                </p>
                {isSecurity ? (
                    <p>
                        No Transaction was created. Review the sender directly
                        in Gmail before approving any broader trust boundary.
                    </p>
                ) : (
                    <div className="grid gap-3">
                        {alert.references.map((reference) => (
                            <DriftReferenceActions
                                key={reference.id}
                                reference={reference}
                            />
                        ))}
                    </div>
                )}
            </AlertDescription>
        </Alert>
    );
}

export default function ParserProfilesIndex({
    profiles,
    alerts,
    source_messages: sourceMessages,
}: {
    profiles: Profile[];
    alerts: ParserAlert[];
    source_messages: SourceMessage[];
}) {
    return (
        <>
            <Head title="Parser Profiles" />

            <div className="flex flex-col gap-6">
                <Heading
                    title="Parser Profiles"
                    description="Create deterministic Spending Notification support and recover visible parser failures without silently broadening trust."
                />

                {alerts.map((alert) => (
                    <GroupedAlert
                        key={`${alert.profile_id}-${alert.kind}`}
                        alert={alert}
                    />
                ))}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Profile health</CardTitle>
                            <CardDescription>
                                Each profile reports decided outcomes and
                                unresolved failures across its approved
                                versions.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {profiles.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No Parser Profiles have been created.
                                </p>
                            ) : (
                                profiles.map((profile) => (
                                    <div
                                        key={profile.id}
                                        className="grid gap-3 rounded-lg border p-3"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="grid gap-1">
                                                <span className="font-medium">
                                                    {profile.name}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    Version{' '}
                                                    {profile.current_version}
                                                </span>
                                            </div>
                                            <Badge
                                                variant={
                                                    profile.health.state ===
                                                    'healthy'
                                                        ? 'secondary'
                                                        : 'destructive'
                                                }
                                            >
                                                {profile.health.state ===
                                                'healthy' ? (
                                                    <ShieldCheck />
                                                ) : (
                                                    <ShieldAlert />
                                                )}
                                                {profile.health.state ===
                                                'healthy'
                                                    ? 'Healthy'
                                                    : 'Degraded'}
                                            </Badge>
                                        </div>
                                        <HealthCounts health={profile.health} />
                                        <div className="grid gap-1 text-xs text-muted-foreground sm:grid-cols-2">
                                            <span>
                                                Last success:{' '}
                                                {profile.health.last_success?.slice(
                                                    0,
                                                    10,
                                                ) ?? 'None yet'}
                                            </span>
                                            <span>
                                                Oldest unresolved failure:{' '}
                                                {profile.health.oldest_unresolved_failure?.slice(
                                                    0,
                                                    10,
                                                ) ?? 'None'}
                                            </span>
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Choose a source message</CardTitle>
                            <CardDescription>
                                Message content is fetched from Gmail only while
                                you review and confirm a profile.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {sourceMessages.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No unprocessed Gmail messages are available.
                                </p>
                            ) : (
                                sourceMessages.map((source) => (
                                    <div
                                        key={source.id}
                                        className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">
                                                {source.subject}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {source.from_address}
                                            </p>
                                        </div>
                                        <Button asChild size="sm">
                                            <Link
                                                href={showSourceMessage(
                                                    source.id,
                                                )}
                                            >
                                                <FileSearch />
                                                Review
                                            </Link>
                                        </Button>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

ParserProfilesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Parser Profiles',
            href: index(),
        },
    ],
};
