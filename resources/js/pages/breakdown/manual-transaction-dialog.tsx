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
import type { CategoryOption, Currency } from '@/types';
import { pickerCategoryOptions } from './classification-select';
import type { BreakdownCategoryOption } from './types';

export function ManualTransactionDialog({
    currency,
    today,
    categoryOptions,
}: {
    currency: Currency;
    today: string;
    categoryOptions: CategoryOption[] | BreakdownCategoryOption[];
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <Plus /> Add Transaction
                </Button>
            </DialogTrigger>
            <DialogContent className="inset-0 flex h-dvh max-h-dvh w-screen max-w-none translate-x-0 translate-y-0 flex-col gap-0 overflow-hidden rounded-none p-0 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:h-[min(48rem,90dvh)] sm:max-h-[90dvh] sm:w-full sm:max-w-2xl sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-lg">
                <DialogHeader className="shrink-0 border-b px-4 py-4 pr-12 sm:px-6">
                    <DialogTitle>Add Transaction</DialogTitle>
                    <DialogDescription>
                        Record one confirmed movement.
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
                    <TransactionEditor
                        key={open ? 'new-open' : 'new-closed'}
                        currency={currency}
                        today={today}
                        categoryOptions={categoryOptions.map((option) =>
                            'parent' in option
                                ? pickerCategoryOptions([option])[0]
                                : option,
                        )}
                        onCancel={() => setOpen(false)}
                        onSaved={() => setOpen(false)}
                    />
                </div>
            </DialogContent>
        </Dialog>
    );
}
