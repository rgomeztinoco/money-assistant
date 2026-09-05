import { Form, Head } from '@inertiajs/react';
import {
    CalendarClock,
    CircleCheck,
    Clock3,
    Download,
    Mail,
    RefreshCw,
    ShieldCheck,
    TriangleAlert,
} from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { create as createGmailAuthorization } from '@/actions/App/Http/Controllers/Settings/GmailAuthorizationController';
import GmailConnectionCheckController from '@/actions/App/Http/Controllers/Settings/GmailConnectionCheckController';
import GmailFailedMessageRetryController from '@/actions/App/Http/Controllers/Settings/GmailFailedMessageRetryController';
import GmailImportController from '@/actions/App/Http/Controllers/Settings/GmailImportController';
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
import { gmail as gmailDataSource } from '@/routes/data_sources';

type GmailStatus = {
    configured: boolean;
    state:
        | 'disconnected'
        | 'connected'
        | 'stale'
        | 'check_failed'
        | 'reauthorization_required';
    account_identity: string | null;
    scope: string;
    connected_at: string | null;
    last_successful_check_at: string | null;
    last_successful_sync_at: string | null;
    next_scheduled_sync_at: string | null;
    last_check_failed_at: string | null;
    reauthorization_required_at: string | null;
    latest_failure: {
        type: 'synchronization' | 'message';
        occurred_at: string;
        error_code: string;
        discovery_id: number | null;
        message_id: string | null;
        retryable: boolean;
    } | null;
};

const statusDetails = {
    disconnected: {
        label: 'Not connected',
        summary: 'Connect Gmail to start importing bank notifications.',
        tone: 'neutral',
    },
    connected: {
        label: 'Connected',
        summary: 'Automatic imports are running every five minutes.',
        tone: 'healthy',
    },
    stale: {
        label: 'Delayed',
        summary:
            'The last automatic import is overdue. Manual import is available.',
        tone: 'warning',
    },
    check_failed: {
        label: 'Check failed',
        summary: 'Imports are active, but the latest connection check failed.',
        tone: 'warning',
    },
    reauthorization_required: {
        label: 'Reconnect Gmail',
        summary: 'Google needs you to reconnect before imports can resume.',
        tone: 'danger',
    },
} as const satisfies Record<
    GmailStatus['state'],
    {
        label: string;
        summary: string;
        tone: 'neutral' | 'healthy' | 'warning' | 'danger';
    }
>;

function formatTimestamp(
    timestamp: string | null,
    timeZone: string,
    missingLabel: string,
): string {
    if (timestamp === null) {
        return missingLabel;
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone,
    }).format(new Date(timestamp));
}

function LocalTimestamp({
    timestamp,
    missingLabel,
    className,
}: {
    timestamp: string | null;
    missingLabel: string;
    className?: string;
}) {
    const timeZone = useSyncExternalStore(
        () => () => undefined,
        () => Intl.DateTimeFormat().resolvedOptions().timeZone,
        () => 'UTC',
    );

    return (
        <time dateTime={timestamp ?? undefined} className={className}>
            {formatTimestamp(timestamp, timeZone, missingLabel)}
        </time>
    );
}

function ManualImportButton() {
    return (
        <Form
            {...GmailImportController.form()}
            options={{ preserveScroll: true }}
            data-test="gmail-import-form"
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    disabled={processing}
                    className="w-full sm:w-auto"
                >
                    {processing ? (
                        <RefreshCw className="animate-spin" />
                    ) : (
                        <Download />
                    )}
                    {processing ? 'Importing...' : 'Import now'}
                </Button>
            )}
        </Form>
    );
}

function ConnectAndImport({ configured }: { configured: boolean }) {
    return (
        <form
            action={createGmailAuthorization.url()}
            method="get"
            className="grid gap-4 rounded-lg border bg-muted/20 p-4 sm:grid-cols-[minmax(0,12rem)_auto] sm:items-end"
            data-test="gmail-authorization-form"
        >
            <div className="grid gap-1.5">
                <Label htmlFor="gmail-import-days">Import previous days</Label>
                <Input
                    id="gmail-import-days"
                    name="import_days"
                    type="number"
                    min={1}
                    max={365}
                    defaultValue={30}
                    required
                />
            </div>
            <Button type="submit" disabled={!configured}>
                <Mail /> Connect and import
            </Button>
        </form>
    );
}

function StatusBadge({ state }: { state: GmailStatus['state'] }) {
    const details = statusDetails[state];

    if (details.tone === 'healthy') {
        return (
            <Badge className="border-emerald-600/20 bg-emerald-600/10 text-emerald-700 shadow-none hover:bg-emerald-600/10 dark:text-emerald-400">
                <CircleCheck className="size-3" /> {details.label}
            </Badge>
        );
    }

    if (details.tone === 'warning') {
        return (
            <Badge className="border-amber-600/20 bg-amber-500/10 text-amber-700 shadow-none hover:bg-amber-500/10 dark:text-amber-400">
                <TriangleAlert className="size-3" /> {details.label}
            </Badge>
        );
    }

    return (
        <Badge
            variant={details.tone === 'danger' ? 'destructive' : 'secondary'}
        >
            {details.label}
        </Badge>
    );
}

function ConnectionCheckButton() {
    return (
        <Form
            {...GmailConnectionCheckController.form()}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    disabled={processing}
                    className="w-full sm:w-auto"
                >
                    <RefreshCw className={processing ? 'animate-spin' : ''} />
                    {processing ? 'Checking...' : 'Check connection'}
                </Button>
            )}
        </Form>
    );
}

export default function GmailDataSource({ gmail }: { gmail: GmailStatus }) {
    const details = statusDetails[gmail.state];
    const canImport =
        gmail.state === 'connected' ||
        gmail.state === 'stale' ||
        gmail.state === 'check_failed';

    return (
        <>
            <Head title="Gmail" />

            <main className="flex flex-1 flex-col p-4 md:p-6">
                <div className="mx-auto grid w-full max-w-5xl gap-5">
                    <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div className="grid gap-1">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Gmail
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Import supported bank notifications into Money
                                Assistant.
                            </p>
                        </div>
                        {canImport && <ManualImportButton />}
                    </header>

                    {!gmail.configured && (
                        <Alert variant="destructive">
                            <TriangleAlert />
                            <AlertTitle>Google OAuth setup required</AlertTitle>
                            <AlertDescription>
                                Add the Gmail client credentials and production
                                callback settings before connecting an account.
                            </AlertDescription>
                        </Alert>
                    )}

                    <Card
                        id="gmail"
                        className="min-w-0 gap-0 overflow-hidden py-0"
                    >
                        <CardHeader className="gap-4 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6">
                            <div className="flex min-w-0 items-start gap-3">
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-muted/40 text-muted-foreground">
                                    <Mail className="size-5" />
                                </div>
                                <div className="grid min-w-0 gap-1">
                                    <CardTitle className="truncate text-base sm:text-lg">
                                        {gmail.account_identity ??
                                            'Gmail is not connected'}
                                    </CardTitle>
                                    <CardDescription className="leading-relaxed">
                                        {details.summary}
                                    </CardDescription>
                                </div>
                            </div>
                            <StatusBadge state={gmail.state} />
                        </CardHeader>

                        <CardContent className="grid gap-0 border-t p-0 lg:grid-cols-3">
                            <div className="grid gap-1 border-b p-5 lg:border-r lg:border-b-0 lg:p-6">
                                <span className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <CalendarClock className="size-4" /> Next
                                    automatic import
                                </span>
                                <LocalTimestamp
                                    timestamp={gmail.next_scheduled_sync_at}
                                    missingLabel={
                                        gmail.state ===
                                        'reauthorization_required'
                                            ? 'Paused'
                                            : 'After Gmail is connected'
                                    }
                                    className="text-lg font-semibold tracking-tight tabular-nums"
                                />
                                <span className="text-xs text-muted-foreground">
                                    Runs every five minutes
                                </span>
                            </div>

                            <div className="grid gap-1 border-b p-5 lg:border-r lg:border-b-0 lg:p-6">
                                <span className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Download className="size-4" /> Last
                                    successful import
                                </span>
                                <LocalTimestamp
                                    timestamp={gmail.last_successful_sync_at}
                                    missingLabel="No imports yet"
                                    className="font-medium tabular-nums"
                                />
                            </div>

                            <div className="grid gap-1 p-5 lg:p-6">
                                <span className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Clock3 className="size-4" /> Connection
                                    checked
                                </span>
                                <LocalTimestamp
                                    timestamp={gmail.last_successful_check_at}
                                    missingLabel="Not checked yet"
                                    className="font-medium tabular-nums"
                                />
                            </div>
                        </CardContent>

                        <CardContent className="border-t p-5 sm:p-6">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex items-start gap-3">
                                    <ShieldCheck className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    <div className="grid gap-0.5">
                                        <p className="text-sm font-medium">
                                            Read-only Gmail access
                                        </p>
                                        <p className="text-sm leading-relaxed text-muted-foreground">
                                            Money Assistant cannot send, edit,
                                            or delete mail.
                                        </p>
                                    </div>
                                </div>
                                {canImport && <ConnectionCheckButton />}
                            </div>

                            {!canImport && (
                                <div className="mt-5 grid gap-2">
                                    <ConnectAndImport
                                        configured={gmail.configured}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Google will ask you to confirm read-only
                                        access.
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {gmail.latest_failure !== null && (
                        <Card className="gap-0 border-destructive/30 py-0">
                            <CardHeader className="gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                                <div className="grid gap-1">
                                    <CardTitle className="text-base">
                                        {gmail.latest_failure.type === 'message'
                                            ? 'A Gmail message could not be processed'
                                            : 'The latest Gmail import failed'}
                                    </CardTitle>
                                    <CardDescription className="flex flex-wrap items-center gap-1">
                                        <LocalTimestamp
                                            timestamp={
                                                gmail.latest_failure.occurred_at
                                            }
                                            missingLabel="Unknown time"
                                        />
                                        <span aria-hidden="true">·</span>
                                        <span>
                                            {gmail.latest_failure.error_code}
                                        </span>
                                    </CardDescription>
                                </div>

                                {gmail.latest_failure.retryable &&
                                    gmail.latest_failure.discovery_id !==
                                        null && (
                                        <Form
                                            {...GmailFailedMessageRetryController.form(
                                                gmail.latest_failure
                                                    .discovery_id,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    <RefreshCw
                                                        className={
                                                            processing
                                                                ? 'animate-spin'
                                                                : ''
                                                        }
                                                    />
                                                    {processing
                                                        ? 'Retrying...'
                                                        : 'Retry message'}
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                            </CardHeader>
                        </Card>
                    )}
                </div>
            </main>
        </>
    );
}

GmailDataSource.layout = {
    breadcrumbs: [
        {
            title: 'Gmail',
            href: gmailDataSource(),
        },
    ],
};
