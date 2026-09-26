import { Form, Head, Link } from '@inertiajs/react';
import {
    CircleCheck,
    Download,
    ExternalLink,
    Inbox,
    Mail,
    RefreshCw,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { create as createGmailAuthorization } from '@/actions/App/Http/Controllers/Settings/GmailAuthorizationController';
import GmailConnectionCheckController from '@/actions/App/Http/Controllers/Settings/GmailConnectionCheckController';
import GmailImportController from '@/actions/App/Http/Controllers/Settings/GmailImportController';
import {
    dismiss,
    restore,
    retry,
} from '@/actions/App/Http/Controllers/Settings/GmailReviewMessageController';
import GmailUnsupportedMessagesRetryController from '@/actions/App/Http/Controllers/Settings/GmailUnsupportedMessagesRetryController';
import { LocalTimestamp } from '@/components/date-time';
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
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { gmail as gmailDataSource } from '@/routes/data_sources';

type GmailStatus = {
    configured: boolean;
    preview: boolean;
    state:
        | 'disconnected'
        | 'connected'
        | 'stale'
        | 'check_failed'
        | 'reauthorization_required';
    account_identity: string | null;
    last_successful_check_at: string | null;
    last_successful_sync_at: string | null;
    next_scheduled_sync_at: string | null;
    retryable_unsupported_count: number;
    latest_failure: {
        type: 'synchronization' | 'message';
        occurred_at: string;
        error_code: string;
    } | null;
};

type ReviewItem = {
    id: number;
    sender: string | null;
    subject: string | null;
    received_at: string | null;
    summary_state:
        'available' | 'missing' | 'unavailable' | 'reauthorization_required';
    outcome: 'unrecognized' | 'failed' | 'resolved';
    explanation: string;
    dismissed_at: string | null;
    retryable: boolean;
    gmail_url: string | null;
};

type ReviewView = 'all' | 'unrecognized' | 'failed' | 'dismissed';

type Review = {
    view: ReviewView;
    attention_count: number;
    unrecognized_count: number;
    failed_count: number;
    dismissed_count: number;
    items: {
        data: ReviewItem[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

const statusDetails = {
    disconnected: {
        label: 'Not connected',
        summary: 'Connect Gmail to start importing bank notifications.',
        variant: 'secondary',
    },
    connected: {
        label: 'Connected',
        summary: 'Automatic imports run every five minutes.',
        variant: 'secondary',
    },
    stale: {
        label: 'Delayed',
        summary: 'The latest automatic import is overdue.',
        variant: 'outline',
    },
    check_failed: {
        label: 'Check failed',
        summary: 'The latest connection check failed.',
        variant: 'outline',
    },
    reauthorization_required: {
        label: 'Reconnect Gmail',
        summary: 'Google needs you to reconnect before imports can resume.',
        variant: 'destructive',
    },
} as const;

const summaryFallback = {
    missing: 'This email is no longer available in Gmail.',
    unavailable:
        'Email details are temporarily unavailable. Refresh to try opening the original.',
    reauthorization_required: 'Reconnect Gmail to load email details.',
};

function ManualImportButton() {
    return (
        <Form
            {...GmailImportController.form()}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <Button type="submit" size="sm" disabled={processing}>
                    <Download /> {processing ? 'Queueing...' : 'Import now'}
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
            className="flex flex-wrap items-end gap-3"
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
                    className="w-40"
                />
            </div>
            <Button type="submit" disabled={!configured}>
                <Mail /> Connect and import
            </Button>
        </form>
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
                >
                    <RefreshCw className={processing ? 'animate-spin' : ''} />
                    {processing ? 'Checking...' : 'Check connection'}
                </Button>
            )}
        </Form>
    );
}

function ReviewActions({ item }: { item: ReviewItem }) {
    return (
        <div className="flex flex-wrap items-center gap-2 sm:justify-end">
            {item.gmail_url !== null && (
                <Button asChild variant="outline" size="sm">
                    <a
                        href={item.gmail_url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <ExternalLink /> Open in Gmail
                    </a>
                </Button>
            )}
            {item.dismissed_at === null ? (
                <>
                    {item.retryable && (
                        <Form
                            {...retry.form(item.id)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                >
                                    <RefreshCw
                                        className={
                                            processing ? 'animate-spin' : ''
                                        }
                                    />
                                    {processing ? 'Queueing...' : 'Retry email'}
                                </Button>
                            )}
                        </Form>
                    )}
                    <Form
                        {...dismiss.form(item.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="ghost"
                                size="sm"
                                disabled={processing}
                            >
                                Dismiss
                            </Button>
                        )}
                    </Form>
                </>
            ) : (
                <Form
                    {...restore.form(item.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            Restore
                        </Button>
                    )}
                </Form>
            )}
        </div>
    );
}

function ReviewRow({ item }: { item: ReviewItem }) {
    const label =
        item.outcome === 'unrecognized'
            ? 'Unrecognized'
            : item.outcome === 'failed'
              ? 'Processing failed'
              : 'Imported';

    return (
        <article
            className="grid min-w-0 gap-4 border-t p-4 first:border-t-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start md:p-5"
            data-test="gmail-review-item"
        >
            <div className="grid min-w-0 gap-2">
                <div className="flex min-w-0 flex-wrap items-center gap-2">
                    <p className="min-w-0 font-medium break-words">
                        {item.subject ||
                            (item.summary_state === 'available'
                                ? '(No subject)'
                                : 'Email details unavailable')}
                    </p>
                    <Badge
                        variant={
                            item.outcome === 'failed'
                                ? 'destructive'
                                : 'secondary'
                        }
                    >
                        {label}
                    </Badge>
                </div>
                {item.summary_state === 'available' ? (
                    <p className="flex flex-wrap gap-x-2 gap-y-1 type-meta">
                        <span className="break-all">{item.sender}</span>
                        <span aria-hidden="true">·</span>
                        <LocalTimestamp
                            value={item.received_at}
                            missingLabel="Date unavailable"
                        />
                    </p>
                ) : (
                    <p className="type-meta">
                        {summaryFallback[item.summary_state]}
                    </p>
                )}
                <p className="type-body text-muted-foreground">
                    {item.explanation}
                </p>
            </div>
            <ReviewActions item={item} />
        </article>
    );
}

export default function GmailDataSource({
    gmail,
    review,
}: {
    gmail: GmailStatus;
    review: Review;
}) {
    const [loading, setLoading] = useState(false);
    const canImport = ['connected', 'stale', 'check_failed'].includes(
        gmail.state,
    );
    const details = statusDetails[gmail.state];
    const filters: { view: ReviewView; label: string; count: number }[] = [
        {
            view: 'all',
            label: 'Needs attention',
            count: review.attention_count,
        },
        {
            view: 'unrecognized',
            label: 'Unrecognized',
            count: review.unrecognized_count,
        },
        {
            view: 'failed',
            label: 'Processing failed',
            count: review.failed_count,
        },
        {
            view: 'dismissed',
            label: 'Dismissed',
            count: review.dismissed_count,
        },
    ];

    return (
        <>
            <Head title="Gmail" />
            <main className="grid flex-1 content-start gap-6 p-4 md:p-6 xl:grid-cols-[minmax(18rem,0.75fr)_minmax(36rem,1.25fr)]">
                <header className="grid gap-1 xl:col-span-2">
                    <h1 className="type-page-title">Gmail</h1>
                    <p className="type-subtitle">
                        Review emails that did not create a Transaction.
                    </p>
                </header>

                <Card
                    className="min-w-0 gap-0 overflow-hidden py-0 xl:col-start-2 xl:row-start-2"
                    data-test="gmail-review"
                >
                    <CardHeader className="gap-3 p-4 sm:flex-row sm:items-start sm:justify-between md:p-5">
                        <div className="grid gap-1">
                            <CardTitle>Emails needing attention</CardTitle>
                            <CardDescription>
                                Unrecognized emails have no matching supported
                                format. Processing failures could not be
                                completed.
                            </CardDescription>
                        </div>
                        <Badge variant="secondary">
                            {review.attention_count} to review
                        </Badge>
                    </CardHeader>

                    <CardContent className="flex flex-col gap-4 border-t p-4 md:p-5">
                        <nav
                            aria-label="Email review views"
                            className="flex flex-wrap gap-2"
                        >
                            {filters.map((filter) => (
                                <Button
                                    key={filter.view}
                                    asChild
                                    variant={
                                        review.view === filter.view
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    size="sm"
                                >
                                    <Link
                                        href={gmailDataSource({
                                            query: { view: filter.view },
                                        })}
                                        preserveScroll
                                        onStart={() => setLoading(true)}
                                        onFinish={() => setLoading(false)}
                                        aria-current={
                                            review.view === filter.view
                                                ? 'page'
                                                : undefined
                                        }
                                    >
                                        {filter.label}{' '}
                                        <span className="tabular-nums">
                                            {filter.count}
                                        </span>
                                    </Link>
                                </Button>
                            ))}
                        </nav>

                        {review.unrecognized_count > 0 &&
                            review.view !== 'dismissed' && (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/20 p-3">
                                    <p className="type-body text-muted-foreground">
                                        Updated parsers can be tried on
                                        unrecognized emails.
                                    </p>
                                    <Form
                                        {...GmailUnsupportedMessagesRetryController.form()}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    processing || !canImport
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
                                                    ? 'Queueing...'
                                                    : 'Retry all unrecognized'}
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            )}
                        {loading && (
                            <p role="status" className="type-meta">
                                Loading email summaries...
                            </p>
                        )}
                    </CardContent>

                    <CardContent className="p-0">
                        {review.items.data.length === 0 ? (
                            <Empty className="min-h-52 border-t">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Inbox />
                                    </EmptyMedia>
                                    <EmptyTitle>
                                        {review.view === 'dismissed'
                                            ? 'No dismissed emails'
                                            : review.attention_count === 0
                                              ? 'No emails need attention'
                                              : 'No emails in this view'}
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        {gmail.state === 'disconnected'
                                            ? 'Connect Gmail to begin reviewing imported mail.'
                                            : review.view === 'dismissed'
                                              ? 'Emails you dismiss will appear here.'
                                              : review.attention_count === 0
                                                ? 'Your email review is complete for now.'
                                                : 'Try another view or the previous page.'}
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <div>
                                {review.items.data.map((item) => (
                                    <ReviewRow key={item.id} item={item} />
                                ))}
                            </div>
                        )}
                    </CardContent>

                    {review.items.last_page > 1 && (
                        <CardContent className="flex flex-wrap items-center justify-between gap-3 border-t p-4 md:p-5">
                            <p className="type-meta">
                                Page {review.items.current_page} of{' '}
                                {review.items.last_page}
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    asChild={Boolean(
                                        review.items.prev_page_url,
                                    )}
                                    variant="outline"
                                    size="sm"
                                    disabled={!review.items.prev_page_url}
                                >
                                    {review.items.prev_page_url ? (
                                        <Link
                                            href={review.items.prev_page_url}
                                            preserveScroll
                                            onStart={() => setLoading(true)}
                                            onFinish={() => setLoading(false)}
                                        >
                                            Previous
                                        </Link>
                                    ) : (
                                        <span>Previous</span>
                                    )}
                                </Button>
                                <Button
                                    asChild={Boolean(
                                        review.items.next_page_url,
                                    )}
                                    variant="outline"
                                    size="sm"
                                    disabled={!review.items.next_page_url}
                                >
                                    {review.items.next_page_url ? (
                                        <Link
                                            href={review.items.next_page_url}
                                            preserveScroll
                                            onStart={() => setLoading(true)}
                                            onFinish={() => setLoading(false)}
                                        >
                                            Next
                                        </Link>
                                    ) : (
                                        <span>Next</span>
                                    )}
                                </Button>
                            </div>
                        </CardContent>
                    )}
                </Card>

                <div className="grid min-w-0 content-start gap-4 xl:col-start-1 xl:row-start-2">
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

                    {gmail.latest_failure?.type === 'synchronization' && (
                        <Alert variant="destructive">
                            <TriangleAlert />
                            <AlertTitle>
                                The latest Gmail import failed
                            </AlertTitle>
                            <AlertDescription>
                                <LocalTimestamp
                                    value={gmail.latest_failure.occurred_at}
                                />{' '}
                                · {gmail.latest_failure.error_code}
                            </AlertDescription>
                        </Alert>
                    )}

                    <Card id="gmail" className="min-w-0 gap-0 py-0">
                        <CardHeader className="gap-3 p-4 sm:flex-row sm:items-start sm:justify-between md:p-5">
                            <div className="grid gap-1">
                                <CardTitle>
                                    {gmail.account_identity ??
                                        'Gmail is not connected'}
                                </CardTitle>
                                <CardDescription>
                                    {gmail.preview
                                        ? 'Sample emails for trying the review workflow. No Gmail account is connected.'
                                        : details.summary}
                                </CardDescription>
                            </div>
                            <Badge variant={details.variant}>
                                {gmail.preview ? (
                                    'Sample data'
                                ) : (
                                    <>
                                        {gmail.state === 'connected' && (
                                            <CircleCheck />
                                        )}
                                        {details.label}
                                    </>
                                )}
                            </Badge>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4 border-t p-4 md:p-5">
                            {gmail.account_identity !== null && (
                                <div className="flex flex-wrap gap-x-8 gap-y-2 type-meta">
                                    <span>
                                        Last successful import:{' '}
                                        <span data-test="gmail-last-successful-sync">
                                            <LocalTimestamp
                                                value={
                                                    gmail.last_successful_sync_at
                                                }
                                                missingLabel="No imports yet"
                                            />
                                        </span>
                                    </span>
                                    <span>
                                        Next import:{' '}
                                        <LocalTimestamp
                                            value={gmail.next_scheduled_sync_at}
                                            missingLabel="Paused"
                                        />
                                    </span>
                                    <span>
                                        Connection checked:{' '}
                                        <LocalTimestamp
                                            value={
                                                gmail.last_successful_check_at
                                            }
                                            missingLabel="Not checked yet"
                                        />
                                    </span>
                                </div>
                            )}
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="type-meta">
                                    {gmail.preview
                                        ? 'Sample messages are local. They cannot open in Gmail.'
                                        : 'Read-only Gmail access. Money Assistant cannot send, edit, or delete mail.'}
                                </p>
                                {canImport ? (
                                    <div className="flex flex-wrap gap-2">
                                        <ConnectionCheckButton />
                                        <ManualImportButton />
                                    </div>
                                ) : (
                                    <ConnectAndImport
                                        configured={gmail.configured}
                                    />
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </main>
        </>
    );
}

GmailDataSource.layout = {
    breadcrumbs: [{ title: 'Gmail', href: gmailDataSource() }],
};
