import { Form, Head } from '@inertiajs/react';
import { CircleCheck, Mail, RefreshCw, TriangleAlert } from 'lucide-react';
import { create as createGmailAuthorization } from '@/actions/App/Http/Controllers/Settings/GmailAuthorizationController';
import GmailConnectionCheckController from '@/actions/App/Http/Controllers/Settings/GmailConnectionCheckController';
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
import { edit } from '@/routes/connections';

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
};

const statusLabels = {
    disconnected: 'Not connected',
    connected: 'Healthy',
    stale: 'Synchronization stale',
    check_failed: 'Check failed',
    reauthorization_required: 'Reauthorization required',
} as const;

function formatTimestamp(timestamp: string | null): string {
    if (timestamp === null) {
        return 'No successful check yet';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(timestamp));
}

export default function Connections({ gmail }: { gmail: GmailStatus }) {
    const needsAuthorization =
        gmail.state === 'disconnected' ||
        gmail.state === 'reauthorization_required';

    return (
        <>
            <Head title="Connection settings" />

            <h1 className="sr-only">Connection settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Connections"
                    description="Manage private, owner-authorized data sources"
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
                                        Dedicated Spending Notification inbox
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
                                )}
                            </span>
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

                        {gmail.configured ? (
                            <Button asChild>
                                <a href={createGmailAuthorization.url()}>
                                    {needsAuthorization
                                        ? 'Authorize Gmail'
                                        : 'Reauthorize Gmail'}
                                </a>
                            </Button>
                        ) : (
                            <Button disabled>
                                {needsAuthorization
                                    ? 'Authorize Gmail'
                                    : 'Reauthorize Gmail'}
                            </Button>
                        )}
                    </CardFooter>
                </Card>
            </div>
        </>
    );
}

Connections.layout = {
    breadcrumbs: [
        {
            title: 'Connection settings',
            href: edit(),
        },
    ],
};
