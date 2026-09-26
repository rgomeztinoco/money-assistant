import { Toaster as Sonner, type ToasterProps } from 'sonner';
import { useState } from 'react';
import { MerchantRuleHistoryDialog } from '@/components/merchant-rule-history-dialog';
import { useAppearance } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();
    const [historyRuleId, setHistoryRuleId] = useState<number | null>(null);

    useFlashToast(setHistoryRuleId);

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
            {historyRuleId !== null && (
                <MerchantRuleHistoryDialog
                    ruleId={historyRuleId}
                    onClose={() => setHistoryRuleId(null)}
                />
            )}
        </>
    );
}

export { Toaster };
