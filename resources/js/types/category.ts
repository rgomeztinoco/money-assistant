export type CategoryItem = {
    id: number;
    parent_id: number | null;
    name: string;
    archived_at: string | null;
    transaction_count: number;
};

export type CategoryNode = CategoryItem & {
    children: CategoryItem[];
};

export type CategoryOption = {
    id: number;
    path: string;
};
