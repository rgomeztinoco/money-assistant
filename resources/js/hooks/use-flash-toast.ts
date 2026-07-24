import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;
            const transactionStateError = flash?.transaction_state_error as
                string | undefined;

            if (data) {
                toast[data.type](data.message);
            }

            if (transactionStateError) {
                toast.error(transactionStateError);
            }
        });
    }, []);
}
