import { Link } from '@inertiajs/react';
import { FileCheck2, Mail } from 'lucide-react';
import { gmail as gmailDataSource } from '@/routes/data_sources';
import { show as showStatementImport } from '@/routes/statement_imports';

export type RecordedCoverageSource = {
    status: 'recorded' | 'partially_verified' | 'verified';
    gmail_last_checked_at: string | null;
    verified_periods: Array<{
        id: number;
        period_start: string;
        period_end: string;
        instrument_label: string;
    }>;
};

export function SourceCoverage({
    source,
    className,
    detailed = false,
    gmailMissingLabel,
}: {
    source: RecordedCoverageSource;
    className: string;
    detailed?: boolean;
    gmailMissingLabel: string;
}) {
    const verifiedPeriod = source.verified_periods.at(0);
    const statusLabel =
        source.status === 'partially_verified'
            ? 'Partly statement verified'
            : detailed
              ? 'Recorded activity, not statement verified'
              : 'Recorded, not statement verified';

    return (
        <section className={className}>
            {source.status === 'verified' && verifiedPeriod ? (
                <Link
                    href={showStatementImport(verifiedPeriod.id)}
                    className="inline-flex items-center gap-1.5 font-medium text-foreground hover:underline"
                >
                    <FileCheck2 className="size-4" />
                    {detailed
                        ? 'Statement verified for this period'
                        : 'Statement verified'}
                </Link>
            ) : (
                <span className="inline-flex items-center gap-1.5">
                    <FileCheck2 className="size-4 text-muted-foreground" />
                    {statusLabel}
                </span>
            )}
            <Link
                href={gmailDataSource()}
                className="inline-flex items-center gap-1.5 text-muted-foreground hover:text-foreground"
            >
                <Mail className="size-4" />
                {source.gmail_last_checked_at
                    ? `Gmail checked ${new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(source.gmail_last_checked_at))}`
                    : gmailMissingLabel}
            </Link>
        </section>
    );
}
