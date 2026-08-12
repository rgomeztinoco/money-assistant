import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    CircleCheck,
    ListChecks,
    MailWarning,
    ShieldAlert,
    TriangleAlert,
    WalletCards,
} from 'lucide-react';
import IntegrationIncidentAcknowledgementController from '@/actions/App/Http/Controllers/IntegrationIncidentAcknowledgementController';
import IntegrationIncidentReplayController from '@/actions/App/Http/Controllers/IntegrationIncidentReplayController';
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
import { formatMinorUnits } from '@/lib/format-minor-units';
import { dashboard } from '@/routes';
import { edit as connectionsEdit } from '@/routes/connections';
import { index as parserProfilesIndex } from '@/routes/parser_profiles';
import { show as reportShow } from '@/routes/reports';
import { index as reviewQueueIndex } from '@/routes/review_queue';
import { index as transactionsIndex } from '@/routes/transactions';
import type { Currency } from '@/types';

type DashboardPeriod = {
    label: string;
    date_from: string;
    date_to: string;
};

type DashboardSpending = {
    totals: Record<Currency, string>;
};

type OperatingException = {
    type:
        | 'parser_security'
        | 'parser_drift'
        | 'gmail_connection'
        | 'integration_incident';
    incident_id?: number;
    integration?: 'gmail';
    failure_kind?: string;
    error_code?: string;
    replayable?: boolean;
    affected_url?: string;
    profile_id?: number;
    profile_name?: string | null;
    count?: number;
    state?: string;
};

type OperatingStatus = {
    summary: {
        gmail: string;
        parser_profiles: {
            healthy_count: number;
            degraded_count: number;
        };
    };
    exceptions: OperatingException[];
};

function periodQuery(period: DashboardPeriod) {
    return {
        date_from: period.date_from,
        date_to: period.date_to,
    };
}

function exceptionPresentation(exception: OperatingException) {
    switch (exception.type) {
        case 'parser_security':
            return {
                icon: ShieldAlert,
                title: `${exception.profile_name ?? 'Parser Profile'} security alert`,
                description: `${exception.count ?? 0} sender authentication ${exception.count === 1 ? 'failure needs' : 'failures need'} review.`,
                href: `${parserProfilesIndex.url({
                    query: {
                        profile: exception.profile_id,
                        alert: 'security',
                    },
                })}#parser-alert-${exception.profile_id}-security`,
            };
        case 'parser_drift':
            return {
                icon: TriangleAlert,
                title: `${exception.profile_name ?? 'Parser Profile'} format drift`,
                description: `${exception.count ?? 0} unsupported or failed ${exception.count === 1 ? 'message needs' : 'messages need'} review.`,
                href: `${parserProfilesIndex.url({
                    query: {
                        profile: exception.profile_id,
                        alert: 'drift',
                    },
                })}#parser-alert-${exception.profile_id}-drift`,
            };
        case 'gmail_connection':
            return {
                icon: MailWarning,
                title: 'Gmail connection needs attention',
                description:
                    exception.state === 'reauthorization_required'
                        ? 'Spending Notification ingestion is paused until Gmail is reauthorized.'
                        : exception.state === 'stale'
                          ? 'The scheduled Gmail synchronization has not completed in the last five minutes.'
                          : 'Review the Gmail connection and its latest check.',
                href: `${connectionsEdit.url({ query: { integration: 'gmail' } })}#gmail`,
            };
        case 'integration_incident': {
            return {
                icon: TriangleAlert,
                title: `Gmail work ${exception.state === 'parked' ? 'is parked' : 'is retrying'}`,
                description:
                    exception.state === 'parked'
                        ? 'Automatic retries stopped. Review the affected item or replay the original work.'
                        : 'The failure has persisted for at least fifteen minutes and automatic recovery is continuing.',
                href: exception.affected_url ?? dashboard.url(),
            };
        }
    }
}

export default function Dashboard({
    period,
    spending,
    operating,
}: {
    period: DashboardPeriod;
    spending: DashboardSpending;
    operating: OperatingStatus;
}) {
    const { review_queue } = usePage().props;
    const healthySystems = [
        operating.summary.gmail === 'connected' ? 'Gmail' : null,
        operating.summary.parser_profiles.healthy_count > 0
            ? `${operating.summary.parser_profiles.healthy_count} healthy Parser ${operating.summary.parser_profiles.healthy_count === 1 ? 'Profile' : 'Profiles'}`
            : null,
    ].filter((system): system is string => system !== null);

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Dashboard
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        What needs attention and where spending stands in{' '}
                        {period.label}.
                    </p>
                </div>

                <section className="grid gap-4 lg:grid-cols-3">
                    <Card
                        className={
                            review_queue.outstanding_count > 0
                                ? 'border-amber-300 bg-amber-50/40 dark:border-amber-800 dark:bg-amber-950/10'
                                : undefined
                        }
                    >
                        <CardHeader>
                            <div className="flex items-center justify-between gap-3">
                                <ListChecks className="size-5 text-muted-foreground" />
                                <Badge variant="outline">
                                    Current workload
                                </Badge>
                            </div>
                            <CardTitle>Review Queue</CardTitle>
                            <CardDescription>
                                Uncertain details remain included in spending
                                while they wait for your decision.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <p className="text-4xl font-semibold tabular-nums">
                                {review_queue.outstanding_count}
                            </p>
                        </CardContent>
                        <CardFooter>
                            <Button asChild className="w-full">
                                <Link
                                    href={reviewQueueIndex()}
                                    data-test="dashboard-review-link"
                                >
                                    Review outstanding work
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </CardFooter>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <div className="flex items-center justify-between gap-3">
                                <WalletCards className="size-5 text-muted-foreground" />
                                <Badge variant="secondary">
                                    {period.label}
                                </Badge>
                            </div>
                            <CardTitle>Current spending</CardTitle>
                            <CardDescription>
                                Net purchases and Refunds kept separate in their
                                original currencies.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 sm:grid-cols-2">
                            {(['USD', 'PEN'] as const).map((currency) => (
                                <Link
                                    key={currency}
                                    href={transactionsIndex({
                                        query: {
                                            ...periodQuery(period),
                                            currency,
                                        },
                                    })}
                                    data-test={`dashboard-spending-${currency.toLowerCase()}`}
                                    className="rounded-lg border bg-muted/20 p-4 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                >
                                    <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        {currency}
                                    </span>
                                    <span className="mt-2 block text-2xl font-semibold tabular-nums">
                                        {formatMinorUnits(
                                            spending.totals[currency],
                                            currency,
                                        )}
                                    </span>
                                </Link>
                            ))}
                        </CardContent>
                    </Card>
                </section>

                <section>
                    <Card>
                        <CardHeader>
                            <BarChart3 className="size-5 text-muted-foreground" />
                            <CardTitle>Currency reports</CardTitle>
                            <CardDescription>
                                Inspect selected periods, monthly history, and
                                Category groups without combining currencies.
                            </CardDescription>
                        </CardHeader>
                        <CardFooter className="flex-wrap gap-2">
                            <Button asChild variant="outline">
                                <Link
                                    href={reportShow('PEN', {
                                        query: periodQuery(period),
                                    })}
                                >
                                    Open PEN report
                                    <ArrowRight />
                                </Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link
                                    href={reportShow('USD', {
                                        query: periodQuery(period),
                                    })}
                                >
                                    Open USD report
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </CardFooter>
                    </Card>
                </section>

                <section className="flex flex-col gap-4">
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Operating attention
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Only conditions with a next action are expanded.
                            </p>
                        </div>
                        <Badge
                            variant={
                                operating.exceptions.length > 0
                                    ? 'destructive'
                                    : 'secondary'
                            }
                        >
                            {operating.exceptions.length > 0 ? (
                                <TriangleAlert />
                            ) : (
                                <CircleCheck />
                            )}
                            {operating.exceptions.length}{' '}
                            {operating.exceptions.length === 1
                                ? 'exception'
                                : 'exceptions'}
                        </Badge>
                    </div>

                    {operating.exceptions.length > 0 ? (
                        <div className="grid gap-3 lg:grid-cols-2">
                            {operating.exceptions.map((exception, index) => {
                                const presentation =
                                    exceptionPresentation(exception);
                                const Icon = presentation.icon;

                                return (
                                    <Card
                                        key={`${exception.type}-${exception.incident_id ?? exception.profile_id ?? index}`}
                                        className="border-amber-300 py-4 dark:border-amber-800"
                                    >
                                        <CardHeader className="flex-row items-start gap-3">
                                            <div className="rounded-lg bg-amber-100 p-2 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                <Icon className="size-4" />
                                            </div>
                                            <div className="flex flex-1 flex-col gap-1">
                                                <CardTitle className="text-base">
                                                    {presentation.title}
                                                </CardTitle>
                                                <CardDescription>
                                                    {presentation.description}
                                                </CardDescription>
                                            </div>
                                            <div className="flex flex-wrap items-center justify-end gap-2">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={presentation.href}
                                                        data-test={`dashboard-exception-${exception.type}`}
                                                    >
                                                        Review
                                                        <ArrowRight />
                                                    </Link>
                                                </Button>

                                                {exception.type ===
                                                    'integration_incident' &&
                                                    exception.incident_id !==
                                                        undefined && (
                                                        <>
                                                            {exception.replayable && (
                                                                <Form
                                                                    {...IntegrationIncidentReplayController.form(
                                                                        exception.incident_id,
                                                                    )}
                                                                    options={{
                                                                        preserveScroll: true,
                                                                    }}
                                                                >
                                                                    {({
                                                                        processing,
                                                                    }) => (
                                                                        <Button
                                                                            type="submit"
                                                                            size="sm"
                                                                            disabled={
                                                                                processing
                                                                            }
                                                                        >
                                                                            Replay
                                                                        </Button>
                                                                    )}
                                                                </Form>
                                                            )}

                                                            <Form
                                                                {...IntegrationIncidentAcknowledgementController.form(
                                                                    exception.incident_id,
                                                                )}
                                                                options={{
                                                                    preserveScroll: true,
                                                                }}
                                                            >
                                                                {({
                                                                    processing,
                                                                }) => (
                                                                    <Button
                                                                        type="submit"
                                                                        size="sm"
                                                                        variant="ghost"
                                                                        disabled={
                                                                            processing
                                                                        }
                                                                    >
                                                                        Acknowledge
                                                                    </Button>
                                                                )}
                                                            </Form>
                                                        </>
                                                    )}
                                            </div>
                                        </CardHeader>
                                    </Card>
                                );
                            })}
                        </div>
                    ) : (
                        <Card className="border-emerald-200 bg-emerald-50/50 py-4 dark:border-emerald-900 dark:bg-emerald-950/10">
                            <CardContent className="flex items-center gap-3">
                                <CircleCheck className="size-5 text-emerald-700 dark:text-emerald-400" />
                                <p className="text-sm font-medium">
                                    No operating exceptions need attention.
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {healthySystems.length > 0 && (
                        <p className="text-xs text-muted-foreground">
                            Healthy summary: {healthySystems.join(' · ')}
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
