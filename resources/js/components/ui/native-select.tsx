import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type NativeSelectOption = { value: string; label: string };

type NativeSelectProps = Omit<ComponentProps<'select'>, 'children'> &
    (
        | {
              options: ReadonlyArray<NativeSelectOption>;
              groups?: never;
          }
        | {
              options?: never;
              groups: ReadonlyArray<{
                  label: string;
                  options: ReadonlyArray<NativeSelectOption>;
              }>;
          }
    );

export function NativeSelect({
    options,
    groups,
    className,
    ...props
}: NativeSelectProps) {
    return (
        <select
            className={cn(
                'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30 dark:aria-invalid:ring-destructive/40',
                className,
            )}
            {...props}
        >
            {options?.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
            {groups?.map((group) => (
                <optgroup key={group.label} label={group.label}>
                    {group.options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </optgroup>
            ))}
        </select>
    );
}
