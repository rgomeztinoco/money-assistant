import { Head, Link } from '@inertiajs/react';
import { FileSearch, ShieldCheck } from 'lucide-react';
import { show as showSourceMessage } from '@/actions/App/Http/Controllers/ParserProfileSourceMessageController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index } from '@/routes/parser_profiles';

type Profile = {
    id: number;
    name: string;
    current_version: number;
    enabled_at: string | null;
};

type SourceMessage = {
    id: number;
    message_id: string;
    received_at: string;
    from_address: string;
    subject: string;
};

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
                    description="Create deterministic Spending Notification support from messages already discovered in Gmail."
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Enabled profiles</CardTitle>
                            <CardDescription>
                                Each version keeps an explicit sender,
                                authentication, and extraction boundary.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {profiles.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No Parser Profiles have been created.
                                </p>
                            ) : (
                                profiles.map((profile) => (
                                    <div
                                        key={profile.id}
                                        className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                    >
                                        <div className="grid gap-1">
                                            <span className="font-medium">
                                                {profile.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                Version{' '}
                                                {profile.current_version}
                                            </span>
                                        </div>
                                        <Badge>
                                            <ShieldCheck />
                                            Enabled
                                        </Badge>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Choose a source message</CardTitle>
                            <CardDescription>
                                Message content is fetched from Gmail only while
                                you review and confirm a profile.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {sourceMessages.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No unprocessed Gmail messages are available.
                                </p>
                            ) : (
                                sourceMessages.map((source) => (
                                    <div
                                        key={source.id}
                                        className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">
                                                {source.subject}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {source.from_address}
                                            </p>
                                        </div>
                                        <Button asChild size="sm">
                                            <Link
                                                href={showSourceMessage(
                                                    source.id,
                                                )}
                                            >
                                                <FileSearch />
                                                Review
                                            </Link>
                                        </Button>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

ParserProfilesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Parser Profiles',
            href: index(),
        },
    ],
};
