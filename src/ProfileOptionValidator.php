<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Jkudish\LaravelAiLibrarium\Contracts\ValidatesProfileOptions;

final readonly class ProfileOptionValidator
{
    /** @var list<string> */
    private const array FORBIDDEN_KEY_PARTS = [
        'apikey',
        'authorization',
        'credential',
        'password',
        'secret',
        'privatekey',
        'accesstoken',
        'authtoken',
        'bearertoken',
        'refreshtoken',
        'sessiontoken',
    ];

    /** @var list<string> */
    private const array ALLOWED_TOKEN_KEYS = [
        'maxtokens',
        'mintokens',
        'inputtokens',
        'outputtokens',
        'prompttokens',
        'completiontokens',
        'reasoningtokens',
        'cachereadtokens',
        'cachewritetokens',
        'totaltokens',
        'tokencount',
        'tokenbudget',
        'tokenlimit',
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
        if (! is_subclass_of($driver, ValidatesProfileOptions::class)) {
            return ["Driver [{$driver}] must implement ValidatesProfileOptions."];
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
                $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
                if (str_contains($normalized, 'token') && ! in_array($normalized, self::ALLOWED_TOKEN_KEYS, true)) {
                    return true;
                }

                foreach (self::FORBIDDEN_KEY_PARTS as $forbidden) {
                    if (str_contains($normalized, $forbidden)) {
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
