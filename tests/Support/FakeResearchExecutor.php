<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests\Support;

use Illuminate\Support\Facades\File;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\QueuedResearchResponse;
use Jkudish\LaravelAiLibrarium\ResearchPlan;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Laravel\Ai\FakePendingDispatch;

final class FakeResearchExecutor implements ExecutesResearch
{
    /** @var list<ResearchPlan> */
    public array $plans = [];

    public function run(ResearchPlan $plan): ResearchResponse
    {
        $this->plans[] = $plan;
        $value = json_decode(File::get(__DIR__.'/Contracts/v1/fixtures/valid/markdown-success.json'), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new \RuntimeException('The terminal fixture must decode to an array.');
        }
        $value['request_id'] = $plan->requestId;

        return ResearchResponse::fromArray($value);
    }

    public function queue(ResearchPlan $plan): QueuedResearchResponse
    {
        $this->plans[] = $plan;

        return new QueuedResearchResponse($plan->requestId, new FakePendingDispatch);
    }
}
