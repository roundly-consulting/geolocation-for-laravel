<?php

declare(strict_types=1);

/**
 * The secret-safe `about` capture (A).
 *
 * Purchases #13 is the bug this exists for: the fleet's most credential-heavy `about`
 * section was guarded by negative assertions against `app(Kernel::class)->output()`,
 * which returns `''` — every "does not leak" check was vacuous.
 *
 * Geolocation holds a Google Maps API key, a MaxMind license key, an IPinfo token and
 * an IP2Location key. The section is careful today — it renders the pipeline and two
 * switches, and no credential — and that restraint is exactly what is worth pinning:
 * the tempting next line ('Google' => $key ? 'key: '.$key : 'OFF') is the one that ends
 * up in a pasted support ticket.
 */
it('renders the pipeline without disclosing a provider credential', function (): void {
    config()->set('geolocation.services.google.key', 'AIzaSyD-google-maps-live-key');
    config()->set('geolocation.services.ipinfo.token', 'ipinfo-live-token-value');
    config()->set('geolocation.services.ip2location.key', 'ip2location-live-key');
    config()->set('geolocation.services.maxmind.database.license_key', 'maxmind-license-key-value');
    config()->set('geolocation.services.maxmind.web.account_id', '123456');
    config()->set('geolocation.services.maxmind.web.license_key', 'maxmind-web-license-key');
    config()->set('geolocation.cache.enabled', true);
    config()->set('geolocation.events.enabled', true);
    config()->set('geolocation.pipeline', ['ipinfo', 'google']);

    expect('geolocation')->toLeakNoSecrets(
        secrets: [
            // Every credential a host configures for a lookup provider.
            'AIzaSyD-google-maps-live-key',
            'ipinfo-live-token-value',
            'ip2location-live-key',
            'maxmind-license-key-value',
            'maxmind-web-license-key',
        ],
        mustRender: [
            'Pipeline',
            'Cache',
            'Events',
            // The positive proof the pipeline line is REPORTING rather than silently
            // empty: the two configured providers must be named. Without this, a
            // section that rendered nothing would satisfy the leak half trivially.
            'ipinfo, google',
            'ENABLED',
        ],
    );
});

/**
 * The counterpart: an empty pipeline must say NONE rather than render a blank line. A
 * line that goes blank when nothing is configured is indistinguishable from one that
 * broke — and it is the state a host most needs `about` to be legible in.
 */
it('reports NONE rather than an empty line when the pipeline is empty', function (): void {
    config()->set('geolocation.pipeline', []);
    config()->set('geolocation.cache.enabled', false);
    config()->set('geolocation.events.enabled', false);

    expect('geolocation')->toLeakNoSecrets(
        secrets: ['AIzaSyD-google-maps-live-key'],
        mustRender: ['Pipeline', 'NONE', 'Cache', 'OFF'],
    );
});
