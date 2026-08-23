import { NativeSelect } from '@/components/ui/native-select';
import { incomeSourceLabel } from '@/lib/money-movement';
import type { CategoryOption, IncomeSource } from '@/types';

type UsedCategoryOption = CategoryOption & { used: boolean };

export function CategoryClassificationSelect({
    id,
    name,
    value,
    categoryOptions,
}: {
    id: string;
    name: string;
    value: string;
    categoryOptions: UsedCategoryOption[];
}) {
    const used = categoryOptions.filter((option) => option.used);
    const unused = categoryOptions.filter((option) => !option.used);
    const groups = [
        {
            label: 'Used Categories',
            options: [
                { value: '', label: 'Uncategorized' },
                ...used.map((option) => ({
                    value: option.id.toString(),
                    label: option.path,
                })),
            ],
        },
        ...(unused.length === 0
            ? []
            : [
                  {
                      label: 'Other Categories',
                      options: unused.map((option) => ({
                          value: option.id.toString(),
                          label: option.path,
                      })),
                  },
              ]),
    ];

    return (
        <NativeSelect
            id={id}
            name={name}
            defaultValue={value}
            groups={groups}
        />
    );
}

export function IncomeSourceClassificationSelect({
    id,
    name,
    value,
    incomeSourceOptions,
}: {
    id: string;
    name: string;
    value: IncomeSource;
    incomeSourceOptions: Array<{ value: IncomeSource; used: boolean }>;
}) {
    const used = incomeSourceOptions.filter((option) => option.used);
    const unused = incomeSourceOptions.filter((option) => !option.used);
    const groups = [
        ...(used.length === 0
            ? []
            : [
                  {
                      label: 'Used Income Sources',
                      options: used.map((option) => ({
                          value: option.value,
                          label: incomeSourceLabel(option.value),
                      })),
                  },
              ]),
        ...(unused.length === 0
            ? []
            : [
                  {
                      label: 'Other Income Sources',
                      options: unused.map((option) => ({
                          value: option.value,
                          label: incomeSourceLabel(option.value),
                      })),
                  },
              ]),
    ];

    return (
        <NativeSelect
            id={id}
            name={name}
            defaultValue={value}
            groups={groups}
        />
    );
}
