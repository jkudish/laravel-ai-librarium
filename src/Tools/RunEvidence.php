<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/** Applications decide which Runs their agent is allowed to read. */
final readonly class RunEvidence implements Tool
{
    public function __construct(private Librarium $librarium) {}

    public function description(): string
    {
        return 'Read the redacted provider evidence stored for one source in a Librarium Run.';
    }

    public function handle(Request $request): string
    {
        $run = $this->librarium->find((string) $request->string('run_id'));
        if ($run === null) {
            return 'Run not found.';
        }

        return json_encode($run->evidence((string) $request->string('source')), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['run_id' => $schema->string()->required(), 'source' => $schema->string()->required()];
    }
}
