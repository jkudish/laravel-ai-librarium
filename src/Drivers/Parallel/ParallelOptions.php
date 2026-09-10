<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Parallel;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;

/** @internal */
final readonly class ParallelOptions
{
    /** @var list<string> */
    private const array LOCATIONS = [
        'ar', 'at', 'au', 'be', 'br', 'ca', 'ch', 'cl', 'cn', 'de', 'dk', 'es',
        'fi', 'fr', 'gb', 'gr', 'hk', 'id', 'in', 'it', 'jp', 'kr', 'mx', 'my',
        'nl', 'no', 'nz', 'ph', 'pl', 'pt', 'ru', 'sa', 'se', 'tr', 'tw', 'us', 'za',
    ];

    /** @return array<string, mixed> */
    public static function searchRules(bool $turbo): array
    {
        return [
            ...($turbo ? [] : ['mode' => ['sometimes', 'string', 'in:turbo,fast,basic,advanced']]),
            'objective' => ['sometimes', 'string', 'min:1', 'max:20000'],
            'search_queries' => ['sometimes', 'array', 'list', 'min:1', 'max:10'],
            'search_queries.*' => ['required', 'string', 'min:1', 'max:1000'],
            'max_chars_total' => ['sometimes', 'integer', 'min:1'],
            'max_chars_per_result' => ['sometimes', 'integer', 'min:1'],
            'max_results' => ['sometimes', 'integer', 'min:1'],
            'location' => ['sometimes', 'string', self::locationRule()],
            'source_policy' => ['sometimes', 'array:include_domains,exclude_domains,after_date'],
            'source_policy.include_domains' => ['sometimes', 'array', 'list', 'min:1', 'max:200'],
            'source_policy.include_domains.*' => ['required', 'string', 'max:253', self::sourceRule(! $turbo)],
            'source_policy.exclude_domains' => ['sometimes', 'array', 'list', 'min:1', 'max:200'],
            'source_policy.exclude_domains.*' => ['required', 'string', 'max:253', self::sourceRule(! $turbo)],
            'source_policy.after_date' => ['sometimes', 'date_format:Y-m-d'],
            'fetch_policy' => ['sometimes', 'array:max_age_seconds,timeout_seconds,disable_cache_fallback'],
            'fetch_policy.max_age_seconds' => ['sometimes', 'integer', 'min:600'],
            'fetch_policy.timeout_seconds' => ['sometimes', 'numeric', 'gt:0'],
            'fetch_policy.disable_cache_fallback' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public static function researchRules(): array
    {
        return [
            'include_domains' => ['sometimes', 'array', 'list', 'min:1', 'max:200'],
            'include_domains.*' => ['required', 'string', 'max:253', self::sourceRule(true)],
            'exclude_domains' => ['sometimes', 'array', 'list', 'min:1', 'max:200'],
            'exclude_domains.*' => ['required', 'string', 'max:253', self::sourceRule(true)],
            'location' => ['sometimes', 'string', self::locationRule()],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function search(array $options, bool $turbo): array
    {
        self::validate($options, self::searchRules($turbo));
        self::validateSourceCount(
            data_get($options, 'source_policy.include_domains', []),
            data_get($options, 'source_policy.exclude_domains', []),
        );

        return self::normalize($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function research(array $options): array
    {
        self::validate($options, self::researchRules());
        self::validateSourceCount($options['include_domains'] ?? [], $options['exclude_domains'] ?? []);

        return self::normalize($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $rules
     */
    private static function validate(array $options, array $rules): void
    {
        $allowed = array_values(array_unique(array_map(
            static fn (string $key): string => explode('.', $key, 2)[0],
            array_keys($rules),
        )));
        $validator = Validator::make($options, $rules);
        $validator->after(static function (LaravelValidator $validator) use ($allowed, $options): void {
            if (array_diff(array_keys($options), $allowed) !== []) {
                $validator->errors()->add('options', 'contains an unsupported option.');
            }
        });

        if ($validator->fails()) {
            throw new DriverException(
                'parallel.invalid_options',
                'The Parallel profile received invalid options.',
                false,
            );
        }
    }

    private static function validateSourceCount(mixed $included, mixed $excluded): void
    {
        if (! is_array($included) || ! is_array($excluded) || count($included) + count($excluded) > 200) {
            throw new DriverException(
                'parallel.invalid_options',
                'Parallel source policy may contain at most 200 entries.',
                false,
            );
        }
    }

    private static function sourceRule(bool $allowPath): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($allowPath): void {
            if (! is_string($value) || ! self::validSource($value, $allowPath)) {
                $fail($allowPath
                    ? 'The :attribute field must be a domain, domain path, or bare extension.'
                    : 'The :attribute field must be a domain or bare extension.');
            }
        };
    }

    private static function locationRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! in_array(strtolower($value), self::LOCATIONS, true)) {
                $fail('The :attribute field must be a supported ISO 3166-1 alpha-2 country code.');
            }
        };
    }

    private static function validSource(string $value, bool $allowPath): bool
    {
        if ($value === '' || trim($value) !== $value || str_contains($value, '://')
            || str_contains($value, '?') || str_contains($value, '#') || str_contains($value, ':')) {
            return false;
        }

        $parts = explode('/', $value, 2);
        $host = $parts[0];
        $path = $parts[1] ?? null;
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        $domain = '/^(?:'.$label.'\.)+'.$label.'$/iD';
        $extension = '/^\.(?:'.$label.'(?:\.'.$label.')*)$/iD';
        if (preg_match($domain, $host) !== 1 && preg_match($extension, $host) !== 1) {
            return false;
        }

        if ($path === null) {
            return true;
        }

        return $allowPath
            && $path !== ''
            && preg_match('#^(?!.*(?:^|/)\.\.?(?:/|$))[A-Za-z0-9._~!$&\'()*+,;=:@%/-]+$#D', $path) === 1;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function normalize(array $options): array
    {
        foreach (['location'] as $key) {
            if (isset($options[$key]) && is_string($options[$key])) {
                $options[$key] = strtoupper($options[$key]);
            }
        }

        foreach (['include_domains', 'exclude_domains'] as $key) {
            if (isset($options[$key]) && is_array($options[$key])) {
                $options[$key] = self::normalizeSources($options[$key]);
            }

            $sourcePolicy = $options['source_policy'] ?? null;
            if (is_array($sourcePolicy) && isset($sourcePolicy[$key]) && is_array($sourcePolicy[$key])) {
                $sourcePolicy[$key] = self::normalizeSources($sourcePolicy[$key]);
                $options['source_policy'] = $sourcePolicy;
            }
        }

        return $options;
    }

    private static function normalizeSource(string $source): string
    {
        $parts = explode('/', $source, 2);
        $host = $parts[0];
        $path = $parts[1] ?? null;

        return strtolower($host).($path === null ? '' : '/'.rtrim($path, '/'));
    }

    /**
     * @param  array<array-key, mixed>  $sources
     * @return list<string>
     */
    private static function normalizeSources(array $sources): array
    {
        $normalized = [];
        foreach ($sources as $source) {
            if (is_string($source)) {
                $normalized[] = self::normalizeSource($source);
            }
        }

        return $normalized;
    }
}
