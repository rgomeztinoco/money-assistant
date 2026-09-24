import { Plus } from 'lucide-react';
import { useState } from 'react';
import { TransactionEditor } from '@/components/transaction-editor';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { Currency } from '@/types';
import type { BreakdownProps } from './types';

export function ManualTransactionDialog({
    currency,
    today,
    categoryOptions,
}: {
    currency: Currency;
    today: string;
    categoryOptions: BreakdownProps['category_options'];
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <Plus /> Add Transaction
                </Button>
            </DialogTrigger>
            <DialogContent className="inset-0 h-dvh max-h-dvh w-screen max-w-none translate-x-0 translate-y-0 overflow-y-auto rounded-none p-4 sm:top-1/2 sm:left-1/2 sm:h-auto sm:max-h-[90vh] sm:max-w-2xl sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-lg sm:p-6">
                <DialogHeader>
                    <DialogTitle>Add Transaction</DialogTitle>
                    <DialogDescription>
                        Record one confirmed movement.
                    </DialogDescription>
                </DialogHeader>
                <TransactionEditor
                    key={open ? 'new-open' : 'new-closed'}
                    currency={currency}
                    today={today}
                    categoryOptions={categoryOptions.map((option) => ({
                        id: option.id,
                        name: option.name,
                        path: option.path,
                        parent_id: option.parent?.id ?? null,
                        parent_name: option.parent?.name ?? null,
                    }))}
                    onCancel={() => setOpen(false)}
                    onSaved={() => setOpen(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
