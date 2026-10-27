<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses;

/** @internal */
final class OpaqueIdentifier
{
    /** PCRE's Unicode \s includes U+0085 and excludes U+FEFF, unlike ECMAScript's \s. */
    public const string PATTERN = '/^(?![\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}])[^\x{0000}-\x{001F}\x{007F}]+(?<![\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}])$/uD';

    private function __construct() {}
}
