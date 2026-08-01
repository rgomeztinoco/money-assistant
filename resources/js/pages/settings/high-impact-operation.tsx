import { Form, Head } from '@inertiajs/react';
import { Clock3, Download, ShieldAlert, Trash2 } from 'lucide-react';
import HighImpactOperationController from '@/actions/App/Http/Controllers/Settings/HighImpactOperationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

type HighImpactOperation = {
    id: string;
    kind: 'financial_export' | 'financial_deletion';
    effect_summary: string;
    expires_at: string;
    expected_revision: number;
    payload_digest: string;
    status: 'pending' | 'expired' | 'canceled' | 'completed';
};

export default function HighImpactOperationPage({
    operation,
}: {
    operation: HighImpactOperation;
}) {
    const isExport = operation.kind === 'financial_export';
    const isPending = operation.status === 'pending';

    return (
        <>
            <Head title="Protected operation" />

            <div className="space-y-6">
                <Heading
                    title={
                        isExport
                            ? 'Download financial data'
                            : 'Approve deletion'
                    }
                    description="Review the exact operation prepared from OpenClaw before continuing."
                />

                <Alert variant={isPending ? 'default' : 'destructive'}>
                    {isPending ? (
                        <ShieldAlert className="size-4" />
                    ) : (
                        <Clock3 className="size-4" />
                    )}
                    <AlertTitle>
                        {isPending
                            ? 'Fresh passkey required'
                            : 'Approval unavailable'}
                    </AlertTitle>
                    <AlertDescription>
                        {isPending
                            ? operation.effect_summary
                            : `This preparation is ${operation.status} and cannot be used.`}
                    </AlertDescription>
                </Alert>

                {isPending && (
                    <Form
                        action={HighImpactOperationController.complete({
                            operationId: operation.id,
                        })}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="expected_revision"
                                    value={operation.expected_revision}
                                />
                                <input
                                    type="hidden"
                                    name="payload_digest"
                                    value={operation.payload_digest}
                                />

                                <InputError
                                    message={
                                        errors.operation ??
                                        errors.expected_revision ??
                                        errors.payload_digest
                                    }
                                />

                                <Button
                                    type="submit"
                                    variant={
                                        isExport ? 'default' : 'destructive'
                                    }
                                    disabled={processing}
                                    className="gap-2"
                                >
                                    {isExport ? (
                                        <Download className="size-4" />
                                    ) : (
                                        <Trash2 className="size-4" />
                                    )}
                                    {isExport
                                        ? 'Confirm and download export'
                                        : 'Confirm deletion'}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}
