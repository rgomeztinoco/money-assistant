import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';

export function DebtAllocationFields({
    principal,
    interest,
    onPrincipal,
    onInterest,
    errors,
    allowNewCharge,
    newCharge,
    onNewCharge,
}: {
    principal: string;
    interest: string;
    onPrincipal: (value: string) => void;
    onInterest: (value: string) => void;
    errors: Record<string, string | undefined>;
    allowNewCharge: boolean;
    newCharge?: boolean;
    onNewCharge?: (value: boolean) => void;
}) {
    return (
        <>
            <Field
                data-invalid={Boolean(
                    errors.principal || errors.principal_minor,
                )}
            >
                <FieldLabel htmlFor="debt-principal">Principal</FieldLabel>
                <Input
                    id="debt-principal"
                    name="principal"
                    inputMode="decimal"
                    value={principal}
                    onChange={(event) => onPrincipal(event.target.value)}
                    aria-invalid={Boolean(
                        errors.principal || errors.principal_minor,
                    )}
                    required
                />
                <FieldError>
                    {errors.principal || errors.principal_minor}
                </FieldError>
            </Field>
            <Field
                data-invalid={Boolean(errors.interest || errors.interest_minor)}
            >
                <FieldLabel htmlFor="debt-interest">Interest</FieldLabel>
                <Input
                    id="debt-interest"
                    name="interest"
                    inputMode="decimal"
                    value={interest}
                    onChange={(event) => onInterest(event.target.value)}
                    aria-invalid={Boolean(
                        errors.interest || errors.interest_minor,
                    )}
                    required
                />
                <FieldDescription>
                    Principal plus interest must equal the full posted amount.
                    Only interest affects spending or income.
                </FieldDescription>
                <FieldError>
                    {errors.interest || errors.interest_minor}
                </FieldError>
            </Field>
            {allowNewCharge && (
                <Field data-invalid={Boolean(errors.interest_is_new)}>
                    <FieldLabel htmlFor="debt-interest-charge">
                        Interest in the balance
                    </FieldLabel>
                    <NativeSelect
                        id="debt-interest-charge"
                        name="interest_is_new"
                        {...(newCharge === undefined
                            ? { defaultValue: '0' }
                            : { value: newCharge ? '1' : '0' })}
                        onChange={(event) =>
                            onNewCharge?.(event.target.value === '1')
                        }
                        aria-invalid={Boolean(errors.interest_is_new)}
                        options={[
                            {
                                value: '0',
                                label: 'Already included in the recorded balance',
                            },
                            {
                                value: '1',
                                label: 'Confirm a new charge with this payment',
                            },
                        ]}
                    />
                    <FieldDescription>
                        A new charge remains in history if this payment is later
                        changed or voided. Correct charges with a dated balance
                        adjustment.
                    </FieldDescription>
                    <FieldError>{errors.interest_is_new}</FieldError>
                </Field>
            )}
        </>
    );
}
