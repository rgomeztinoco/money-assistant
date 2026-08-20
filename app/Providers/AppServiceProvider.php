<?php

namespace App\Providers;

use App\Actions\Ledger\CountOutstandingReviews;
use App\Contracts\Gmail;
use App\Contracts\StatementPdfExtractor;
use App\Integrations\Gmail\GoogleGmail;
use App\StatementImports\ProcessStatementPdfExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            Gmail::class,
            fn (): GoogleGmail => new GoogleGmail(
                clientId: (string) config('services.gmail.client_id'),
                clientSecret: (string) config('services.gmail.client_secret'),
                redirectUri: (string) config('services.gmail.redirect_uri'),
            ),
        );

        $this->app->scoped(CountOutstandingReviews::class);

        $this->app->bind(StatementPdfExtractor::class, ProcessStatementPdfExtractor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        RateLimiter::for(
            'gmail-message-processing',
            fn (): Limit => Limit::perMinute(120),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
