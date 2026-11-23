<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse;

use Illuminate\Support\Facades\Http;
use Prism\Bedrock\Enums\BedrockSchema;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\EventStreamEncoder;

/**
 * Simulates the `render-ui` tool call from the SMS agent conversation where the
 * tool arguments arrived as `{"input": "{\"layout_id\": \"...\", \"type\": \"video\""}`.
 */
function renderUiStream(array $inputChunks, string $stopReason, string $toolName = 'render-ui'): string
{
    return EventStreamEncoder::encode([
        ['messageStart', ['role' => 'assistant']],
        ['contentBlockStart', [
            'contentBlockIndex' => 0,
            'start' => ['toolUse' => ['toolUseId' => 'tooluse_test', 'name' => $toolName]],
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

it('returns an error result without running the tool when the response hits max_tokens mid tool call', function (): void {
    // Bedrock flushes Claude's tool input one complete key/value at a time. When
    // max_tokens is reached while the (large) `spec` value is being generated, the
    // buffered spec is never sent, so the stream ends with the JSON object unclosed.
    $events = streamRenderUi(renderUiStream([
        '',
        '{"layout_id": "open-summit-brisbane-highlights"',
        ', "type": "video"',
    ], 'max_tokens'));

    $toolResult = collect($events)->first(fn ($e): bool => $e instanceof ToolResultEvent);

    expect($toolResult)->not->toBeNull()
        ->and($toolResult->success)->toBeFalse()
        ->and($toolResult->toolResult->args)->toBe([])
        ->and($toolResult->error)
        ->toContain('could not be parsed as valid JSON')
        ->toContain('maximum output token limit')
        ->toContain('NOT executed');
});

it('reports back to the model when the called tool does not exist', function (): void {
    // e.g. the model dropped the relay server prefix from an MCP tool name.
    $events = streamRenderUi(renderUiStream(['{"layout_id": "a", "type": "web", "spec": {}}'], 'tool_use', 'relay_render-ui'));

    $toolResult = collect($events)->first(fn ($e): bool => $e instanceof ToolResultEvent);

    expect($toolResult->success)->toBeFalse()
        ->and($toolResult->toolResult->result)->toContain('does not exist')
        ->and($toolResult->error)->toContain('"relay_render-ui"');
});

function streamUnknownToolRepeatedly(array $providerOptions = []): array
{
    Http::fake([
        'converse-stream' => Http::response(
            renderUiStream(['{}'], 'tool_use', 'relay_render-ui'),
            200,
            ['Content-Type' => 'application/vnd.amazon.eventstream'],
        ),
    ])->preventStrayRequests();

    $tool = Tool::as('render-ui')
        ->for('Render a web page, pdf or video')
        ->using(fn (): string => 'ok');

    return iterator_to_array(
        Prism::text()
            ->using('bedrock', 'anthropic.claude-sonnet-5-5')
            ->withProviderOptions(['apiSchema' => BedrockSchema::Converse, ...$providerOptions])
            ->withPrompt('Make me a video')
            ->withTools([$tool])
            ->withMaxSteps(10)
            ->asStream(),
        false
    );
}

it('stops after three sequential tool errors by default', function (): void {
    $events = streamUnknownToolRepeatedly();

    $error = collect($events)->first(fn ($e): bool => $e instanceof ErrorEvent);

    Http::assertSentCount(3);
    expect($error->errorType)->toBe('too_many_tool_errors')
        ->and($error->recoverable)->toBeFalse();
});

it('allows the sequential tool error limit to be configured', function (): void {
    streamUnknownToolRepeatedly(['maxSequentialToolErrors' => 2]);

    Http::assertSentCount(2);
});
