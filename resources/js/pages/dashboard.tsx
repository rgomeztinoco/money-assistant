import { Head, router } from '@inertiajs/react';
import {
    ArrowDownRight,
    ArrowLeft,
    ArrowRight,
    BarChart3,
    Bot,
    Check,
    CheckCircle2,
    ChevronRight,
    CircleDollarSign,
    Clock3,
    Command,
    Goal,
    Inbox,
    ListFilter,
    MessageCircle,
    MoreHorizontal,
    PencilLine,
    ReceiptText,
    Search,
    ShieldCheck,
    Sparkles,
    Target,
    TrendingDown,
    Upload,
    WalletCards,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { dashboard } from '@/routes';

type VariantKey = 'A' | 'B' | 'C';

type Transaction = {
    id: number;
    merchant: string;
    date: string;
    amount: string;
    category: string;
    confidence: number | null;
    status: 'Review' | 'Confirmed' | 'Receipt';
    source: string;
};

const variants: Array<{ key: VariantKey; name: string }> = [
    { key: 'A', name: 'Daily focus' },
    { key: 'B', name: 'Ledger workspace' },
    { key: 'C', name: 'OpenClaw first' },
];

const transactions: Transaction[] = [
    {
        id: 1,
        merchant: 'Listo!',
        date: 'Today, 9:42 AM',
        amount: 'S/ 42.50',
        category: 'Dining › Lunch',
        confidence: 72,
        status: 'Review',
        source: 'Visa notification',
    },
    {
        id: 2,
        merchant: 'Wong',
        date: 'Yesterday, 6:18 PM',
        amount: 'S/ 186.20',
        category: 'Food › Groceries',
        confidence: 97,
        status: 'Receipt',
        source: 'Receipt proposal',
    },
    {
        id: 3,
        merchant: 'Adobe',
        date: 'Jul 21, 8:01 AM',
        amount: '$ 22.99',
        category: 'Work › Software',
        confidence: 99,
        status: 'Confirmed',
        source: 'Learned Rule',
    },
    {
        id: 4,
        merchant: 'Cabify',
        date: 'Jul 20, 11:36 PM',
        amount: 'S/ 28.40',
        category: 'Uncategorized',
        confidence: 46,
        status: 'Review',
        source: 'AI classification',
    },
    {
        id: 5,
        merchant: 'Mercado 28',
        date: 'Jul 19, 1:12 PM',
        amount: 'S/ 64.00',
        category: 'Dining › Lunch',
        confidence: null,
        status: 'Confirmed',
        source: 'Owner action',
    },
];

function ConfidenceBadge({ confidence }: { confidence: number | null }) {
    if (confidence === null) {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-stone-100 px-2.5 py-1 text-xs font-medium text-stone-600 dark:bg-stone-800 dark:text-stone-300">
                <Check className="size-3" />
                Authoritative
            </span>
        );
    }

    const tone =
        confidence >= 95
            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
            : confidence >= 60
              ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300'
              : 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300';

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${tone}`}
        >
            <span className="size-1.5 rounded-full bg-current" />
            {confidence}% model confidence
        </span>
    );
}

function VariantA() {
    const [reviewIndex, setReviewIndex] = useState(0);
    const reviewItems = [
        {
            merchant: 'Listo!',
            amount: 'S/ 42.50',
            category: 'Dining › Lunch',
            confidence: 72,
            reason: 'The merchant name and weekday lunch timing fit this Category.',
        },
        {
            merchant: 'Cabify',
            amount: 'S/ 28.40',
            category: 'Transport › Rides',
            confidence: 46,
            reason: 'The merchant is recognizable, but no validated assignment exists yet.',
        },
        {
            merchant: 'Wong',
            amount: 'S/ 186.20',
            category: 'Food › Groceries',
            confidence: 97,
            reason: 'A receipt proposal has six Line Items ready to reconcile.',
        },
    ];
    const item = reviewItems[reviewIndex];

    return (
        <div className="min-h-full bg-[#f7f7f2] text-[#18332b] dark:bg-[#10201c] dark:text-[#edf5f1]">
            <div className="mx-auto flex max-w-7xl flex-col gap-8 px-5 py-7 pb-28 lg:px-10">
                <header className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div className="flex flex-col gap-1">
                        <p className="text-xs font-semibold tracking-[0.2em] text-[#668078] uppercase">
                            Thursday · 23 July
                        </p>
                        <h1 className="font-serif text-4xl tracking-tight">
                            Good morning, Ricardo.
                        </h1>
                        <p className="text-sm text-[#668078]">
                            Four things need your attention. The first should
                            take under a minute.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <button className="rounded-full border border-[#cedbd5] bg-white px-4 py-2 text-sm font-semibold shadow-sm dark:border-[#345047] dark:bg-[#18332b]">
                            All Transactions
                        </button>
                        <button className="inline-flex items-center gap-2 rounded-full bg-[#18332b] px-4 py-2 text-sm font-semibold text-white shadow-sm dark:bg-[#dff0e8] dark:text-[#18332b]">
                            <MessageCircle className="size-4" />
                            Ask OpenClaw
                        </button>
                    </div>
                </header>

                <main className="grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(19rem,0.8fr)]">
                    <section className="overflow-hidden rounded-[2rem] bg-white shadow-[0_20px_70px_-38px_rgba(22,57,47,0.45)] dark:bg-[#18332b]">
                        <div className="flex items-center justify-between border-b border-[#e4ebe7] px-6 py-4 dark:border-[#2b463d]">
                            <div className="flex items-center gap-3">
                                <span className="grid size-9 place-items-center rounded-full bg-[#ec6b4e] text-white">
                                    <Inbox className="size-4" />
                                </span>
                                <div>
                                    <h2 className="font-semibold">
                                        Review Queue
                                    </h2>
                                    <p className="text-xs text-[#668078]">
                                        {reviewIndex + 1} of{' '}
                                        {reviewItems.length} · focus mode
                                    </p>
                                </div>
                            </div>
                            <button className="text-sm font-semibold text-[#668078]">
                                Leave for later
                            </button>
                        </div>

                        <div className="flex min-h-[31rem] flex-col justify-between gap-8 p-6 sm:p-9">
                            <div className="flex flex-col gap-7">
                                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                                    <div>
                                        <p className="text-sm text-[#668078]">
                                            Category assignment
                                        </p>
                                        <h3 className="mt-1 font-serif text-4xl">
                                            {item.merchant}
                                        </h3>
                                        <p className="mt-2 text-lg font-semibold">
                                            {item.amount}
                                        </p>
                                    </div>
                                    <ConfidenceBadge
                                        confidence={item.confidence}
                                    />
                                </div>

                                <div className="rounded-2xl bg-[#f4f7f4] p-5 dark:bg-[#102a23]">
                                    <p className="text-xs font-semibold tracking-[0.18em] text-[#668078] uppercase">
                                        Suggested Category
                                    </p>
                                    <div className="mt-3 flex items-center justify-between gap-4">
                                        <div className="flex items-center gap-3">
                                            <span className="grid size-11 place-items-center rounded-xl bg-[#dcebe4] text-[#255e4d] dark:bg-[#244b3f] dark:text-[#bde1d2]">
                                                <WalletCards className="size-5" />
                                            </span>
                                            <div>
                                                <p className="text-lg font-semibold">
                                                    {item.category}
                                                </p>
                                                <p className="text-sm text-[#668078]">
                                                    AI classification
                                                </p>
                                            </div>
                                        </div>
                                        <ChevronRight className="size-5 text-[#82978f]" />
                                    </div>
                                </div>

                                <div className="flex gap-3 rounded-2xl border border-[#dce6e1] p-4 dark:border-[#345047]">
                                    <Sparkles className="mt-0.5 size-4 shrink-0 text-[#dc684d]" />
                                    <div className="flex flex-col gap-1">
                                        <p className="text-sm font-semibold">
                                            Why this confidence?
                                        </p>
                                        <p className="text-sm leading-6 text-[#668078]">
                                            {item.reason}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-[1fr_auto]">
                                <button
                                    onClick={() =>
                                        setReviewIndex(
                                            (reviewIndex + 1) %
                                                reviewItems.length,
                                        )
                                    }
                                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-[#ec6b4e] px-5 py-3.5 font-semibold text-white shadow-sm transition hover:bg-[#d85b42]"
                                >
                                    <Check className="size-4" />
                                    Approve {item.category}
                                </button>
                                <button className="inline-flex items-center justify-center gap-2 rounded-xl border border-[#cad8d2] px-5 py-3.5 font-semibold dark:border-[#49645b]">
                                    <PencilLine className="size-4" />
                                    Correct
                                </button>
                            </div>
                        </div>
                    </section>

                    <aside className="flex flex-col gap-5">
                        <section className="rounded-[1.7rem] bg-[#18332b] p-6 text-[#edf5f1] shadow-[0_18px_55px_-38px_rgba(15,45,36,0.9)] dark:bg-[#203d34]">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-xs font-semibold tracking-[0.16em] text-[#9bb2a9] uppercase">
                                        July so far
                                    </p>
                                    <p className="mt-2 font-serif text-3xl">
                                        S/ 2,840
                                    </p>
                                    <p className="text-sm text-[#9bb2a9]">
                                        plus $148.40
                                    </p>
                                </div>
                                <BarChart3 className="size-6 text-[#ef8065]" />
                            </div>
                            <div className="mt-6 flex items-center gap-2 rounded-xl bg-white/7 p-3 text-sm">
                                <TrendingDown className="size-4 text-[#85ceb2]" />
                                Dining is 12% below its preceding three-month
                                average.
                            </div>
                        </section>

                        <section className="rounded-[1.7rem] border border-[#dce6e1] bg-white p-6 dark:border-[#345047] dark:bg-[#18332b]">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-xs font-semibold tracking-[0.16em] text-[#668078] uppercase">
                                        Category Target
                                    </p>
                                    <h3 className="mt-1 font-semibold">
                                        Food › Groceries
                                    </h3>
                                </div>
                                <Target className="size-5 text-[#ec6b4e]" />
                            </div>
                            <div className="mt-5 h-2 overflow-hidden rounded-full bg-[#e4ebe7] dark:bg-[#2b463d]">
                                <div className="h-full w-[69%] rounded-full bg-[#ec6b4e]" />
                            </div>
                            <div className="mt-2 flex justify-between text-sm">
                                <span className="font-semibold">
                                    S/ 620 spent
                                </span>
                                <span className="text-[#668078]">
                                    S/ 280 remaining
                                </span>
                            </div>
                            <p className="mt-4 text-xs leading-5 text-[#82978f]">
                                Spending to date · not a forecast
                            </p>
                        </section>

                        <section className="rounded-[1.7rem] border border-[#dce6e1] bg-white p-5 dark:border-[#345047] dark:bg-[#18332b]">
                            <div className="flex gap-3">
                                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#e4f0eb] text-[#255e4d] dark:bg-[#244b3f] dark:text-[#bde1d2]">
                                    <Bot className="size-5" />
                                </span>
                                <div>
                                    <p className="text-sm font-semibold">
                                        OpenClaw is ready
                                    </p>
                                    <p className="mt-1 text-sm leading-5 text-[#668078]">
                                        “Show my biggest changes this month” or
                                        send a receipt photo in Telegram.
                                    </p>
                                    <button className="mt-3 text-sm font-semibold text-[#d95e44]">
                                        Open conversation →
                                    </button>
                                </div>
                            </div>
                        </section>
                    </aside>
                </main>
            </div>
        </div>
    );
}

function VariantB() {
    const [selectedId, setSelectedId] = useState(1);
    const [detailMode, setDetailMode] = useState<
        'review' | 'receipt' | 'history'
    >('review');
    const selected =
        transactions.find((transaction) => transaction.id === selectedId) ??
        transactions[0];

    return (
        <div className="min-h-full bg-slate-100 text-slate-950 dark:bg-slate-950 dark:text-slate-100">
            <div className="flex min-h-full flex-col pb-24">
                <header className="border-b border-slate-200 bg-white px-5 py-4 dark:border-slate-800 dark:bg-slate-900">
                    <div className="mx-auto flex max-w-[96rem] flex-col justify-between gap-4 lg:flex-row lg:items-center">
                        <div className="flex items-center gap-5">
                            <div>
                                <p className="text-xs font-semibold tracking-[0.16em] text-slate-500 uppercase">
                                    Money Assistant
                                </p>
                                <h1 className="text-xl font-semibold">
                                    Transactions
                                </h1>
                            </div>
                            <div className="hidden h-8 w-px bg-slate-200 lg:block dark:bg-slate-700" />
                            <div className="hidden items-center gap-1 rounded-lg bg-slate-100 p-1 lg:flex dark:bg-slate-800">
                                <button className="rounded-md bg-white px-3 py-1.5 text-sm font-semibold shadow-sm dark:bg-slate-700">
                                    All
                                </button>
                                <button className="px-3 py-1.5 text-sm text-slate-500">
                                    Review{' '}
                                    <span className="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-800">
                                        4
                                    </span>
                                </button>
                                <button className="px-3 py-1.5 text-sm text-slate-500">
                                    Receipts
                                </button>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="flex min-w-0 flex-1 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-500 lg:w-72 dark:border-slate-700 dark:bg-slate-900">
                                <Search className="size-4" />
                                <input
                                    className="min-w-0 flex-1 bg-transparent outline-none"
                                    placeholder="Search Transactions"
                                />
                                <kbd className="rounded border border-slate-200 px-1.5 text-[10px] dark:border-slate-700">
                                    ⌘K
                                </kbd>
                            </label>
                            <button className="grid size-9 place-items-center rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                                <ListFilter className="size-4" />
                            </button>
                            <button className="inline-flex items-center gap-2 rounded-lg bg-violet-600 px-3.5 py-2 text-sm font-semibold text-white">
                                <Bot className="size-4" />
                                OpenClaw
                            </button>
                        </div>
                    </div>
                </header>

                <div className="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="mx-auto grid max-w-[96rem] grid-cols-2 divide-x divide-slate-200 md:grid-cols-4 dark:divide-slate-800">
                        {[
                            ['Reporting total', 'S/ 3,394.16', 'USD combined'],
                            ['Original USD', '$ 148.40', '4 Transactions'],
                            ['Original PEN', 'S/ 2,840.00', '31 Transactions'],
                            ['Review Queue', '4', '≈ 3 min to clear'],
                        ].map(([label, value, note]) => (
                            <div className="px-5 py-3" key={label}>
                                <p className="text-xs text-slate-500">
                                    {label}
                                </p>
                                <div className="mt-1 flex items-baseline gap-2">
                                    <p className="font-mono text-lg font-semibold">
                                        {value}
                                    </p>
                                    <span className="hidden text-xs text-slate-400 xl:inline">
                                        {note}
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <main className="mx-auto grid w-full max-w-[96rem] flex-1 gap-4 p-4 xl:grid-cols-[minmax(0,1fr)_25rem]">
                    <section className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                            <p className="text-sm font-semibold">
                                35 Transactions · July 2026
                            </p>
                            <div className="flex items-center gap-4 text-xs text-slate-500">
                                <span className="inline-flex items-center gap-1.5">
                                    <span className="size-2 rounded-full bg-amber-400" />
                                    Needs review
                                </span>
                                <button className="font-semibold text-violet-600">
                                    Columns
                                </button>
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[52rem] text-left text-sm">
                                <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-900/70">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">
                                            Merchant
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Category
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Confidence
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Source
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {transactions.map((transaction) => (
                                        <tr
                                            key={transaction.id}
                                            onClick={() =>
                                                setSelectedId(transaction.id)
                                            }
                                            className={`cursor-pointer transition ${
                                                transaction.id === selectedId
                                                    ? 'bg-violet-50/80 dark:bg-violet-950/30'
                                                    : 'hover:bg-slate-50 dark:hover:bg-slate-800/50'
                                            }`}
                                        >
                                            <td className="px-4 py-3.5">
                                                <div className="flex items-center gap-3">
                                                    <span
                                                        className={`size-2 rounded-full ${
                                                            transaction.status ===
                                                            'Review'
                                                                ? 'bg-amber-400'
                                                                : transaction.status ===
                                                                    'Receipt'
                                                                  ? 'bg-violet-500'
                                                                  : 'bg-emerald-500'
                                                        }`}
                                                    />
                                                    <div>
                                                        <p className="font-semibold">
                                                            {
                                                                transaction.merchant
                                                            }
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            {transaction.date}
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3.5 text-slate-700 dark:text-slate-300">
                                                {transaction.category}
                                            </td>
                                            <td className="px-4 py-3.5">
                                                <ConfidenceBadge
                                                    confidence={
                                                        transaction.confidence
                                                    }
                                                />
                                            </td>
                                            <td className="px-4 py-3.5 text-slate-500">
                                                {transaction.source}
                                            </td>
                                            <td className="px-4 py-3.5 text-right font-mono font-semibold">
                                                {transaction.amount}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <aside className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-slate-200 p-5 dark:border-slate-800">
                            <div className="flex items-start justify-between">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h2 className="text-xl font-semibold">
                                            {selected.merchant}
                                        </h2>
                                        {selected.status === 'Review' && (
                                            <span className="rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                                                Review
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-1 text-sm text-slate-500">
                                        {selected.date}
                                    </p>
                                </div>
                                <button className="text-slate-400">
                                    <X className="size-5" />
                                </button>
                            </div>
                            <p className="mt-5 font-mono text-3xl font-semibold">
                                {selected.amount}
                            </p>
                        </div>

                        <div className="grid grid-cols-3 border-b border-slate-200 text-sm dark:border-slate-800">
                            {(
                                [
                                    ['review', 'Review'],
                                    ['receipt', 'Receipt'],
                                    ['history', 'History'],
                                ] as const
                            ).map(([key, label]) => (
                                <button
                                    key={key}
                                    onClick={() => setDetailMode(key)}
                                    className={`border-b-2 px-2 py-3 font-semibold ${
                                        detailMode === key
                                            ? 'border-violet-600 text-violet-700 dark:text-violet-300'
                                            : 'border-transparent text-slate-500'
                                    }`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>

                        <div className="p-5">
                            {detailMode === 'review' && (
                                <div className="flex flex-col gap-5">
                                    <div>
                                        <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                            Current Category
                                        </p>
                                        <button className="mt-2 flex w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-3 text-left dark:border-slate-700">
                                            <span className="font-semibold">
                                                {selected.category}
                                            </span>
                                            <ChevronRight className="size-4 text-slate-400" />
                                        </button>
                                    </div>
                                    <div className="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/60">
                                        <ConfidenceBadge
                                            confidence={selected.confidence}
                                        />
                                        <p className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">
                                            The visible confidence is a model
                                            estimate, not observed accuracy.
                                            Approval makes this assignment
                                            authoritative.
                                        </p>
                                    </div>
                                    <label className="flex items-start gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                        <input
                                            type="checkbox"
                                            className="mt-1 accent-violet-600"
                                        />
                                        <span>
                                            <span className="block text-sm font-semibold">
                                                Create a Learned Rule
                                            </span>
                                            <span className="text-xs leading-5 text-slate-500">
                                                Exact merchant match for future
                                                Transactions. Preview before
                                                activation.
                                            </span>
                                        </span>
                                    </label>
                                    <div className="grid grid-cols-2 gap-2">
                                        <button className="rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-semibold dark:border-slate-700">
                                            Correct
                                        </button>
                                        <button className="rounded-lg bg-violet-600 px-3 py-2.5 text-sm font-semibold text-white">
                                            Approve
                                        </button>
                                    </div>
                                </div>
                            )}

                            {detailMode === 'receipt' && (
                                <div className="flex flex-col gap-4">
                                    <div className="rounded-lg border border-dashed border-violet-300 bg-violet-50 p-4 text-center dark:border-violet-700 dark:bg-violet-950/30">
                                        <ReceiptText className="mx-auto size-6 text-violet-600" />
                                        <p className="mt-2 text-sm font-semibold">
                                            Receipt Breakdown
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-slate-500">
                                            OpenClaw can submit an image-free
                                            proposal from a Telegram photo.
                                        </p>
                                    </div>
                                    {[
                                        'Groceries',
                                        'Household',
                                        'Adjustment',
                                    ].map((label, index) => (
                                        <div
                                            key={label}
                                            className="flex items-center justify-between border-b border-slate-100 pb-3 text-sm dark:border-slate-800"
                                        >
                                            <div>
                                                <p className="font-semibold">
                                                    {label}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {index + 2} Line Items
                                                </p>
                                            </div>
                                            <span className="font-mono">
                                                {
                                                    [
                                                        'S/ 142.70',
                                                        'S/ 39.50',
                                                        'S/ 4.00',
                                                    ][index]
                                                }
                                            </span>
                                        </div>
                                    ))}
                                    <div className="flex items-center justify-between rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                                        <span className="font-semibold">
                                            Reconciles exactly
                                        </span>
                                        <CheckCircle2 className="size-4" />
                                    </div>
                                </div>
                            )}

                            {detailMode === 'history' && (
                                <div className="flex flex-col gap-5">
                                    {[
                                        [
                                            '9:42 AM',
                                            'Transaction confirmed',
                                            'Visa notification',
                                        ],
                                        [
                                            '9:43 AM',
                                            'AI proposed Dining › Lunch',
                                            '72% · classifier v3',
                                        ],
                                        [
                                            'Now',
                                            'Waiting for owner review',
                                            'No confirmed state changed',
                                        ],
                                    ].map(([time, event, detail]) => (
                                        <div
                                            className="grid grid-cols-[3.5rem_1fr] gap-3"
                                            key={event}
                                        >
                                            <span className="font-mono text-xs text-slate-400">
                                                {time}
                                            </span>
                                            <div className="border-l border-slate-200 pl-3 dark:border-slate-700">
                                                <p className="text-sm font-semibold">
                                                    {event}
                                                </p>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    {detail}
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </aside>
                </main>
            </div>
        </div>
    );
}

function VariantC() {
    const [prompt, setPrompt] = useState('month');
    const responses: Record<string, string> = {
        month: 'Dining is S/ 118 lower than its preceding three-month average. Groceries are S/ 74 higher, with 69% of the July Category Target used.',
        review: 'Four Transactions need review. Two are Category approvals, one has uncertain extracted fields, and one Receipt Breakdown is ready to reconcile.',
        receipt:
            'Send the receipt photo here. I will interpret it, then submit only an image-free Receipt Proposal to Money Assistant for your review.',
    };

    return (
        <div className="min-h-full bg-[#090b12] text-[#f3f5fa]">
            <div className="mx-auto flex max-w-[94rem] flex-col gap-4 px-4 py-4 pb-28 lg:px-6">
                <header className="flex flex-col justify-between gap-4 rounded-2xl border border-white/8 bg-[#11141d] px-5 py-4 sm:flex-row sm:items-center">
                    <div className="flex items-center gap-3">
                        <span className="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-[#8a6cff] to-[#42c7b0] shadow-lg shadow-violet-950">
                            <Command className="size-5" />
                        </span>
                        <div>
                            <p className="font-semibold">Money Assistant</p>
                            <p className="text-xs text-[#828a9f]">
                                OpenClaw connected · owner session
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 text-xs">
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-400/20 bg-emerald-400/8 px-3 py-1.5 text-emerald-300">
                            <span className="size-1.5 rounded-full bg-emerald-400" />
                            Private tailnet
                        </span>
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-white/10 px-3 py-1.5 text-[#a8afc0]">
                            <Clock3 className="size-3" />
                            Bound for 24 min
                        </span>
                    </div>
                </header>

                <main className="grid min-h-[42rem] gap-4 lg:grid-cols-[minmax(0,1.45fr)_minmax(21rem,0.72fr)]">
                    <section className="flex min-h-[42rem] flex-col overflow-hidden rounded-2xl border border-white/8 bg-[#11141d]">
                        <div className="flex items-center justify-between border-b border-white/8 px-5 py-4">
                            <div>
                                <h1 className="font-semibold">
                                    Personal finance
                                </h1>
                                <p className="text-xs text-[#828a9f]">
                                    Telegram · one distinct message per action
                                </p>
                            </div>
                            <button className="text-[#828a9f]">
                                <MoreHorizontal className="size-5" />
                            </button>
                        </div>

                        <div className="flex flex-1 flex-col gap-5 overflow-y-auto p-5 sm:p-7">
                            <div className="max-w-[85%] self-end rounded-2xl rounded-br-sm bg-[#6f55dd] px-4 py-3 text-sm leading-6">
                                {prompt === 'month'
                                    ? 'How is this month looking?'
                                    : prompt === 'review'
                                      ? 'What do I need to review?'
                                      : 'I want to submit a receipt.'}
                            </div>

                            <div className="flex max-w-[92%] gap-3">
                                <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-[#8a6cff] to-[#42c7b0]">
                                    <Bot className="size-4" />
                                </span>
                                <div className="flex flex-col gap-3">
                                    <div className="rounded-2xl rounded-tl-sm border border-white/8 bg-[#191d28] px-4 py-3 text-sm leading-6 text-[#d7dbe6]">
                                        {responses[prompt]}
                                    </div>

                                    {prompt === 'month' && (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="rounded-xl border border-white/8 bg-[#151923] p-4">
                                                <div className="flex items-center justify-between">
                                                    <span className="text-xs font-semibold text-[#9299ab]">
                                                        JULY SPENDING
                                                    </span>
                                                    <ArrowDownRight className="size-4 text-emerald-400" />
                                                </div>
                                                <p className="mt-3 text-2xl font-semibold">
                                                    S/ 2,840
                                                </p>
                                                <p className="text-xs text-[#828a9f]">
                                                    plus $148.40 · original
                                                    currencies
                                                </p>
                                            </div>
                                            <div className="rounded-xl border border-white/8 bg-[#151923] p-4">
                                                <div className="flex items-center justify-between">
                                                    <span className="text-xs font-semibold text-[#9299ab]">
                                                        GROCERIES TARGET
                                                    </span>
                                                    <Goal className="size-4 text-[#a78bfa]" />
                                                </div>
                                                <p className="mt-3 text-2xl font-semibold">
                                                    69%
                                                </p>
                                                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-white/10">
                                                    <div className="h-full w-[69%] bg-[#8a6cff]" />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {prompt === 'review' && (
                                        <div className="overflow-hidden rounded-xl border border-white/8 bg-[#151923]">
                                            {transactions
                                                .filter(
                                                    (transaction) =>
                                                        transaction.status !==
                                                        'Confirmed',
                                                )
                                                .map((transaction) => (
                                                    <div
                                                        key={transaction.id}
                                                        className="flex items-center justify-between gap-4 border-b border-white/6 p-3 last:border-0"
                                                    >
                                                        <div>
                                                            <p className="text-sm font-semibold">
                                                                {
                                                                    transaction.merchant
                                                                }
                                                            </p>
                                                            <p className="text-xs text-[#828a9f]">
                                                                {
                                                                    transaction.category
                                                                }
                                                            </p>
                                                        </div>
                                                        <div className="text-right">
                                                            <p className="font-mono text-sm">
                                                                {
                                                                    transaction.amount
                                                                }
                                                            </p>
                                                            <button className="text-xs font-semibold text-[#a78bfa]">
                                                                Review
                                                            </button>
                                                        </div>
                                                    </div>
                                                ))}
                                        </div>
                                    )}

                                    {prompt === 'receipt' && (
                                        <div className="rounded-xl border border-dashed border-[#7065a5] bg-[#171522] p-5 text-center">
                                            <Upload className="mx-auto size-6 text-[#a78bfa]" />
                                            <p className="mt-2 text-sm font-semibold">
                                                Photo stays with OpenClaw
                                            </p>
                                            <p className="mt-1 text-xs leading-5 text-[#828a9f]">
                                                Money Assistant receives no
                                                image—only the proposed
                                                Transaction and Line Items.
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div className="mt-auto flex flex-wrap gap-2">
                                {[
                                    ['month', 'How is this month looking?'],
                                    ['review', 'What needs review?'],
                                    ['receipt', 'Submit a receipt'],
                                ].map(([key, label]) => (
                                    <button
                                        key={key}
                                        onClick={() => setPrompt(key)}
                                        className={`rounded-full border px-3 py-2 text-xs font-semibold transition ${
                                            prompt === key
                                                ? 'border-[#8a6cff] bg-[#8a6cff]/15 text-[#c4b5fd]'
                                                : 'border-white/10 text-[#9299ab] hover:border-white/20'
                                        }`}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="border-t border-white/8 p-4">
                            <div className="flex items-center gap-3 rounded-xl border border-white/10 bg-[#0d1017] px-4 py-3">
                                <input
                                    className="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-[#60677a]"
                                    placeholder="Message OpenClaw…"
                                />
                                <button className="grid size-8 place-items-center rounded-lg bg-[#765de1]">
                                    <ArrowRight className="size-4" />
                                </button>
                            </div>
                        </div>
                    </section>

                    <aside className="flex flex-col gap-4">
                        <section className="rounded-2xl border border-[#8a6cff]/30 bg-gradient-to-b from-[#211a3b] to-[#151522] p-5">
                            <div className="flex items-start justify-between">
                                <span className="grid size-9 place-items-center rounded-lg bg-[#8a6cff]/20 text-[#b9a6ff]">
                                    <ShieldCheck className="size-5" />
                                </span>
                                <span className="rounded-full bg-amber-400/10 px-2.5 py-1 text-xs font-semibold text-amber-300">
                                    Web confirmation
                                </span>
                            </div>
                            <h2 className="mt-5 text-lg font-semibold">
                                Exact operation ready
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-[#a8afc0]">
                                Correct Listo! to Dining › Lunch and create one
                                exact-merchant Learned Rule for future
                                Transactions.
                            </p>
                            <div className="mt-4 rounded-xl bg-black/20 p-3 text-xs leading-5 text-[#9299ab]">
                                Changes 1 Transaction · affects future matching
                                · expires in 28:14
                            </div>
                            <button className="mt-4 w-full rounded-lg bg-[#8a6cff] px-4 py-3 text-sm font-semibold text-white">
                                Review and confirm
                            </button>
                            <p className="mt-3 text-center text-[11px] text-[#697084]">
                                A Confirmation Grant is single-use and bound to
                                this exact operation.
                            </p>
                        </section>

                        <section className="rounded-2xl border border-white/8 bg-[#11141d] p-5">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-xs font-semibold tracking-[0.14em] text-[#828a9f] uppercase">
                                        Review Queue
                                    </p>
                                    <p className="mt-1 text-2xl font-semibold">
                                        4 items
                                    </p>
                                </div>
                                <Inbox className="size-5 text-amber-300" />
                            </div>
                            <div className="mt-4 flex flex-col gap-2">
                                {[
                                    ['Category approvals', '2'],
                                    ['Receipt Breakdown', '1'],
                                    ['Extracted detail', '1'],
                                ].map(([label, value]) => (
                                    <div
                                        className="flex items-center justify-between rounded-lg bg-white/4 px-3 py-2.5 text-sm"
                                        key={label}
                                    >
                                        <span className="text-[#a8afc0]">
                                            {label}
                                        </span>
                                        <span className="font-mono font-semibold">
                                            {value}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="rounded-2xl border border-white/8 bg-[#11141d] p-5">
                            <div className="flex items-center gap-3">
                                <CircleDollarSign className="size-5 text-emerald-300" />
                                <div>
                                    <p className="text-sm font-semibold">
                                        Spending Baseline established
                                    </p>
                                    <p className="text-xs text-[#828a9f]">
                                        Apr–Jun complete · rolling average
                                    </p>
                                </div>
                            </div>
                        </section>
                    </aside>
                </main>
            </div>
        </div>
    );
}

function PrototypeSwitcher({
    current,
    onChange,
}: {
    current: VariantKey;
    onChange: (variant: VariantKey) => void;
}) {
    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                target?.matches(
                    'input, textarea, select, [contenteditable="true"]',
                )
            ) {
                return;
            }

            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }

            const currentIndex = variants.findIndex(
                (variant) => variant.key === current,
            );
            const offset = event.key === 'ArrowRight' ? 1 : -1;
            const nextIndex =
                (currentIndex + offset + variants.length) % variants.length;

            onChange(variants[nextIndex].key);
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [current, onChange]);

    if (import.meta.env.PROD) {
        return null;
    }

    const currentIndex = variants.findIndex(
        (variant) => variant.key === current,
    );
    const move = (offset: number) => {
        const nextIndex =
            (currentIndex + offset + variants.length) % variants.length;

        onChange(variants[nextIndex].key);
    };

    return (
        <div className="fixed bottom-5 left-1/2 z-50 flex -translate-x-1/2 items-center gap-1 rounded-full border border-white/15 bg-neutral-950 p-1.5 text-white shadow-2xl shadow-black/40">
            <button
                onClick={() => move(-1)}
                className="grid size-9 place-items-center rounded-full text-neutral-300 transition hover:bg-white/10 hover:text-white"
                aria-label="Previous prototype variant"
            >
                <ArrowLeft className="size-4" />
            </button>
            <div className="min-w-44 px-3 text-center">
                <p className="text-xs font-semibold">
                    {current} — {variants[currentIndex].name}
                </p>
                <p className="text-[10px] text-neutral-500">
                    Prototype · use ← →
                </p>
            </div>
            <button
                onClick={() => move(1)}
                className="grid size-9 place-items-center rounded-full text-neutral-300 transition hover:bg-white/10 hover:text-white"
                aria-label="Next prototype variant"
            >
                <ArrowRight className="size-4" />
            </button>
        </div>
    );
}

export default function Dashboard() {
    const [variant, setVariant] = useState<VariantKey>(() => {
        if (typeof window === 'undefined') {
            return 'A';
        }

        const requestedVariant = new URLSearchParams(
            window.location.search,
        ).get('variant');

        return variants.some((variant) => variant.key === requestedVariant)
            ? (requestedVariant as VariantKey)
            : 'A';
    });

    const changeVariant = (nextVariant: VariantKey) => {
        const url = new URL(window.location.href);
        url.searchParams.set('variant', nextVariant);
        setVariant(nextVariant);
        router.replace({ url: `${url.pathname}${url.search}` });
    };

    return (
        <>
            <Head title="Core owner workflow prototype" />
            {variant === 'A' && <VariantA />}
            {variant === 'B' && <VariantB />}
            {variant === 'C' && <VariantC />}
            <PrototypeSwitcher current={variant} onChange={changeVariant} />
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Core owner workflow prototype',
            href: dashboard(),
        },
    ],
};
