<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses\Enums;

enum ResultKind: string
{
    case SearchResults = 'search_results';
    case GroundedAnswer = 'grounded_answer';
    case ResearchReport = 'research_report';
    case ModelAnswer = 'model_answer';
}
