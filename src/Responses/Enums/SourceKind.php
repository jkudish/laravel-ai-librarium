<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses\Enums;

enum SourceKind: string
{
    case WebPage = 'web_page';
    case NewsArticle = 'news_article';
    case XPost = 'x_post';
    case File = 'file';
    case Place = 'place';
    case Video = 'video';
    case ForumPost = 'forum_post';
    case Unknown = 'unknown';
}
