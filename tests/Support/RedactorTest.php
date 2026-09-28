<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\Support\Redactor;

it('blanks known secrets, raw and url-encoded', function (): void {
    $message = 'failed for https://x.test/?k=a+b%2Fc and header a b/c';

    expect(Redactor::redact($message, ['a b/c', null, '']))
        ->not->toContain('a+b%2Fc')
        ->not->toContain('a b/c')
        ->toContain('[redacted]');
});

it('blanks credential query parameters even when the value is unknown', function (): void {
    $message = 'cURL error 28 for https://download.test/app?edition_id=X&license_key=SECRET&suffix=tar.gz and ?key=K2&token=T3';

    expect(Redactor::redact($message))
        ->toBe('cURL error 28 for https://download.test/app?edition_id=X&license_key=[redacted]&suffix=tar.gz and ?key=[redacted]&token=[redacted]');
});
