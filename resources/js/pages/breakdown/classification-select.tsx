import { NativeSelect } from '@/components/ui/native-select';
import { incomeSourceLabel } from '@/lib/money-movement';
import type { IncomeSource } from '@/types';
import type { BreakdownCategoryOption } from './types';

export type CategoryOptionGroup = {
    key: string;
    label: string;
    options: BreakdownCategoryOption[];
};

export function groupCategoryOptions(
    categoryOptions: BreakdownCategoryOption[],
): CategoryOptionGroup[] {
    const parentGroups = new Map<number, CategoryOptionGroup>();

    for (const option of categoryOptions) {
        if (option.parent === null) {
            continue;
        }

        const group = parentGroups.get(option.parent.id) ?? {
            key: `parent-${option.parent.id}`,
            label: option.parent.name,
            options: [],
        };

        group.options.push(option);
        parentGroups.set(option.parent.id, group);
    }

    const topLevelOptions = categoryOptions.filter(
        (option) => option.parent === null,
    );
    const groups = [...parentGroups.values()].sort((left, right) =>
        left.label.localeCompare(right.label),
    );

    return topLevelOptions.length === 0
        ? groups
        : [
              {
                  key: 'top-level',
                  label: 'Top-level categories',
                  options: topLevelOptions,
              },
              ...groups,
          ];
}

export function CategoryClassificationSelect({
    id,
    name,
    value,
    categoryOptions,
}: {
    id: string;
    name: string;
    value: string;
    categoryOptions: BreakdownCategoryOption[];
}) {
    const groups = [
        {
            label: 'Classification',
            options: [{ value: '', label: 'Uncategorized' }],
        },
        ...groupCategoryOptions(categoryOptions).map((group) => ({
            label: group.label,
            options: group.options.map((option) => ({
                value: option.id.toString(),
                label: option.name,
            })),
        })),
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
