import { Head } from '@inertiajs/react';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import PasskeyVerify from '@/components/passkey-verify';

export default function ConfirmPasskey() {
    return (
        <>
            <Head title="Confirm with a passkey" />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label="Confirm with passkey"
                loadingLabel="Confirming..."
                showSeparator={false}
            />
        </>
    );
}

ConfirmPasskey.layout = {
    title: 'Confirm with a passkey',
    description:
        'This action permanently changes protected data. Use a passkey to continue.',
};
