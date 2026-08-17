<?php

declare(strict_types=1);

namespace Prism\Bedrock\ValueObjects;

use Prism\Prism\Streaming\StreamState;

class ConverseStreamState extends StreamState
{
    protected string $currentThinkingSignature = '';

    public function withBlockIndex(int $index): self
    {
        $this->currentBlockIndex = $index;

        return $this;
    }

    public function withBlockType(string $type): self
    {
        $this->currentBlockType = $type;

        return $this;
    }

    public function appendThinkingSignature(string $signature): self
    {
        $this->currentThinkingSignature .= $signature;

        return $this;
    }

    public function currentThinkingSignature(): string
    {
        return $this->currentThinkingSignature;
    }

    #[\Override]
    public function reset(): self
    {
        parent::reset();

        $this->currentThinkingSignature = '';

        return $this;
    }
}
