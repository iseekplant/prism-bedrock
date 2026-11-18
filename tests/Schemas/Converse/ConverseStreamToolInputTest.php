<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse;

use Illuminate\Support\Facades\Http;
use Prism\Bedrock\Enums\BedrockSchema;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\EventStreamEncoder;

/**
 * Simulates the `render-ui` tool call from the SMS agent conversation where the
 * tool arguments arrived as `{"input": "{\"layout_id\": \"...\", \"type\": \"video\""}`.
 */
function renderUiStream(array $inputChunks, string $stopReason): string
{
    return EventStreamEncoder::encode([
        ['messageStart', ['role' => 'assistant']],
        ['contentBlockStart', [
            'contentBlockIndex' => 0,
            'start' => ['toolUse' => ['toolUseId' => 'tooluse_test', 'name' => 'render-ui']],
        ]],
        ...array_map(fn (string $chunk): array => ['contentBlockDelta', [
            'contentBlockIndex' => 0,
            'delta' => ['toolUse' => ['input' => $chunk]],
        ]], $inputChunks),
        ['contentBlockStop', ['contentBlockIndex' => 0]],
        ['messageStop', ['stopReason' => $stopReason]],
        ['metadata', [
            'usage' => ['inputTokens' => 100, 'outputTokens' => 4096, 'totalTokens' => 4196],
            'metrics' => ['latencyMs' => 1000],
        ]],
    ]);
}

function streamRenderUi(string $body): array
{
    Http::fake([
        'converse-stream' => Http::response($body, 200, ['Content-Type' => 'application/vnd.amazon.eventstream']),
    ])->preventStrayRequests();

    $tool = Tool::as('render-ui')
        ->for('Render a web page, pdf or video')
        ->withStringParameter('layout_id', 'Stable layout id')
        ->withEnumParameter('type', 'Layout type', ['web', 'pdf', 'video'])
        ->withObjectParameter('spec', 'The json-render spec', [new StringSchema('root', 'root')])
        ->using(fn (string $layout_id, string $type, array $spec): string => 'ok');

    return iterator_to_array(
        Prism::text()
            ->using('bedrock', 'anthropic.claude-sonnet-5-5')
            ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
            ->withPrompt('Make me a video')
            ->withTools([$tool])
            ->withMaxSteps(1)
            ->asStream(),
        false
    );
}

it('reassembles tool input split across many deltas', function (): void {
    $input = json_encode([
        'layout_id' => 'open-summit-brisbane-highlights',
        'type' => 'video',
        'spec' => ['composition' => ['id' => 'main', 'fps' => 30], 'tracks' => [], 'clips' => []],
    ]);

    // Split at awkward positions, including mid-string and mid-escape boundaries.
    $events = streamRenderUi(renderUiStream(str_split($input, 7), 'tool_use'));

    $toolCall = collect($events)->first(fn ($e): bool => $e instanceof ToolCallEvent)->toolCall;

    expect($toolCall->arguments())->toBe(json_decode($input, true));
});

it('reproduces the partial json tool input when the response hits max_tokens', function (): void {
    // Bedrock flushes Claude's tool input one complete key/value at a time. When
    // max_tokens is reached while the (large) `spec` value is being generated, the
    // buffered spec is never sent, so the stream ends with the JSON object unclosed.
    $events = streamRenderUi(renderUiStream([
        '',
        '{"layout_id": "open-summit-brisbane-highlights"',
        ', "type": "video"',
    ], 'max_tokens'));

    $toolCall = collect($events)->first(fn ($e): bool => $e instanceof ToolCallEvent)->toolCall;
    $end = collect($events)->first(fn ($e): bool => $e instanceof StreamEndEvent);
    $toolResult = collect($events)->first(fn ($e): bool => $e instanceof ToolResultEvent);

    expect($end->finishReason)->toBe(FinishReason::Length)
        ->and($toolCall->arguments())->toBe([
            'input' => '{"layout_id": "open-summit-brisbane-highlights", "type": "video"',
        ])
        // The truncated call is still executed instead of surfacing the length stop.
        ->and($toolResult)->not->toBeNull()
        ->and($toolResult->success)->toBeFalse();
});
