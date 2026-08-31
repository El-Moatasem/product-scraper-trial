<?php

namespace App\Services\Scraping;

use App\Exceptions\ScrapingException;

final class UserAgentRotator
{
    /** @var list<string> */
    private array $userAgents;

    private int $cursor = 0;

    /** @param list<string> $userAgents */
    public function __construct(array $userAgents)
    {
        $this->userAgents = array_values(array_filter(array_map('trim', $userAgents)));

        if ($this->userAgents === []) {
            throw new ScrapingException('At least one scraper user agent must be configured.');
        }

        $this->cursor = random_int(0, count($this->userAgents) - 1);
    }

    public function next(): string
    {
        $userAgent = $this->userAgents[$this->cursor];
        $this->cursor = ($this->cursor + 1) % count($this->userAgents);

        return $userAgent;
    }
}
