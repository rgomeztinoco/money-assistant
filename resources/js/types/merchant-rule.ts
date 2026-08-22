export type MerchantRule = {
    id: number;
    category_id: number;
    category_name: string;
    merchant: string;
    merchant_key: string;
    transaction_kind: 'spending' | 'refund' | null;
    currency: 'PEN' | 'USD' | null;
    enabled: boolean;
};
