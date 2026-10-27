<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests\Support;

use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Testing\RunStoreContractTests;

final readonly class StoreContract
{
    use RunStoreContractTests;

    public function __construct(private RunStore $runStore) {}

    protected function store(): RunStore
    {
        return $this->runStore;
    }
}
