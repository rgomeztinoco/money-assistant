import { Form, Head, Link } from '@inertiajs/react';
import {
    FileSearch,
    Pencil,
    Power,
    PowerOff,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import {
    destroy as disableProfile,
    store as enableProfile,
} from '@/actions/App/Http/Controllers/ParserProfileActivationController';
import {
    destroy as deleteProfile,
    update as updateProfile,
} from '@/actions/App/Http/Controllers/ParserProfileController';
import { show as showSourceMessage } from '@/actions/App/Http/Controllers/ParserProfileSourceMessageController';
import {
    destroy as disableFormat,
    store as enableFormat,
} from '@/actions/App/Http/Controllers/SpendingNotificationFormatActivationController';
import { destroy as deleteFormat } from '@/actions/App/Http/Controllers/SpendingNotificationFormatController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { index } from '@/routes/parser_profiles';

type Format = {
    id: number;
    name: string;
    purpose: 'spending' | 'ignore';
    mime_source: 'text_plain' | 'text_html';
    enabled: boolean;
};

type Profile = {
    id: number;
    name: string;
    trusted_sender_address: string;
    authentication_mechanism: string;
    authenticated_domain: string;
    enabled: boolean;
    formats: Format[];
};

type SourceMessage = {
    id: number;
    received_at: string;
    from_address: string;
    subject: string;
};

function FormatRow({ profile, format }: { profile: Profile; format: Format }) {
    const activation = format.enabled ? disableFormat : enableFormat;

    return (
        <div className="grid gap-3 rounded-lg border bg-background p-3 sm:grid-cols-[1fr_auto] sm:items-center">
            <div className="grid gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium">{format.name}</span>
                    <Badge variant={format.enabled ? 'secondary' : 'outline'}>
                        {format.enabled ? 'Enabled' : 'Disabled'}
                    </Badge>
                </div>
                <span className="text-xs text-muted-foreground">
                    {format.purpose === 'spending'
                        ? 'Creates Transactions'
                        : 'Known non-spending message'}{' '}
                    ·{' '}
                    {format.mime_source === 'text_plain'
                        ? 'Plain text'
                        : 'HTML'}
                </span>
            </div>
            <div className="flex flex-wrap gap-2">
                <Form
                    {...activation.form([profile.id, format.id])}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={processing}
                        >
                            {format.enabled ? <PowerOff /> : <Power />}
                            {processing
                                ? 'Saving...'
                                : format.enabled
                                  ? 'Disable'
                                  : 'Enable'}
                        </Button>
                    )}
                </Form>
                <Form
                    {...deleteFormat.form([profile.id, format.id])}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            size="sm"
                            variant="destructive"
                            disabled={processing}
                        >
                            <Trash2 /> {processing ? 'Deleting...' : 'Delete'}
                        </Button>
                    )}
                </Form>
            </div>
        </div>
    );
}

function ProfileCard({ profile }: { profile: Profile }) {
    const activation = profile.enabled ? disableProfile : enableProfile;

    return (
        <Card>
            <CardHeader className="gap-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="grid gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <CardTitle>{profile.name}</CardTitle>
                            <Badge
                                variant={
                                    profile.enabled ? 'secondary' : 'outline'
                                }
                            >
                                {profile.enabled ? 'Enabled' : 'Disabled'}
                            </Badge>
                        </div>
                        <CardDescription>
                            {profile.trusted_sender_address} ·{' '}
                            {profile.authentication_mechanism.toUpperCase()} for{' '}
                            {profile.authenticated_domain}
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Form
                            {...activation.form(profile.id)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    {profile.enabled ? <PowerOff /> : <Power />}
                                    {processing
                                        ? 'Saving...'
                                        : profile.enabled
                                          ? 'Disable profile'
                                          : 'Enable profile'}
                                </Button>
                            )}
                        </Form>
                        <Form
                            {...deleteProfile.form(profile.id)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    <Trash2 />
                                    {processing
                                        ? 'Deleting...'
                                        : 'Delete profile'}
                                </Button>
                            )}
                        </Form>
                    </div>
                </div>
                <Form
                    {...updateProfile.form(profile.id)}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-2 sm:flex-row"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grow">
                                <Input
                                    name="name"
                                    defaultValue={profile.name}
                                    aria-label={`Rename ${profile.name}`}
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                />
                                <InputError message={errors.name} />
                            </div>
                            <Button
                                type="submit"
                                variant="secondary"
                                disabled={processing}
                            >
                                <Pencil />
                                Rename
                            </Button>
                        </>
                    )}
                </Form>
            </CardHeader>
            <CardContent className="grid gap-3">
                {profile.formats.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        This profile has no current formats.
                    </p>
                ) : (
                    profile.formats.map((format) => (
                        <FormatRow
                            key={format.id}
                            profile={profile}
                            format={format}
                        />
                    ))
                )}
            </CardContent>
        </Card>
    );
}

export default function ParserProfilesIndex({
    profiles,
    source_messages: sourceMessages,
}: {
    profiles: Profile[];
    source_messages: SourceMessage[];
}) {
    return (
        <>
            <Head title="Parser Profiles" />

            <div className="flex flex-col gap-6">
                <Heading
                    title="Parser Profiles"
                    description="Manage the current sender trust and deterministic formats used for Gmail imports."
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Validate a format from Gmail</CardTitle>
                        <CardDescription>
                            Choose a message to create a profile or add a
                            format. Subject, body, and MIME content are fetched
                            only for this validation.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        {sourceMessages.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No unprocessed Gmail messages are available for
                                validation.
                            </p>
                        ) : (
                            sourceMessages.map((source) => (
                                <div
                                    key={source.id}
                                    className="flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">
                                            {source.subject}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {source.from_address} ·{' '}
                                            {source.received_at.slice(0, 10)}
                                        </p>
                                    </div>
                                    <Button asChild size="sm">
                                        <Link
                                            href={showSourceMessage(source.id)}
                                        >
                                            <FileSearch /> Validate format
                                        </Link>
                                    </Button>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                <section
                    className="grid gap-4"
                    aria-labelledby="current-profiles"
                >
                    <div>
                        <h2
                            id="current-profiles"
                            className="text-lg font-semibold"
                        >
                            Current profiles
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Enabled definitions are evaluated deterministically
                            in creation order.
                        </p>
                    </div>
                    {profiles.length === 0 ? (
                        <Card>
                            <CardContent className="flex items-center gap-3 py-6 text-sm text-muted-foreground">
                                <ShieldCheck className="size-5" /> No Parser
                                Profiles have been created.
                            </CardContent>
                        </Card>
                    ) : (
                        profiles.map((profile) => (
                            <ProfileCard key={profile.id} profile={profile} />
                        ))
                    )}
                </section>
            </div>
        </>
    );
}

ParserProfilesIndex.layout = {
    breadcrumbs: [{ title: 'Parser Profiles', href: index() }],
};
