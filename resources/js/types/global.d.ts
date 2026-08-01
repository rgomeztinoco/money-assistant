import type { Auth } from '@/types/auth';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            review_queue: {
                outstanding_count: number;
            };
            openclaw: {
                state: 'configured' | 'unavailable';
                launcher_url: string | null;
            };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
