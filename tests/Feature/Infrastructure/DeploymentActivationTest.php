<?php

test('a candidate admits only its health probe before deployment activation', function (): void {
    $activationMarker = storage_path('framework/testing-deployment-activated');

    @unlink($activationMarker);
    config()->set('app.deployment_requests_enabled', false);
    config()->set('app.deployment_activation_marker', $activationMarker);

    $this->get('/up')->assertSuccessful();
    $this->get('/')->assertServiceUnavailable();
    $this->post('/login')->assertServiceUnavailable();

    touch($activationMarker);

    try {
        $this->get('/')->assertSuccessful();
    } finally {
        @unlink($activationMarker);
    }
});

test('an active release admits application requests', function (): void {
    config()->set('app.deployment_requests_enabled', true);

    $this->get('/')->assertSuccessful();
});
