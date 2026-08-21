import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { PRODUCT_NAME } from '@/lib/product';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-5">
                        <Link
                            href={home()}
                            className="flex items-center gap-3 rounded-lg font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-4 focus-visible:outline-none"
                        >
                            <div className="flex size-10 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm">
                                <AppLogoIcon className="size-6 fill-current" />
                            </div>
                            <div className="grid text-left leading-tight">
                                <span className="font-semibold">
                                    {PRODUCT_NAME}
                                </span>
                                <span className="text-xs font-normal text-muted-foreground">
                                    Personal spending workspace
                                </span>
                            </div>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
