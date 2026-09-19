const shortMonthNames = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
] as const;
const longMonthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
] as const;

type DateOnlyParts = {
    year: number;
    month: number;
    day: number;
};

function invalidDate(value: string): never {
    throw new RangeError(`Invalid ISO date value: ${value}`);
}

function parseDateOnly(value: string): DateOnlyParts {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (match === null) {
        return invalidDate(value);
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(Date.UTC(year, month - 1, day));

    if (
        date.getUTCFullYear() !== year ||
        date.getUTCMonth() !== month - 1 ||
        date.getUTCDate() !== day
    ) {
        return invalidDate(value);
    }

    return { year, month, day };
}

function parseInstant(value: string): Date {
    const match =
        /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/.exec(
            value,
        );

    if (match === null) {
        return invalidDate(value);
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const hour = Number(match[4]);
    const minute = Number(match[5]);
    const second = Number(match[6]);
    const daysInMonth = [
        31,
        year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0) ? 29 : 28,
        31,
        30,
        31,
        30,
        31,
        31,
        30,
        31,
        30,
        31,
    ];

    if (
        month < 1 ||
        month > 12 ||
        day < 1 ||
        day > daysInMonth[month - 1] ||
        hour > 23 ||
        minute > 59 ||
        second > 59
    ) {
        return invalidDate(value);
    }

    const date = new Date(value);

    if (value.trim() === '' || Number.isNaN(date.getTime())) {
        return invalidDate(value);
    }

    return date;
}

export function resolvedTimeZone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
}

export function formatFullDate(value: string): string {
    const { year, month, day } = parseDateOnly(value);

    return `${day} ${shortMonthNames[month - 1]} ${year}`;
}

export function formatContextualDate(value: string): string {
    const { month, day } = parseDateOnly(value);

    return `${day} ${shortMonthNames[month - 1]}`;
}

export function formatMonthHeading(value: string): string {
    const { year, month } = parseDateOnly(value);

    return `${longMonthNames[month - 1]} ${year}`;
}

export function formatCompactMonthYear(value: string): string {
    const { year, month } = parseDateOnly(value);

    return `${shortMonthNames[month - 1]} ${year}`;
}

export function formatCalendarMonth(date: Date): string {
    const month = date.getMonth();

    if (Number.isNaN(date.getTime())) {
        return invalidDate(String(date));
    }

    return shortMonthNames[month];
}

export function formatDateRange(dateFrom: string, dateTo: string): string {
    const from = parseDateOnly(dateFrom);
    const to = parseDateOnly(dateTo);

    if (dateFrom > dateTo) {
        throw new RangeError(
            `Invalid ISO date range: ${dateFrom} to ${dateTo}`,
        );
    }

    if (dateFrom === dateTo) {
        return formatFullDate(dateFrom);
    }

    if (from.year === to.year && from.month === to.month) {
        return `${from.day} ${shortMonthNames[from.month - 1]} – ${to.day} ${shortMonthNames[to.month - 1]} ${to.year}`;
    }

    if (from.year === to.year) {
        return `${from.day} ${shortMonthNames[from.month - 1]} – ${to.day} ${shortMonthNames[to.month - 1]} ${to.year}`;
    }

    return `${from.day} ${shortMonthNames[from.month - 1]} ${from.year} – ${to.day} ${shortMonthNames[to.month - 1]} ${to.year}`;
}

export function formatMonthSpan(dateFrom: string, dateTo: string): string {
    const from = parseDateOnly(dateFrom);
    const to = parseDateOnly(dateTo);

    if (dateFrom > dateTo) {
        throw new RangeError(
            `Invalid ISO date range: ${dateFrom} to ${dateTo}`,
        );
    }

    if (from.year === to.year && from.month === to.month) {
        return formatCompactMonthYear(dateFrom);
    }

    if (from.year === to.year) {
        return `${shortMonthNames[from.month - 1]} – ${shortMonthNames[to.month - 1]} ${to.year}`;
    }

    return `${shortMonthNames[from.month - 1]} ${from.year} – ${shortMonthNames[to.month - 1]} ${to.year}`;
}

export function formatReportingPeriod(period: {
    unit: 'week' | 'month' | 'quarter' | 'year' | 'custom';
    date_from: string;
    date_to: string;
}): string {
    const { year, month } = parseDateOnly(period.date_from);

    if (period.unit === 'month') {
        return formatMonthHeading(period.date_from);
    }

    if (period.unit === 'quarter') {
        return `Q${Math.ceil(month / 3)} ${year}`;
    }

    if (period.unit === 'year') {
        return String(year);
    }

    return formatDateRange(period.date_from, period.date_to);
}

export function isLastDayOfMonth(value: string): boolean {
    const { year, month, day } = parseDateOnly(value);

    return day === new Date(Date.UTC(year, month, 0)).getUTCDate();
}

export function formatTimestamp(value: string, timeZone: string): string {
    const parts = new Intl.DateTimeFormat('en-US', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        timeZone,
    }).formatToParts(parseInstant(value));
    const valueFor = (type: Intl.DateTimeFormatPartTypes): string => {
        const part = parts.find((candidate) => candidate.type === type)?.value;

        if (part === undefined) {
            return invalidDate(value);
        }

        return part;
    };

    return `${valueFor('day')} ${valueFor('month')} ${valueFor('year')}, ${valueFor('hour')}:${valueFor('minute')} ${valueFor('dayPeriod').toUpperCase()}`;
}

export function formatRelativeTime(value: string, now: Date): string {
    const seconds = Math.round(
        (parseInstant(value).getTime() - now.getTime()) / 1_000,
    );
    const absoluteSeconds = Math.abs(seconds);

    if (absoluteSeconds < 60) {
        return 'just now';
    }

    const minutes = Math.round(absoluteSeconds / 60);

    if (minutes < 60) {
        return seconds < 0
            ? `${minutes} ${minutes === 1 ? 'minute' : 'minutes'} ago`
            : `in ${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return seconds < 0
            ? `${hours} ${hours === 1 ? 'hour' : 'hours'} ago`
            : `in ${hours} ${hours === 1 ? 'hour' : 'hours'}`;
    }

    const days = Math.round(hours / 24);

    return seconds < 0
        ? `${days} ${days === 1 ? 'day' : 'days'} ago`
        : `in ${days} ${days === 1 ? 'day' : 'days'}`;
}
