<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

final class SourceResult
{
    /**
     * @param list<Candidate> $candidates
     * @param bool $transient the error is temporary (rate limit, timeout) and the source should be retried
     */
    public function __construct(
        public array $candidates = [],
        public ?string $error = null,
        public bool $transient = false,
        public int $requests = 0,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
