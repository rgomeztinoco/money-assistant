import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    headerActions?: ReactNode;
    viewportConstrained?: boolean;
};

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
    action?:
        | {
              type: 'apply_existing_merchant_rule';
              rule_id: number;
          }
        | {
              type: 'create_merchant_rule';
              draft: MerchantRuleDraft;
          };
};

export type MerchantRuleDraft = {
    source_transaction_id: number;
    merchant: string;
    category_id: number;
    transaction_kind: 'spending' | 'refund';
    currency: 'PEN' | 'USD';
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
