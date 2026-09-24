import { router } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { store as createInlineCategory } from '@/actions/App/Http/Controllers/InlineCategoryController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

export type CategoryPickerOption = {
    id: number;
    name: string;
    path: string;
    parent_id: number | null;
    parent_name: string | null;
};

type CreatedCategory = CategoryPickerOption;

function searchable(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

function isCreatedCategory(value: unknown): value is CreatedCategory {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    return (
        'id' in value &&
        typeof value.id === 'number' &&
        'name' in value &&
        typeof value.name === 'string' &&
        'path' in value &&
        typeof value.path === 'string' &&
        'parent_id' in value &&
        (typeof value.parent_id === 'number' || value.parent_id === null)
    );
}

export function CategoryPicker({
    id,
    name,
    options,
    value,
    defaultValue = '',
    onValueChange,
    emptyLabel = 'Choose a Category',
    allowEmpty = true,
    allowCreate = true,
    createParentId = null,
    createTopLevelOnly = false,
    ariaLabel,
    required = false,
    disabled = false,
    className,
    closeOnSelect = true,
    portalToBody = false,
    popoverFooter,
}: {
    id: string;
    name: string;
    options: CategoryPickerOption[];
    value?: string;
    defaultValue?: string;
    onValueChange?: (value: string) => void;
    emptyLabel?: string;
    allowEmpty?: boolean;
    allowCreate?: boolean;
    createParentId?: number | null;
    createTopLevelOnly?: boolean;
    ariaLabel?: string;
    required?: boolean;
    disabled?: boolean;
    className?: string;
    closeOnSelect?: boolean;
    portalToBody?: boolean;
    popoverFooter?: ReactNode;
}) {
    const portalContainerRef = useRef<HTMLDivElement>(null);
    const [internalValue, setInternalValue] = useState(defaultValue);
    const [createdOption, setCreatedOption] = useState<CreatedCategory | null>(
        null,
    );
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [createOpen, setCreateOpen] = useState(false);
    const [createName, setCreateName] = useState('');
    const [parentId, setParentId] = useState(
        createParentId === null ? '' : createParentId.toString(),
    );
    const [creating, setCreating] = useState(false);
    const [createErrors, setCreateErrors] = useState<Record<string, string>>(
        {},
    );
    const selectedValue = value ?? internalValue;
    const allOptions = useMemo(
        () =>
            createdOption === null ||
            options.some((option) => option.id === createdOption.id)
                ? options
                : [...options, createdOption].sort((left, right) =>
                      left.path.localeCompare(right.path),
                  ),
        [createdOption, options],
    );
    const rootOptions = allOptions.filter(
        (option) => option.parent_id === null,
    );
    const normalizedQuery = searchable(query.trim());
    const filteredOptions = allOptions.filter((option) =>
        searchable(option.path).includes(normalizedQuery),
    );
    const selected = allOptions.find(
        (option) => option.id.toString() === selectedValue,
    );

    function closePicker(): void {
        setOpen(false);
        setQuery('');
    }

    function handleOpenChange(nextOpen: boolean): void {
        if (nextOpen) {
            setOpen(true);

            return;
        }

        closePicker();
    }

    function select(nextValue: string): void {
        if (value === undefined) {
            setInternalValue(nextValue);
        }

        onValueChange?.(nextValue);

        if (closeOnSelect) {
            closePicker();
        }
    }

    function createCategory(): void {
        setCreating(true);
        setCreateErrors({});

        router.post(
            createInlineCategory(),
            {
                name: createName,
                parent_id:
                    createTopLevelOnly || parentId === ''
                        ? null
                        : Number(parentId),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => setCreateErrors(errors),
                onSuccess: (page) => {
                    const created = page.flash.created_category;

                    if (!isCreatedCategory(created)) {
                        return;
                    }

                    const option = {
                        ...created,
                        parent_name:
                            rootOptions.find(
                                (root) => root.id === created.parent_id,
                            )?.name ?? null,
                    };
                    setCreatedOption(option);
                    select(option.id.toString());
                    setCreateOpen(false);
                    setCreateName('');
                    setParentId('');
                },
                onFinish: () => setCreating(false),
            },
        );
    }

    return (
        <div ref={portalContainerRef} className="contents">
            <select
                id={id}
                name={name}
                value={selectedValue}
                required={required}
                disabled={disabled}
                aria-labelledby={`${id}-trigger`}
                className="sr-only"
                onChange={(event) => select(event.currentTarget.value)}
                tabIndex={-1}
            >
                <option value="" disabled={!allowEmpty}>
                    {emptyLabel}
                </option>
                {allOptions.map((option) => (
                    <option key={option.id} value={option.id}>
                        {option.path}
                    </option>
                ))}
            </select>

            <Popover open={open} onOpenChange={handleOpenChange}>
                <PopoverTrigger
                    render={
                        <Button
                            id={`${id}-trigger`}
                            data-test={`${id}-trigger`}
                            type="button"
                            variant="outline"
                            role="combobox"
                            aria-label={ariaLabel ?? emptyLabel}
                            aria-controls={`${id}-options`}
                            aria-expanded={open}
                            disabled={disabled}
                            className={cn(
                                'w-full justify-between font-normal',
                                className,
                            )}
                        />
                    }
                >
                    <span className="truncate">
                        {selected?.path ?? emptyLabel}
                    </span>
                    <ChevronsUpDown />
                </PopoverTrigger>
                <PopoverContent
                    container={portalToBody ? undefined : portalContainerRef}
                    align="start"
                    className="max-h-(--available-height) w-[min(24rem,calc(100vw-2rem))] gap-0 overflow-hidden p-0"
                >
                    <Command shouldFilter={false} className="min-h-0">
                        <CommandInput
                            value={query}
                            onValueChange={setQuery}
                            placeholder="Search Categories"
                            aria-label="Search Categories"
                        />
                        <CommandList id={`${id}-options`}>
                            {allowCreate && (
                                <>
                                    <CommandGroup>
                                        <CommandItem
                                            data-test={`${id}-create-option`}
                                            value="create-new-category"
                                            onSelect={() => {
                                                closePicker();
                                                setCreateOpen(true);
                                                setParentId(
                                                    createParentId === null
                                                        ? ''
                                                        : createParentId.toString(),
                                                );
                                            }}
                                        >
                                            <Plus /> Create a Category
                                        </CommandItem>
                                    </CommandGroup>
                                    <CommandSeparator />
                                </>
                            )}
                            <CommandEmpty>No Categories found.</CommandEmpty>
                            <CommandGroup heading="Categories">
                                {allowEmpty &&
                                    searchable(emptyLabel).includes(
                                        normalizedQuery,
                                    ) && (
                                        <CommandItem
                                            data-test={`${id}-empty-option`}
                                            value={emptyLabel}
                                            onSelect={() => select('')}
                                        >
                                            <Check
                                                className={cn(
                                                    selectedValue === ''
                                                        ? 'opacity-100'
                                                        : 'opacity-0',
                                                )}
                                            />
                                            {emptyLabel}
                                        </CommandItem>
                                    )}
                                {filteredOptions.map((option) => (
                                    <CommandItem
                                        key={option.id}
                                        data-test={`${id}-option-${option.id}`}
                                        value={option.path}
                                        onSelect={() =>
                                            select(option.id.toString())
                                        }
                                    >
                                        <Check
                                            className={cn(
                                                selectedValue ===
                                                    option.id.toString()
                                                    ? 'opacity-100'
                                                    : 'opacity-0',
                                            )}
                                        />
                                        {option.path}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                        {popoverFooter && (
                            <>
                                <Separator />
                                <div className="shrink-0" onClick={closePicker}>
                                    {popoverFooter}
                                </div>
                            </>
                        )}
                    </Command>
                </PopoverContent>
            </Popover>

            {createTopLevelOnly ? (
                createOpen && (
                    <div
                        data-test={`${id}-create-panel`}
                        className="flex flex-col gap-4 rounded-md border p-4"
                    >
                        <div className="flex flex-col gap-1">
                            <p className="font-medium">
                                Create a top-level Category
                            </p>
                            <p className="text-sm text-muted-foreground">
                                It will be selected as the parent.
                            </p>
                        </div>
                        <FieldGroup>
                            <Field
                                data-invalid={createErrors.name !== undefined}
                            >
                                <FieldLabel htmlFor={`${id}-new-name`}>
                                    Name
                                </FieldLabel>
                                <Input
                                    id={`${id}-new-name`}
                                    value={createName}
                                    maxLength={255}
                                    aria-invalid={
                                        createErrors.name !== undefined
                                    }
                                    onChange={(event) =>
                                        setCreateName(event.currentTarget.value)
                                    }
                                />
                                <InputError message={createErrors.name} />
                            </Field>
                        </FieldGroup>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreateOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                disabled={creating || createName.trim() === ''}
                                onClick={createCategory}
                            >
                                {creating && <Spinner />}
                                Create Parent Category
                            </Button>
                        </div>
                    </div>
                )
            ) : (
                <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Create a Category</DialogTitle>
                            <DialogDescription>
                                Add it without leaving this workflow. The new
                                Category will be selected here.
                            </DialogDescription>
                        </DialogHeader>
                        <FieldGroup>
                            <Field
                                data-invalid={createErrors.name !== undefined}
                            >
                                <FieldLabel htmlFor={`${id}-new-name`}>
                                    Name
                                </FieldLabel>
                                <Input
                                    id={`${id}-new-name`}
                                    value={createName}
                                    maxLength={255}
                                    aria-invalid={
                                        createErrors.name !== undefined
                                    }
                                    onChange={(event) =>
                                        setCreateName(event.currentTarget.value)
                                    }
                                />
                                <InputError message={createErrors.name} />
                            </Field>
                            <Field
                                data-invalid={
                                    createErrors.parent_id !== undefined
                                }
                            >
                                <FieldLabel htmlFor={`${id}-new-parent`}>
                                    Parent
                                </FieldLabel>
                                <CategoryPicker
                                    id={`${id}-new-parent`}
                                    name="parent_id"
                                    options={rootOptions}
                                    value={parentId}
                                    onValueChange={setParentId}
                                    emptyLabel="Top-level Category"
                                    allowCreate={false}
                                />
                                <FieldDescription>
                                    Categories support at most two levels.
                                </FieldDescription>
                                <InputError message={createErrors.parent_id} />
                            </Field>
                        </FieldGroup>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreateOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                disabled={creating || createName.trim() === ''}
                                onClick={createCategory}
                            >
                                {creating && <Spinner />}
                                Create Category
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}
