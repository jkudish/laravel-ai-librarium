<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;

final readonly class ProfileOptionValidator
{
    /** @var list<string> */
    private const array FORBIDDEN_KEY_WORDS = [
        'auth',
        'authorization',
        'bearer',
        'cookie',
        'cookies',
        'credential',
        'credentials',
        'dsn',
        'password',
        'passwords',
        'secret',
        'secrets',
    ];

    /** @var list<string> */
    private const array FORBIDDEN_COMPACT_KEY_PARTS = [
        'apikey',
        'privatekey',
        'accesstoken',
        'authtoken',
        'bearertoken',
        'refreshtoken',
        'sessiontoken',
        'signedurl',
        'presignedurl',
        'connectionstring',
    ];

    /** @var list<string> */
    private const array SAFE_TOKEN_WORDS = [
        'budget',
        'cache',
        'completion',
        'count',
        'input',
        'limit',
        'max',
        'min',
        'output',
        'prompt',
        'read',
        'reasoning',
        'return',
        'token',
        'tokens',
        'total',
        'write',
    ];

    public function __construct(
        private ValidationFactory $validator,
        private ConfigRepository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function errors(string $driver, array $options): array
    {
        if (! is_subclass_of($driver, Driver::class)) {
            return ["Driver [{$driver}] must implement Driver."];
        }

        $errors = [];

        if ($this->containsCredentialKey($options)) {
            $errors[] = 'Profile options may not contain credentials or secrets.';
        }

        if ($this->containsObject($options)) {
            $errors[] = 'Profile options may contain only arrays and JSON scalar values.';
        }

        $encoded = json_encode($options);
        $configuredMaxBytes = $this->config->get('librarium.profile_options_max_bytes', 16384);
        $maxBytes = is_int($configuredMaxBytes) && $configuredMaxBytes > 0 ? $configuredMaxBytes : 16384;
        if ($encoded === false || strlen($encoded) > $maxBytes) {
            $errors[] = "Profile options must be valid JSON and no larger than {$maxBytes} bytes.";
        }

        $rules = $driver::profileOptionRules();
        $allowed = [];
        $prefixedRules = [];
        foreach ($rules as $key => $rule) {
            $allowed[] = str($key)->before('.')->toString();
            $prefixedRules['options.'.$key] = $rule;
        }

        $allowed = array_values(array_unique($allowed));
        $rootRules = $allowed === [] ? ['array', 'max:0'] : ['array:'.implode(',', $allowed)];
        $validator = $this->validator->make(
            ['options' => $options],
            ['options' => $rootRules, ...$prefixedRules],
        );

        if ($validator->fails()) {
            $errors[] = $validator->errors()->first();
        }

        return $errors;
    }

    /** @param array<array-key, mixed> $options */
    private function containsCredentialKey(array $options): bool
    {
        foreach ($options as $key => $value) {
            if (is_string($key)) {
                $words = $this->keyWords($key);
                $compact = implode('', $words);

                if (str_contains($compact, 'token') && ! $this->isSafeTokenControl($words)) {
                    return true;
                }

                if (array_intersect($words, self::FORBIDDEN_KEY_WORDS) !== []) {
                    return true;
                }

                foreach (self::FORBIDDEN_COMPACT_KEY_PARTS as $forbidden) {
                    if (str_contains($compact, $forbidden)) {
                        return true;
                    }
                }
            }

            if (is_array($value) && $this->containsCredentialKey($value)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function keyWords(string $key): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::snake($key), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? $words : [];
    }

    /** @param list<string> $words */
    private function isSafeTokenControl(array $words): bool
    {
        $qualifiers = array_values(array_diff($words, ['token', 'tokens']));

        return $qualifiers !== []
            && array_diff($words, self::SAFE_TOKEN_WORDS) === [];
    }

    /** @param array<array-key, mixed> $options */
    private function containsObject(array $options): bool
    {
        foreach ($options as $value) {
            if (is_object($value) || is_resource($value)) {
                return true;
            }

            if (is_array($value) && $this->containsObject($value)) {
                return true;
            }
        }

        return false;
    }
}
