import TransactionsIndex from '@/pages/transactions/index';
import type { TransactionsIndexProps } from '@/pages/transactions/index';
import { index } from '@/routes/review_queue';

export default function ReviewQueueIndex(props: TransactionsIndexProps) {
    return <TransactionsIndex {...props} />;
}

ReviewQueueIndex.layout = {
    breadcrumbs: [
        {
            title: 'Review Queue',
            href: index(),
        },
    ],
};
