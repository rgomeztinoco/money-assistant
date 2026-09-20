export type CategoryItem = {
    id: number;
    parent_id: number | null;
    name: string;
    archived_at: string | null;
    child_count: number;
    transaction_count: number;
    active_merchant_rule_count: number;
    archive_impact: {
        active_child_count: number;
        active_merchant_rule_count: number;
        active_children: Array<{ id: number; name: string }>;
        active_merchant_rules: Array<{
            id: number;
            merchant: string;
            category_path: string;
        }>;
    };
};

export type CategoryNode = CategoryItem & {
    children: CategoryItem[];
};

export type CategoryOption = {
    id: number;
    name: string;
    path: string;
    parent_id: number | null;
    parent_name: string | null;
};
