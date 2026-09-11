<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Drivers\EvidenceUrl;

it('accepts bounded HTTP evidence URLs without credential-bearing parameters', function (string $url): void {
    expect(EvidenceUrl::sanitize($url))->toBe($url);
})->with([
    'HTTPS URL' => 'https://example.com/path?utm_source=private&search=security+token#section',
    'HTTP URL' => 'http://example.org/result?monkey=capuchin&secretary=name',
]);

it('rejects malformed and authority-bearing evidence URLs', function (mixed $url): void {
    expect(EvidenceUrl::sanitize($url))->toBeNull();
})->with([
    'not a string' => 42,
    'empty' => '',
    'surrounding whitespace' => ' https://example.com',
    'relative' => '/result',
    'unsafe scheme' => 'javascript:alert(1)',
    'userinfo' => 'https://user:password@example.com/private',
    'over limit' => fn (): string => 'https://example.com/'.str_repeat('a', 8192),
]);

it('rejects credential-bearing URL material across provider encodings', function (string $url): void {
    expect(EvidenceUrl::sanitize($url))->toBeNull();
})->with([
    'Google signature' => 'https://example.com/file?X-Goog-Signature=secret',
    'camel-case API key' => 'https://example.com/file?apiKey=secret',
    'AWS access key' => 'https://example.com/file?AWSAccessKeyId=secret',
    'nested token key' => 'https://example.com/file?redirect%5Btoken%5D=secret',
    'repeated key encoding' => 'https://example.com/file?api%254Bey=secret',
    'bare signature' => 'https://example.com/file?sig=secret',
    'fragment token' => 'https://example.com/file#access_token=secret',
    'nested signed URL' => 'https://example.com/redirect?url=https%3A%2F%2Fstorage.example%2Ffile%3FapiKey%3Dsecret',
    'repeatedly encoded nested URL' => 'https://example.com/redirect?url=https%253A%252F%252Fstorage.example%252Ffile%253Ftoken%253Dsecret',
    'path-encoded signed URL' => 'https://example.com/redirect/https%3A%2F%2Fstorage.example%2Ffile%3Ftoken%3Dsecret',
    'leading-space nested URL' => 'https://example.com/redirect?url=+https%3A%2F%2Fstorage.example%2Ffile%3Ftoken%3Dsecret',
    'protocol-relative nested URL' => 'https://example.com/redirect?url=%2F%2Fstorage.example%2Ffile%3FapiKey%3Dsecret',
    'relative nested URL' => 'https://example.com/redirect?url=%2Ffile%3Ftoken%3Dsecret',
    'bare encoded assignment' => 'https://example.com/redirect?url=token%3Dsecret',
    'password key' => 'https://example.com/file?password=secret',
    'authorization key' => 'https://example.com/file?authorization=secret',
    'session key' => 'https://example.com/file?session_id=secret',
    'over-encoded key' => 'https://example.com/file?api%2525254Bey=secret',
    'over-nested URL' => 'https://example.com/?url=https%3A%2F%2Fa.example%2F%3Furl%3Dhttps%253A%252F%252Fb.example%252F%253Furl%253Dhttps%25253A%25252F%25252Fc.example%25252F%25253Furl%25253Dhttps%2525253A%2525252F%2525252Fd.example%2525252F%2525253Ftoken%2525253Dsecret',
]);
