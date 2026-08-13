import { Form, Head, router, usePage } from '@inertiajs/react';
import { BadgeCheck, FileText, ScanSearch, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { store } from '@/actions/App/Http/Controllers/ParserProfileController';
import { store as previewStore } from '@/actions/App/Http/Controllers/ParserProfilePreviewController';
import { store as storeFormat } from '@/actions/App/Http/Controllers/SpendingNotificationFormatController';
import { update as updateFormat } from '@/actions/App/Http/Controllers/SpendingNotificationFormatController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { formatMinorUnits } from '@/lib/format-minor-units';

type AuthenticationResult = {
    result: string | null;
    domain: string | null;
    aligned: boolean;
};

type SourceMessage = {
    discovery_id: number;
    message_id: string;
    received_at: string;
    from_address: string;
    subject: string;
    authentication: Record<string, AuthenticationResult>;
    mime_parts: {
        text_plain: string | null;
        text_html: string | null;
    };
};

type ExistingProfile = {
    id: number;
    name: string;
    formats: Array<{ id: number; name: string }>;
};

type ParserProfileTransactionPreview = {
    purpose: 'spending';
    occurred_on: string;
    amount_minor: string;
    currency: 'USD' | 'PEN';
    kind: 'purchase' | 'refund';
    merchant_description: string;
    provisional_fields: string[];
};

type ParserProfilePreview =
    ParserProfileTransactionPreview | { purpose: 'ignore' };

const inputClassName =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

export default function CreateParserProfile({
    source,
    profiles,
}: {
    source: SourceMessage;
    profiles: ExistingProfile[];
}) {
    const alignedAuthentication = Object.entries(source.authentication)
        .filter(([, result]) => result.aligned)
        .map(([mechanism]) => ({
            value: mechanism,
            label: mechanism.toUpperCase(),
        }));
    const availableMimeParts = [
        source.mime_parts.text_plain !== null
            ? { value: 'text_plain', label: 'Plain text' }
            : null,
        source.mime_parts.text_html !== null
            ? { value: 'text_html', label: 'HTML source' }
            : null,
    ].filter((option): option is { value: string; label: string } => {
        return option !== null;
    });
    const browserTimezone =
        Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/Lima';
    const { flash } = usePage();
    const initialPreview = flash.parser_profile_preview as
        ParserProfilePreview | undefined;
    const [preview, setPreview] = useState(initialPreview);
    const [selectedProfileId, setSelectedProfileId] = useState('');
    const [selectedFormatId, setSelectedFormatId] = useState('');
    const selectedProfile = profiles.find(
        (profile) => String(profile.id) === selectedProfileId,
    );
    const [formatPurpose, setFormatPurpose] = useState<'spending' | 'ignore'>(
        initialPreview?.purpose ?? 'spending',
    );

    useEffect(() => {
        return router.on('flash', (event) => {
            const nextFlash = (event as CustomEvent).detail?.flash;

            const nextPreview = nextFlash?.parser_profile_preview as
                ParserProfilePreview | undefined;

            setPreview(nextPreview);

            if (nextPreview !== undefined) {
                setFormatPurpose(nextPreview.purpose);
            }
        });
    }, []);

    return (
        <>
            <Head title="Create Parser Profile" />

            <div className="flex flex-col gap-6">
                <Heading
                    title="Create Parser Profile"
                    description="Review this Gmail message transiently, then define the exact trust and extraction rules."
                />

                <Card>
                    <CardHeader>
                        <div className="flex items-start justify-between gap-4">
                            <div className="grid gap-1">
                                <CardTitle>{source.subject}</CardTitle>
                                <CardDescription>
                                    From {source.from_address}
                                </CardDescription>
                            </div>
                            <Badge variant="outline">
                                <ShieldCheck />
                                Transient preview
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-6">
                        <div className="grid gap-3 sm:grid-cols-3">
                            {Object.entries(source.authentication).map(
                                ([mechanism, result]) => (
                                    <div
                                        key={mechanism}
                                        className="grid gap-1 rounded-lg border p-3"
                                    >
                                        <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            {mechanism}
                                        </span>
                                        <span className="flex items-center gap-2 text-sm font-medium">
                                            {result.aligned && (
                                                <BadgeCheck className="size-4 text-emerald-600" />
                                            )}
                                            {result.result ?? 'unavailable'}
                                        </span>
                                        <span className="text-xs break-all text-muted-foreground">
                                            {result.domain ?? 'No domain'}
                                        </span>
                                    </div>
                                ),
                            )}
                        </div>

                        {source.mime_parts.text_plain !== null && (
                            <div className="grid gap-2">
                                <div className="flex items-center gap-2 text-sm font-medium">
                                    <FileText className="size-4" />
                                    Plain-text MIME part
                                </div>
                                <pre className="max-h-96 overflow-auto rounded-lg border bg-muted/30 p-4 text-sm whitespace-pre-wrap">
                                    {source.mime_parts.text_plain}
                                </pre>
                            </div>
                        )}

                        {source.mime_parts.text_html !== null && (
                            <div className="grid gap-2">
                                <div className="flex items-center gap-2 text-sm font-medium">
                                    <FileText className="size-4" />
                                    HTML MIME part
                                </div>
                                <pre className="max-h-96 overflow-auto rounded-lg border bg-muted/30 p-4 text-sm whitespace-pre-wrap">
                                    {source.mime_parts.text_html}
                                </pre>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Form
                    {...(preview === undefined
                        ? previewStore.form()
                        : selectedProfileId === ''
                          ? store.form()
                          : selectedFormatId === ''
                            ? storeFormat.form(Number(selectedProfileId))
                            : updateFormat.form([
                                  Number(selectedProfileId),
                                  Number(selectedFormatId),
                              ]))}
                    className="grid gap-6"
                    onChange={(event) => {
                        setPreview(undefined);

                        const target = event.nativeEvent.target;

                        if (
                            target instanceof HTMLSelectElement &&
                            target.name === 'parser_profile_id'
                        ) {
                            setSelectedProfileId(target.value);
                            setSelectedFormatId('');
                        }

                        if (
                            target instanceof HTMLSelectElement &&
                            target.name === 'format_purpose'
                        ) {
                            setFormatPurpose(
                                target.value === 'ignore'
                                    ? 'ignore'
                                    : 'spending',
                            );
                        }
                    }}
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="source_message_discovery_id"
                                value={source.discovery_id}
                            />

                            {errors.profile && (
                                <Alert variant="destructive">
                                    <AlertTitle>
                                        Profile cannot be confirmed
                                    </AlertTitle>
                                    <AlertDescription>
                                        {errors.profile}
                                    </AlertDescription>
                                </Alert>
                            )}

                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        Profile and trust boundary
                                    </CardTitle>
                                    <CardDescription>
                                        Create a sender trust boundary or add a
                                        current format to an existing profile.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-5 md:grid-cols-2">
                                    <SelectField
                                        label="Profile destination"
                                        name="parser_profile_id"
                                        options={[
                                            {
                                                value: '',
                                                label: 'Create a new profile',
                                            },
                                            ...profiles.map((profile) => ({
                                                value: String(profile.id),
                                                label: `${profile.name} — add a format`,
                                            })),
                                        ]}
                                        error={errors.parser_profile_id}
                                    />
                                    {selectedProfile !== undefined && (
                                        <SelectField
                                            label="Format destination"
                                            name="format_destination"
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'Add a new format',
                                                },
                                                ...selectedProfile.formats.map(
                                                    (format) => ({
                                                        value: String(
                                                            format.id,
                                                        ),
                                                        label: `Replace ${format.name}`,
                                                    }),
                                                ),
                                            ]}
                                            onChange={setSelectedFormatId}
                                        />
                                    )}
                                    <FormField
                                        label="New profile name"
                                        name="profile_name"
                                        disabled={selectedProfileId !== ''}
                                        error={errors.profile_name}
                                    />
                                    <FormField
                                        label="Format name"
                                        name="format_name"
                                        error={errors.format_name}
                                    />
                                    <SelectField
                                        label="Format outcome"
                                        name="format_purpose"
                                        options={[
                                            {
                                                value: 'spending',
                                                label: 'Create a Transaction',
                                            },
                                            {
                                                value: 'ignore',
                                                label: 'Ignore as non-spending',
                                            },
                                        ]}
                                        error={errors.format_purpose}
                                    />
                                    <SelectField
                                        label="Required authentication"
                                        name="authentication_mechanism"
                                        options={alignedAuthentication}
                                        error={errors.authentication_mechanism}
                                    />
                                    <SelectField
                                        label="Canonical MIME source"
                                        name="mime_source"
                                        options={availableMimeParts}
                                        error={errors.mime_source}
                                    />
                                    <FormField
                                        label="Exact subject marker"
                                        name="subject_marker"
                                        error={errors.subject_marker}
                                    />
                                    <FormField
                                        label="Exact body marker"
                                        name="body_marker"
                                        error={errors.body_marker}
                                    />
                                </CardContent>
                            </Card>

                            {formatPurpose === 'spending' && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            Amount and Transaction kind
                                        </CardTitle>
                                        <CardDescription>
                                            Boundaries may use the literal
                                            escape sequence \n for a line
                                            ending. Exactly one amount must be
                                            found.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid gap-5 md:grid-cols-2">
                                        <BoundaryField
                                            label="Text immediately before amount"
                                            name="amount_prefix"
                                            error={errors.amount_prefix}
                                        />
                                        <BoundaryField
                                            label="Text immediately after amount"
                                            name="amount_suffix"
                                            error={errors.amount_suffix}
                                        />
                                        <SelectField
                                            label="Decimal separator"
                                            name="decimal_separator"
                                            options={[
                                                {
                                                    value: '.',
                                                    label: 'Period (.)',
                                                },
                                                {
                                                    value: ',',
                                                    label: 'Comma (,)',
                                                },
                                            ]}
                                            error={errors.decimal_separator}
                                        />
                                        <SelectField
                                            label="Grouping separator"
                                            name="grouping_separator"
                                            options={[
                                                {
                                                    value: 'none',
                                                    label: 'None',
                                                },
                                                {
                                                    value: ',',
                                                    label: 'Comma (,)',
                                                },
                                                {
                                                    value: '.',
                                                    label: 'Period (.)',
                                                },
                                                {
                                                    value: 'space',
                                                    label: 'Space',
                                                },
                                            ]}
                                            error={errors.grouping_separator}
                                        />
                                        <SelectField
                                            label="Currency token position"
                                            name="currency_position"
                                            options={[
                                                {
                                                    value: 'before',
                                                    label: 'Before amount',
                                                },
                                                {
                                                    value: 'after',
                                                    label: 'After amount',
                                                },
                                            ]}
                                            error={errors.currency_position}
                                        />
                                        <FormField
                                            label="Exact currency token"
                                            name="currency_token"
                                            placeholder="S/ or $"
                                            error={errors.currency_token}
                                        />
                                        <SelectField
                                            label="Currency mapping"
                                            name="currency"
                                            options={[
                                                { value: 'PEN', label: 'PEN' },
                                                { value: 'USD', label: 'USD' },
                                            ]}
                                            error={errors.currency}
                                        />
                                        <SelectField
                                            label="Amount semantics"
                                            name="amount_semantics"
                                            options={[
                                                {
                                                    value: 'absolute',
                                                    label: 'Unsigned absolute value',
                                                },
                                                {
                                                    value: 'signed',
                                                    label: 'Explicit signed value',
                                                },
                                            ]}
                                            error={errors.amount_semantics}
                                        />
                                        <SelectField
                                            label="Transaction-kind semantics"
                                            name="kind_semantics"
                                            options={[
                                                {
                                                    value: 'fixed_purchase',
                                                    label: 'Always purchase',
                                                },
                                                {
                                                    value: 'fixed_refund',
                                                    label: 'Always Refund',
                                                },
                                            ]}
                                            error={errors.kind_semantics}
                                        />
                                    </CardContent>
                                </Card>
                            )}

                            {formatPurpose === 'spending' && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            Date and merchant extraction
                                        </CardTitle>
                                        <CardDescription>
                                            Missing or ambiguous values use a
                                            provisional value and enter one
                                            grouped Review Queue item.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid gap-5 md:grid-cols-2">
                                        <BoundaryField
                                            label="Text immediately before date"
                                            name="date_prefix"
                                            error={errors.date_prefix}
                                        />
                                        <BoundaryField
                                            label="Text immediately after date"
                                            name="date_suffix"
                                            error={errors.date_suffix}
                                        />
                                        <SelectField
                                            label="Date grammar"
                                            name="date_format"
                                            options={[
                                                {
                                                    value: 'd/m/Y',
                                                    label: 'DD/MM/YYYY',
                                                },
                                                {
                                                    value: 'd-m-Y',
                                                    label: 'DD-MM-YYYY',
                                                },
                                                {
                                                    value: 'Y-m-d',
                                                    label: 'YYYY-MM-DD',
                                                },
                                                {
                                                    value: 'd/m/Y H:i',
                                                    label: 'DD/MM/YYYY HH:mm',
                                                },
                                                {
                                                    value: 'd-m-Y H:i',
                                                    label: 'DD-MM-YYYY HH:mm',
                                                },
                                                {
                                                    value: 'Y-m-d H:i',
                                                    label: 'YYYY-MM-DD HH:mm',
                                                },
                                            ]}
                                            error={errors.date_format}
                                        />
                                        <FormField
                                            label="Institution timezone"
                                            name="timezone"
                                            defaultValue={browserTimezone}
                                            error={errors.timezone}
                                        />
                                        <BoundaryField
                                            label="Text immediately before merchant"
                                            name="merchant_prefix"
                                            required={false}
                                            error={errors.merchant_prefix}
                                        />
                                        <BoundaryField
                                            label="Text immediately after merchant"
                                            name="merchant_suffix"
                                            required={false}
                                            error={errors.merchant_suffix}
                                        />
                                    </CardContent>
                                </Card>
                            )}

                            {preview?.purpose === 'spending' && (
                                <Card data-testid="candidate-transaction">
                                    <CardHeader>
                                        <CardTitle>
                                            Candidate Transaction
                                        </CardTitle>
                                        <CardDescription>
                                            Review the exact values this profile
                                            will create before enabling it.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                                        <CandidateValue
                                            label="Amount"
                                            value={formatMinorUnits(
                                                preview.amount_minor,
                                                preview.currency,
                                            )}
                                        />
                                        <CandidateValue
                                            label="Kind"
                                            value={
                                                preview.kind === 'purchase'
                                                    ? 'Purchase'
                                                    : 'Refund'
                                            }
                                        />
                                        <CandidateValue
                                            label="Occurrence date"
                                            value={preview.occurred_on}
                                            provisional={preview.provisional_fields.includes(
                                                'occurred_on',
                                            )}
                                        />
                                        <CandidateValue
                                            label="Merchant"
                                            value={preview.merchant_description}
                                            provisional={preview.provisional_fields.includes(
                                                'merchant_description',
                                            )}
                                        />
                                        <CandidateValue
                                            label="Currency"
                                            value={preview.currency}
                                        />
                                    </CardContent>
                                </Card>
                            )}

                            {preview?.purpose === 'ignore' && (
                                <Alert>
                                    <ShieldCheck />
                                    <AlertTitle>
                                        Known non-spending format
                                    </AlertTitle>
                                    <AlertDescription>
                                        This exact authenticated sender and
                                        marker combination will be ignored. It
                                        will create neither a Transaction nor
                                        review noise.
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div className="flex flex-wrap justify-end gap-3">
                                {preview === undefined ? (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={
                                            processing ||
                                            alignedAuthentication.length ===
                                                0 ||
                                            availableMimeParts.length === 0
                                        }
                                    >
                                        <ScanSearch />
                                        {processing
                                            ? 'Validating rules...'
                                            : formatPurpose === 'ignore'
                                              ? 'Preview ignored format'
                                              : 'Preview candidate Transaction'}
                                    </Button>
                                ) : (
                                    <Button type="submit" disabled={processing}>
                                        <ShieldCheck />
                                        {processing
                                            ? 'Saving format...'
                                            : preview.purpose === 'ignore'
                                              ? 'Save ignored format'
                                              : selectedProfileId === ''
                                                ? 'Enable profile and create Transaction'
                                                : selectedFormatId === ''
                                                  ? 'Add format and create Transaction'
                                                  : 'Replace validated format'}
                                    </Button>
                                )}
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function CandidateValue({
    label,
    value,
    provisional = false,
}: {
    label: string;
    value: string;
    provisional?: boolean;
}) {
    return (
        <div className="grid gap-1 rounded-lg border p-3">
            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span className="text-sm font-medium">{value}</span>
            {provisional && (
                <Badge variant="secondary">Needs review after creation</Badge>
            )}
        </div>
    );
}

function FormField({
    label,
    name,
    error,
    onChange,
    placeholder,
    defaultValue,
    disabled = false,
}: {
    label: string;
    name: string;
    error?: string;
    onChange?: (value: string) => void;
    placeholder?: string;
    defaultValue?: string;
    disabled?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <Input
                id={name}
                name={name}
                placeholder={placeholder}
                defaultValue={defaultValue}
                disabled={disabled}
                aria-invalid={error ? true : undefined}
                onChange={(event) => onChange?.(event.target.value)}
            />
            <InputError message={error} />
        </div>
    );
}

function BoundaryField({
    label,
    name,
    error,
    required = true,
}: {
    label: string;
    name: string;
    error?: string;
    required?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>
                {label}
                {!required && (
                    <span className="font-normal text-muted-foreground">
                        {' '}
                        (optional)
                    </span>
                )}
            </Label>
            <textarea
                id={name}
                name={name}
                className={inputClassName}
                aria-invalid={error ? true : undefined}
            />
            <InputError message={error} />
        </div>
    );
}

function SelectField({
    label,
    name,
    options,
    error,
    onChange,
}: {
    label: string;
    name: string;
    options: ReadonlyArray<{ value: string; label: string }>;
    error?: string;
    onChange?: (value: string) => void;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <NativeSelect
                id={name}
                name={name}
                options={options}
                aria-invalid={error ? true : undefined}
                onChange={(event) => onChange?.(event.target.value)}
            />
            <InputError message={error} />
        </div>
    );
}
