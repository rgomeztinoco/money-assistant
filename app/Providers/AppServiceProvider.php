<?php

namespace App\Providers;

use App\Contracts\AiClassifier;
use App\Contracts\BcrpData;
use App\Contracts\Gmail;
use App\Contracts\OpenClawHook;
use App\Integrations\Ai\HttpAiClassifier;
use App\Integrations\BcrpData\HttpBcrpData;
use App\Integrations\Gmail\GoogleGmail;
use App\Integrations\OpenClaw\HttpOpenClawHook;
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
        $this->app->singleton(BcrpData::class, HttpBcrpData::class);

        $this->app->singleton(
            AiClassifier::class,
            fn (): HttpAiClassifier => new HttpAiClassifier(
                url: (string) config('services.ai_classifier.url'),
                token: (string) config('services.ai_classifier.token'),
                classifierVersion: (string) config('services.ai_classifier.version'),
            ),
        );

        $this->app->singleton(
            Gmail::class,
            fn (): GoogleGmail => new GoogleGmail(
                clientId: (string) config('services.gmail.client_id'),
                clientSecret: (string) config('services.gmail.client_secret'),
                redirectUri: (string) config('services.gmail.redirect_uri'),
            ),
        );

        $this->app->singleton(
            OpenClawHook::class,
            fn (): HttpOpenClawHook => new HttpOpenClawHook(
                url: (string) config('services.openclaw.hook.url'),
                token: (string) config('services.openclaw.hook.token'),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        RateLimiter::for(
            'openclaw-ingress',
            fn (): Limit => Limit::perMinute(120),
        );

        RateLimiter::for(
            'ai-classifier',
            fn (): Limit => Limit::perMinute(30),
        );

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
