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
            auth: Auth;
            navigation: {
                review_queue_count: number;
            };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
