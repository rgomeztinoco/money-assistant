import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

export function useFlashToast(
    onApplyExistingRule: (ruleId: number) => void,
): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (data) {
                const ruleId =
                    data.action?.type === 'apply_existing_merchant_rule'
                        ? data.action.rule_id
                        : null;

                toast[data.type](
                    data.message,
                    ruleId === null
                        ? undefined
                        : {
                              duration: 12000,
                              action: {
                                  label: 'Apply to previous',
                                  onClick: () => onApplyExistingRule(ruleId),
                              },
                          },
                );
            }
        });
    }, [onApplyExistingRule]);
}
