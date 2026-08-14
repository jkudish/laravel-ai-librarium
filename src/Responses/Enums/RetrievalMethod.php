<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses\Enums;

enum RetrievalMethod: string
{
    case SearchEndpoint = 'search_endpoint';
    case ModelSearchTool = 'model_search_tool';
    case ResearchAgent = 'research_agent';
    case ModelOnly = 'model_only';
}
