<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Composer\InstalledVersions;
use InvalidArgumentException;
use Opis\JsonSchema\Validator;

/** The pinned, shared data package is the authority for catalog and wire shapes. */
final class Spec
{
    public static function path(string $file): string
    {
        $path = InstalledVersions::getInstallPath('jkudish/librarium-spec');

        return $path.'/'.$file;
    }

    public static function read(string $file): mixed
    {
        $contents = file_get_contents(self::path($file));
        if ($contents === false) {
            throw new InvalidArgumentException("Cannot read shared spec file [{$file}].");
        }

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function validate(mixed $document, string $schema): void
    {
        $validator = new Validator;
        $validator->resolver()?->registerPrefix(
            'https://raw.githubusercontent.com/jkudish/librarium-spec/main/schema/',
            self::path('schema/'),
        );
        $result = $validator->validate(
            json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            'https://raw.githubusercontent.com/jkudish/librarium-spec/main/schema/'.$schema.'.schema.json',
        );
        if (! $result->isValid()) {
            throw new InvalidArgumentException("Invalid Librarium [{$schema}] document.");
        }
    }
}
