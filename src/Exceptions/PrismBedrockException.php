<?php

namespace Prism\Bedrock\Exceptions;

use Prism\Prism\Exceptions\PrismException;

class PrismBedrockException extends PrismException
{
    public bool $isRetryable = false;

    public function retryable(bool $isRetryable = true): static
    {
        $this->isRetryable = $isRetryable;

        return $this;
    }
}
