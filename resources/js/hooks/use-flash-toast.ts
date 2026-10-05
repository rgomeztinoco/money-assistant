import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

export function useFlashToast(
    onAction: (action: NonNullable<FlashToast['action']>) => void,
): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (data) {
                const action = data.action;

                toast[data.type](
                    data.message,
                    action === undefined
                        ? undefined
                        : {
                              duration: Infinity,
                              closeButton: true,
                              action: {
                                  label:
                                      action.type === 'create_merchant_rule'
                                          ? 'Create merchant rule'
                                          : 'Apply to previous',
                                  onClick: () => onAction(action),
                              },
                          },
                );
            }
        });
    }, [onAction]);
}
