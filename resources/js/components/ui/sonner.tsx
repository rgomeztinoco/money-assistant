import { Toaster as Sonner, type ToasterProps } from 'sonner';
import { useState } from 'react';
import { MerchantRuleHistoryDialog } from '@/components/merchant-rule-history-dialog';
import { useAppearance } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';
import type { FlashToast } from '@/types/ui';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();
    const [action, setAction] = useState<FlashToast['action']>();

    useFlashToast(setAction);

    return (
        <>
            <Sonner
                theme={appearance}
                className="toaster group"
                position="bottom-right"
                style={
                    {
                        '--normal-bg': 'var(--popover)',
                        '--normal-text': 'var(--popover-foreground)',
                        '--normal-border': 'var(--border)',
                    } as React.CSSProperties
                }
                {...props}
            />
            {action !== undefined && (
                <MerchantRuleHistoryDialog
                    key={
                        action.type === 'create_merchant_rule'
                            ? `draft-${action.draft.source_transaction_id}-${action.draft.category_id}`
                            : `rule-${action.rule_id}`
                    }
                    ruleId={
                        action.type === 'apply_existing_merchant_rule'
                            ? action.rule_id
                            : undefined
                    }
                    draft={
                        action.type === 'create_merchant_rule'
                            ? action.draft
                            : undefined
                    }
                    onClose={() => setAction(undefined)}
                />
            )}
        </>
    );
}

export { Toaster };
