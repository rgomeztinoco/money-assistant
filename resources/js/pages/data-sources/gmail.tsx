import { Form, Head } from '@inertiajs/react';
import { CircleCheck, Mail, RefreshCw, TriangleAlert } from 'lucide-react';
import { create as createGmailAuthorization } from '@/actions/App/Http/Controllers/Settings/GmailAuthorizationController';
import GmailConnectionCheckController from '@/actions/App/Http/Controllers/Settings/GmailConnectionCheckController';
import GmailFailedMessageRetryController from '@/actions/App/Http/Controllers/Settings/GmailFailedMessageRetryController';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
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

const statusLabels = {
    disconnected: 'Not connected',
    connected: 'Healthy',
    stale: 'Synchronization stale',
    check_failed: 'Check failed',
    reauthorization_required: 'Reauthorization required',
} as const;

function formatTimestamp(
    timestamp: string | null,
    missingLabel = 'Not yet',
): string {
    if (timestamp === null) {
        return missingLabel;
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(timestamp));
}

export default function GmailDataSource({ gmail }: { gmail: GmailStatus }) {
    const needsAuthorization =
        gmail.state === 'disconnected' ||
        gmail.state === 'reauthorization_required';

    return (
        <>
            <Head title="Gmail" />

            <h1 className="sr-only">Gmail</h1>

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Gmail"
                    description="Monitor ongoing activity recorded from supported bank notifications"
                />

                {!gmail.configured && (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle>Google OAuth setup required</AlertTitle>
                        <AlertDescription>
                            Configure the Gmail client ID, client secret, exact
                            callback URI, and confirm the OAuth project is In
                            production before connecting this account.
                        </AlertDescription>
                    </Alert>
                )}

                {gmail.state === 'reauthorization_required' && (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle>Gmail ingestion is paused</AlertTitle>
                        <AlertDescription>
                            Google rejected the retained authorization. The
                            owner must explicitly reauthorize Gmail before
                            ingestion can resume.
                        </AlertDescription>
                    </Alert>
                )}

                {gmail.state === 'check_failed' && (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle>The latest Gmail check failed</AlertTitle>
                        <AlertDescription>
                            The retained authorization was not rejected, so
                            ingestion is not paused. Try the connection check
                            again.
                        </AlertDescription>
                    </Alert>
                )}

                {gmail.state === 'stale' && (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle>Gmail synchronization is stale</AlertTitle>
                        <AlertDescription>
                            The scheduled synchronization has not completed in
                            the last five minutes. Check the scheduler and queue
                            worker before relying on newly received messages.
                        </AlertDescription>
                    </Alert>
                )}

                <Card
                    id="gmail"
                    className="overflow-hidden target:ring-2 target:ring-ring"
                >
                    <CardHeader className="border-b bg-muted/30">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-start gap-3">
                                <div className="rounded-lg border bg-background p-2.5 text-muted-foreground shadow-xs">
                                    <Mail className="size-5" />
                                </div>
                                <div className="grid gap-1">
                                    <CardTitle>Gmail</CardTitle>
                                    <CardDescription>
                                        Read-only connection
                                    </CardDescription>
                                </div>
                            </div>
                            <Badge
                                variant={
                                    gmail.state === 'reauthorization_required'
                                        ? 'destructive'
                                        : gmail.state === 'check_failed' ||
                                            gmail.state === 'stale'
                                          ? 'destructive'
                                          : gmail.state === 'connected'
                                            ? 'default'
                                            : 'secondary'
                                }
                            >
                                {gmail.state === 'connected' && (
                                    <CircleCheck className="size-3" />
                                )}
                                {statusLabels[gmail.state]}
                            </Badge>
                        </div>
                    </CardHeader>

                    <CardContent className="grid gap-5 pt-1 text-sm">
                        <div className="grid gap-1">
                            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Gmail account
                            </span>
                            <span className="font-medium">
                                {gmail.account_identity ??
                                    'No account connected'}
                            </span>
                        </div>
                        <div className="grid gap-1">
                            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Authorization
                            </span>
                            <span>Read-only Gmail access</span>
                            <code className="text-xs break-all text-muted-foreground">
                                {gmail.scope}
                            </code>
                        </div>
                        <div className="grid gap-1">
                            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Last successful check
                            </span>
                            <span>
                                {formatTimestamp(
                                    gmail.last_successful_check_at,
                                    'No successful check yet',
                                )}
                            </span>
                        </div>
                        <div className="grid gap-1">
                            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Last successful synchronization
                            </span>
                            <span>
                                {formatTimestamp(
                                    gmail.last_successful_sync_at,
                                    'No successful synchronization yet',
                                )}
                            </span>
                        </div>
                        <div className="grid gap-2 border-t pt-5">
                            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Latest processing failure
                            </span>
                            {gmail.latest_failure === null ? (
                                <span className="text-muted-foreground">
                                    No retained Gmail processing failure
                                </span>
                            ) : (
                                <div className="grid gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="grid gap-1">
                                            <span className="font-medium">
                                                {gmail.latest_failure.type ===
                                                'message'
                                                    ? 'Message processing failed'
                                                    : 'Synchronization failed'}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {formatTimestamp(
                                                    gmail.latest_failure
                                                        .occurred_at,
                                                )}
                                            </span>
                                            <code className="text-xs text-muted-foreground">
                                                {
                                                    gmail.latest_failure
                                                        .error_code
                                                }
                                            </code>
                                        </div>

                                        {gmail.latest_failure.retryable &&
                                            gmail.latest_failure
                                                .discovery_id !== null && (
                                                <Form
                                                    {...GmailFailedMessageRetryController.form(
                                                        gmail.latest_failure
                                                            .discovery_id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
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
                                    </div>
                                </div>
                            )}
                        </div>
                    </CardContent>

                    <CardFooter className="flex-wrap gap-3 border-t bg-muted/20 pt-6">
                        {(gmail.state === 'connected' ||
                            gmail.state === 'stale' ||
                            gmail.state === 'check_failed') && (
                            <Form {...GmailConnectionCheckController.form()}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        <RefreshCw
                                            className={
                                                processing ? 'animate-spin' : ''
                                            }
                                        />
                                        {processing
                                            ? 'Checking...'
                                            : 'Check connection'}
                                    </Button>
                                )}
                            </Form>
                        )}

                        <form
                            action={createGmailAuthorization.url()}
                            method="get"
                            className="grid w-full gap-3 sm:max-w-md"
                        >
                            <div className="grid gap-1.5">
                                <p className="font-medium">
                                    How far back should Gmail import?
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Import starts as soon as Google returns you
                                    to Money Assistant.
                                </p>
                            </div>
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div className="grid flex-1 gap-1.5">
                                    <Label
                                        htmlFor="gmail-import-days"
                                        className="text-xs text-muted-foreground"
                                    >
                                        Previous days
                                    </Label>
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
                                <Button
                                    type="submit"
                                    disabled={!gmail.configured}
                                >
                                    {needsAuthorization
                                        ? 'Authorize and import'
                                        : 'Reauthorize and import'}
                                </Button>
                            </div>
                        </form>
                    </CardFooter>
                </Card>
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
