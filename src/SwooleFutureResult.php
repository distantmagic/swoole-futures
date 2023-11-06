<?php

declare(strict_types=1);

namespace Distantmagic\SwooleFuture;

readonly class SwooleFutureResult
{
    public function __construct(
        public PromiseState $state,
        public mixed $result,
    ) {}
}
