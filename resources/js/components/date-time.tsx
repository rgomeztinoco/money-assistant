import { useSyncExternalStore } from 'react';
import {
    formatContextualDate,
    formatFullDate,
    formatRelativeTime,
    formatTimestamp,
    formatWeekdayDate,
    resolvedTimeZone,
} from '@/lib/date-presentation';

const serverTimeZone = () => null;
const currentMinute = () => Math.floor(Date.now() / 60_000);
const serverMinute = () => null;

function subscribeToTimeZone(onStoreChange: () => void): () => void {
    window.addEventListener('focus', onStoreChange);
    document.addEventListener('visibilitychange', onStoreChange);

    return () => {
        window.removeEventListener('focus', onStoreChange);
        document.removeEventListener('visibilitychange', onStoreChange);
    };
}

function subscribeToMinuteBoundary(onStoreChange: () => void): () => void {
    let interval: ReturnType<typeof setInterval> | undefined;
    const timeout = setTimeout(
        () => {
            onStoreChange();
            interval = setInterval(onStoreChange, 60_000);
        },
        60_000 - (Date.now() % 60_000) + 25,
    );
    window.addEventListener('focus', onStoreChange);

    return () => {
        clearTimeout(timeout);
        window.removeEventListener('focus', onStoreChange);

        if (interval !== undefined) {
            clearInterval(interval);
        }
    };
}

function useBrowserTimeZone(): string | null {
    return useSyncExternalStore(
        subscribeToTimeZone,
        resolvedTimeZone,
        serverTimeZone,
    );
}

export function DateText({
    value,
    format = 'full',
    className,
}: {
    value: string;
    format?: 'full' | 'contextual' | 'weekday';
    className?: string;
}) {
    return (
        <time dateTime={value} className={className}>
            {format === 'contextual'
                ? formatContextualDate(value)
                : format === 'weekday'
                  ? formatWeekdayDate(value)
                  : formatFullDate(value)}
        </time>
    );
}

export function RelativeTimestamp({
    value,
    className,
}: {
    value: string;
    className?: string;
}) {
    const timeZone = useBrowserTimeZone();
    const minute = useSyncExternalStore(
        subscribeToMinuteBoundary,
        currentMinute,
        serverMinute,
    );

    if (timeZone === null || minute === null) {
        return (
            <span className={className} aria-label="Relative time loading">
                ...
            </span>
        );
    }

    const exactLabel = `${formatTimestamp(value, timeZone)} (${timeZone})`;

    return (
        <time
            dateTime={value}
            className={className}
            title={exactLabel}
            aria-label={exactLabel}
        >
            {formatRelativeTime(value, new Date(minute * 60_000))}
        </time>
    );
}

export function LocalTimestamp({
    value,
    missingLabel,
    className,
}: {
    value: string | null;
    missingLabel?: string;
    className?: string;
}) {
    const timeZone = useBrowserTimeZone();

    if (value === null) {
        return (
            <span className={className}>{missingLabel ?? 'Not available'}</span>
        );
    }

    if (timeZone === null) {
        return (
            <span className={className} aria-label="Local time loading">
                ...
            </span>
        );
    }

    const label = formatTimestamp(value, timeZone);
    const exactLabel = `${label} (${timeZone})`;

    return (
        <time
            dateTime={value}
            className={className}
            title={exactLabel}
            aria-label={exactLabel}
        >
            {label}
        </time>
    );
}
