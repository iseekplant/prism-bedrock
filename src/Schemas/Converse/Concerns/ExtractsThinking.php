<?php

namespace Prism\Bedrock\Schemas\Converse\Concerns;

use Illuminate\Support\Arr;

trait ExtractsThinking
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractThinking(array $data): array
    {
        $content = data_get($data, 'output.message.content', []);

        $reasoning = Arr::first(
            $content,
            fn ($item): bool => data_get($item, 'reasoningContent') !== null
        );

        if ($reasoning === null) {
            return [];
        }

        return array_filter([
            'thinking' => data_get($reasoning, 'reasoningContent.reasoningText.text'),
            'thinking_signature' => data_get($reasoning, 'reasoningContent.reasoningText.signature'),
        ], fn ($value): bool => $value !== null);
    }
}
