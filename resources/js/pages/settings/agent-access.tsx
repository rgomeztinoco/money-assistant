import type { HttpResponse } from '@inertiajs/core';
import { Head, router, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import AgentAccessController from '@/actions/App/Http/Controllers/Settings/AgentAccessController';
import { RelativeTimestamp } from '@/components/date-time';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

type Token = {
    id: number;
    name: string;
    created_at: string;
    last_used_at: string | null;
};
type IssuedToken = {
    token: Pick<Token, 'id' | 'name'>;
    plain_text_token: string;
};
type Props = { tokens: Token[]; endpoint: string };

function CopyButton({ text, label }: { text: string; label: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={async () => {
                try {
                    await navigator.clipboard.writeText(text);
                    setCopied(true);
                } catch {
                    toast.error(
                        'Copy failed. Select and copy the text manually.',
                    );
                }
            }}
        >
            {copied ? 'Copied' : label}
        </Button>
    );
}

function SetupInstructions({ endpoint }: { endpoint: string }) {
    const codex = `[mcp_servers.money_assistant]\nurl = ${JSON.stringify(endpoint)}\nbearer_token_env_var = "MONEY_ASSISTANT_TOKEN"`;
    const openclaw = JSON.stringify(
        {
            mcp: {
                servers: {
                    money_assistant: {
                        transport: 'streamable-http',
                        url: endpoint,
                        headers: { Authorization: 'Bearer <token>' },
                    },
                },
            },
        },
        null,
        2,
    );
    const generic = `${endpoint}\nAuthorization: Bearer <token>`;

    return (
        <Tabs defaultValue="codex" className="flex-col">
            <TabsList aria-label="Client setup" className="h-9 shrink-0">
                <TabsTrigger value="codex">Codex</TabsTrigger>
                <TabsTrigger value="openclaw">OpenClaw</TabsTrigger>
                <TabsTrigger value="generic">Generic MCP</TabsTrigger>
            </TabsList>
            <TabsContent value="codex" className="flex flex-col gap-3">
                <p>
                    Add this to your Codex config.toml. Set
                    MONEY_ASSISTANT_TOKEN in the environment that starts Codex
                    to the token above.
                </p>
                <pre className="overflow-x-auto rounded-md bg-muted p-3 text-xs">
                    {codex}
                </pre>
                <CopyButton text={codex} label="Copy Codex config" />
            </TabsContent>
            <TabsContent value="openclaw" className="flex flex-col gap-3">
                <p>
                    Add this remote server to your OpenClaw configuration.
                    Replace &lt;token&gt; with the token above.
                </p>
                <pre className="overflow-x-auto rounded-md bg-muted p-3 text-xs">
                    {openclaw}
                </pre>
                <p className="text-muted-foreground">
                    A literal Authorization header stores the secret in your
                    owner-local OpenClaw configuration. It must not be
                    committed.
                </p>
                <CopyButton text={openclaw} label="Copy OpenClaw config" />
            </TabsContent>
            <TabsContent value="generic" className="flex flex-col gap-3">
                <p>
                    Use Streamable HTTP. Send your bearer token with every
                    request.
                </p>
                <pre className="overflow-x-auto rounded-md bg-muted p-3 text-xs">
                    {generic}
                </pre>
                <CopyButton text={generic} label="Copy connection details" />
            </TabsContent>
        </Tabs>
    );
}

export default function AgentAccess({ tokens, endpoint }: Props) {
    const create = useHttp<{ name: string }, IssuedToken>({ name: '' });
    const mutation = useHttp<Record<string, never>, IssuedToken>({});
    const [issued, setIssued] = useState<IssuedToken | null>(null);
    const [confirmation, setConfirmation] = useState<{
        action: 'rotate' | 'revoke';
        token: Token;
    } | null>(null);
    const [error, setError] = useState<string | null>(null);

    const onHttpException = (response: HttpResponse) => {
        if (response.status === 423) {
            router.visit(AgentAccessController.index());
        } else {
            setError('The token could not be changed. Please try again.');
        }

        return false;
    };
    const onNetworkError = () => {
        setError('Connection lost. Check your connection and try again.');
        return false;
    };
    const showToken = (response: IssuedToken) => {
        setIssued(response);
        create.reset();
        setConfirmation(null);
        router.reload({ only: ['tokens'] });
    };
    const mutate = async () => {
        if (!confirmation) {
            return;
        }

        setError(null);

        try {
            if (confirmation.action === 'rotate') {
                await mutation.post(
                    AgentAccessController.rotate.url(confirmation.token.id),
                    { onSuccess: showToken, onHttpException },
                );
            } else {
                await mutation.delete(
                    AgentAccessController.destroy.url(confirmation.token.id),
                    {
                        onSuccess: () => {
                            setConfirmation(null);
                            router.reload({ only: ['tokens'] });
                        },
                        onHttpException,
                        onNetworkError,
                    },
                );
            }
        } catch {
            // The form displays validation and HTTP errors; failed mutations are never retried.
        }
    };

    return (
        <>
            <Head title="Agent access" />
            <div className="flex flex-col gap-6">
                <Heading
                    variant="small"
                    title="Agent access"
                    description="Let your agents read Transactions and Categories. Each token has read-only access and stays active until you revoke it."
                />
                <form
                    className="flex flex-col gap-3"
                    onSubmit={async (event) => {
                        event.preventDefault();
                        setError(null);

                        try {
                            await create.post(
                                AgentAccessController.store.url(),
                                { onSuccess: showToken, onHttpException },
                            );
                        } catch {
                            // Validation errors are rendered below the input.
                        }
                    }}
                >
                    <Label htmlFor="token-name">Token name</Label>
                    <Input
                        id="token-name"
                        value={create.data.name}
                        onChange={(event) =>
                            create.setData('name', event.target.value)
                        }
                        maxLength={100}
                        required
                        placeholder="e.g. Codex on my laptop"
                        aria-invalid={Boolean(create.errors.name)}
                    />
                    <InputError message={create.errors.name} />
                    <Button className="self-start" disabled={create.processing}>
                        Create token
                    </Button>
                </form>
                {error && <InputError message={error} />}
                <div className="divide-y rounded-lg border">
                    {tokens.length === 0 ? (
                        <p className="p-6 text-center text-sm text-muted-foreground">
                            No agent tokens yet
                        </p>
                    ) : (
                        tokens.map((token) => (
                            <div
                                key={token.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-4"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium break-words">
                                        {token.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Created{' '}
                                        <RelativeTimestamp
                                            value={token.created_at}
                                        />{' '}
                                        ·{' '}
                                        {token.last_used_at ? (
                                            <>
                                                Last used{' '}
                                                <RelativeTimestamp
                                                    value={token.last_used_at}
                                                />
                                            </>
                                        ) : (
                                            'Never used'
                                        )}
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setConfirmation({
                                                action: 'rotate',
                                                token,
                                            })
                                        }
                                    >
                                        Rotate
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        onClick={() =>
                                            setConfirmation({
                                                action: 'revoke',
                                                token,
                                            })
                                        }
                                    >
                                        Revoke
                                    </Button>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>
            <Dialog
                open={issued !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setIssued(null);
                    }
                }}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Copy your token now</DialogTitle>
                        <DialogDescription>
                            {issued?.token.name}. This secret is shown only
                            once. If you lose it, rotate the token to get a
                            replacement.
                        </DialogDescription>
                    </DialogHeader>
                    {issued && (
                        <>
                            <Label htmlFor="issued-token">Bearer token</Label>
                            <Input
                                id="issued-token"
                                readOnly
                                value={issued.plain_text_token}
                                autoComplete="off"
                                onFocus={(event) => event.target.select()}
                            />
                            <CopyButton
                                key={issued.plain_text_token}
                                text={issued.plain_text_token}
                                label="Copy token"
                            />
                            <SetupInstructions endpoint={endpoint} />
                        </>
                    )}
                    <DialogFooter>
                        <Button onClick={() => setIssued(null)}>Done</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog
                open={confirmation !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setConfirmation(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {confirmation?.action === 'rotate'
                                ? 'Rotate'
                                : 'Revoke'}{' '}
                            {confirmation?.token.name}
                        </DialogTitle>
                        <DialogDescription>
                            {confirmation?.action === 'rotate'
                                ? 'The old secret will stop working immediately. Update your agent with the replacement secret.'
                                : 'This agent will lose access immediately. You can create a new token later.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={mutation.processing}
                            onClick={() => setConfirmation(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant={
                                confirmation?.action === 'revoke'
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={mutation.processing}
                            onClick={mutate}
                        >
                            {confirmation?.action === 'rotate'
                                ? 'Rotate token'
                                : 'Revoke token'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

AgentAccess.layout = {
    breadcrumbs: [
        { title: 'Agent access', href: AgentAccessController.index() },
    ],
};
