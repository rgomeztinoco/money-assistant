import { Head } from '@inertiajs/react';
import { BarChart3, Target } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index } from '@/routes/insights';

export default function InsightsIndex() {
    return (
        <>
            <Head title="Insights" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Insights
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Factual spending comparisons and owner-approved Category
                        Targets live here as enough history becomes available.
                    </p>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <BarChart3 className="size-5 text-muted-foreground" />
                            <CardTitle>Spending Insights</CardTitle>
                            <CardDescription>
                                Comparisons will appear when enough reviewed
                                spending establishes a factual baseline.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            Current-period spending remains available on the
                            Dashboard while this history is built.
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <Target className="size-5 text-muted-foreground" />
                            <CardTitle>Category Targets</CardTitle>
                            <CardDescription>
                                No active Category Targets yet.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            Targets will be explicit monthly intentions, never
                            forecasts or inferred reductions.
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

InsightsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Insights',
            href: index(),
        },
    ],
};
