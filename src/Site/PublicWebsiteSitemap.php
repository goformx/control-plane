<?php

declare(strict_types=1);

namespace App\Site;

use Waaseyaa\Seo\Discovery\SitemapContributorInterface;
use Waaseyaa\Seo\Discovery\SitemapPath;

final class PublicWebsiteSitemap implements SitemapContributorInterface
{
    public function contributedPaths(): iterable
    {
        foreach (['/', '/getting-started', '/compatibility', '/docs'] as $path) {
            yield new SitemapPath($path);
        }
    }
}
