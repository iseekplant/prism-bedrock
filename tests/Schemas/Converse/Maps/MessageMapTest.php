<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse\Maps;

use Prism\Bedrock\Schemas\Converse\Maps\MessageMap;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('maps user messages', function (): void {
    expect(MessageMap::map([
        new UserMessage('Who are you?'),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            ['text' => 'Who are you?'],
        ],
    ]]);
});

it('maps assistant message', function (): void {
    expect(MessageMap::map([
        new AssistantMessage('I am Nyx'),
    ]))->toContain([
        'role' => 'assistant',
        'content' => [
            [
                'text' => 'I am Nyx',
            ],
        ],
    ]);
});

it('maps system messages', function (): void {
    expect(MessageMap::mapSystemMessages([
        new SystemMessage('I am Thanos.'),
        new SystemMessage('But call me Bob.'),
    ]))->toBe([
        [
            'text' => 'I am Thanos.',
        ],
        [
            'text' => 'But call me Bob.',
        ],
    ]);
});

it('maps an md document correctly', function (): void {
    expect(MessageMap::map([
        new UserMessage(
            content: 'Who are you?',
            additionalContent: [
                Document::fromPath('tests/Fixtures/document.md', 'Answer To Life'),
            ]
        ),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            [
                'document' => [
                    'format' => 'txt',
                    'name' => 'Answer To Life',
                    'source' => ['bytes' => base64_encode(file_get_contents('tests/Fixtures/document.md'))],
                ],
            ],
            ['text' => 'Who are you?'],
        ],
    ]]);
});

it('maps a document with citations enabled correctly', function (): void {
    expect(MessageMap::map([
        (new UserMessage(
            content: 'Who are you?',
            additionalContent: [
                Document::fromPath('tests/Fixtures/document.md', 'Answer To Life'),
            ]
        ))->withProviderOptions([
            'citations' => [
                'enabled' => true,
            ],
        ]),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            [
                'document' => [
                    'format' => 'txt',
                    'name' => 'Answer To Life',
                    'source' => ['bytes' => base64_encode(file_get_contents('tests/Fixtures/document.md'))],
                    'citations' => [
                        'enabled' => true,
                    ],
                ],
            ],
            ['text' => 'Who are you?'],
        ],
    ]]);
});

it('maps an image correctly', function (): void {
    expect(MessageMap::map([
        new UserMessage(
            content: 'Who are you?',
            additionalContent: [
                Image::fromPath('tests/Fixtures/test-image.png'),
            ]
        ),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            [
                'image' => [
                    'format' => 'png',
                    'source' => ['bytes' => base64_encode(file_get_contents('tests/Fixtures/test-image.png'))],
                ],
            ],
            ['text' => 'Who are you?'],
        ],
    ]]);
});

it('maps assistant message with tool calls', function (): void {
    expect(MessageMap::map([
        new AssistantMessage('I am Nyx', [
            new ToolCall(
                'tool_1234',
                'search',
                [
                    'query' => 'Laravel collection methods',
                ]
            ),
        ]),
    ]))->toBe([
        [
            'role' => 'assistant',
            'content' => [
                ['text' => 'I am Nyx'],
                [
                    'toolUse' => [
                        'toolUseId' => 'tool_1234',
                        'name' => 'search',
                        'input' => [
                            'query' => 'Laravel collection methods',
                        ],
                    ],
                ],
            ],
        ],
    ]);
});

it('maps assistant message with tool calls with empty arguments as stdClass', function (): void {
    expect(MessageMap::map([
        new AssistantMessage('Running tool', [
            new ToolCall(
                'tool_5678',
                'get_time',
                []
            ),
        ]),
    ]))->toEqual([
        [
            'role' => 'assistant',
            'content' => [
                ['text' => 'Running tool'],
                [
                    'toolUse' => [
                        'toolUseId' => 'tool_5678',
                        'name' => 'get_time',
                        'input' => new \stdClass,
                    ],
                ],
            ],
        ],
    ]);
});

it('prepends a reasoningContent block for assistant messages with thinking', function (): void {
    expect(MessageMap::map([
        new AssistantMessage(
            content: 'I am Nyx',
            toolCalls: [],
            additionalContent: [
                'thinking' => 'Let me consider this carefully.',
                'thinking_signature' => 'sig-abc123',
            ],
        ),
    ]))->toBe([[
        'role' => 'assistant',
        'content' => [
            [
                'reasoningContent' => [
                    'reasoningText' => [
                        'text' => 'Let me consider this carefully.',
                        'signature' => 'sig-abc123',
                    ],
                ],
            ],
            ['text' => 'I am Nyx'],
        ],
    ]]);
});

it('prepends a reasoningContent block without a signature when none is present', function (): void {
    expect(MessageMap::map([
        new AssistantMessage(
            content: 'I am Nyx',
            toolCalls: [],
            additionalContent: ['thinking' => 'Thinking without a signature.'],
        ),
    ]))->toBe([[
        'role' => 'assistant',
        'content' => [
            ['reasoningContent' => ['reasoningText' => ['text' => 'Thinking without a signature.']]],
            ['text' => 'I am Nyx'],
        ],
    ]]);
});

it('puts reasoningContent before tool calls and the cache breakpoint', function (): void {
    expect(MessageMap::map([
        (new AssistantMessage(
            content: '',
            toolCalls: [new ToolCall('tool_1', 'search', ['query' => 'x'])],
            additionalContent: ['thinking' => 'Reasoning about the search.', 'thinking_signature' => 'sig-1'],
        ))->withProviderOptions(['cacheType' => 'default']),
    ]))->toBe([[
        'role' => 'assistant',
        'content' => [
            ['reasoningContent' => ['reasoningText' => ['text' => 'Reasoning about the search.', 'signature' => 'sig-1']]],
            ['toolUse' => ['toolUseId' => 'tool_1', 'name' => 'search', 'input' => ['query' => 'x']]],
            ['cachePoint' => ['type' => 'default']],
        ],
    ]]);
});

it('does not add a reasoningContent block when no thinking is present', function (): void {
    expect(MessageMap::map([
        new AssistantMessage('I am Nyx'),
    ]))->toBe([[
        'role' => 'assistant',
        'content' => [
            ['text' => 'I am Nyx'],
        ],
    ]]);
});

it('maps tool result messages', function (): void {
    expect(MessageMap::map([
        new ToolResultMessage([
            new ToolResult(
                'tool_1234',
                'search',
                [
                    'query' => 'Laravel collection methods',
                ],
                '[search results]'
            ),
        ]),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            [
                'toolResult' => [
                    'status' => 'success',
                    'toolUseId' => 'tool_1234',
                    'content' => [
                        ['text' => '[search results]'],
                    ],
                ],
            ],
        ],
    ]]);
});

it('maps user messages with a cache breakpoint correctly', function (): void {
    expect(MessageMap::map([
        (new UserMessage('Who are you?'))->withProviderOptions(['cacheType' => 'default']),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            ['text' => 'Who are you?'],
            [
                'cachePoint' => [
                    'type' => 'default',
                ],
            ],
        ],
    ]]);
});

it('maps assistant messages with a cache breakpoint correctly', function (): void {
    expect(MessageMap::map([
        (new AssistantMessage('I am Thanos'))->withProviderOptions(['cacheType' => 'default']),
    ]))->toBe([[
        'role' => 'assistant',
        'content' => [
            ['text' => 'I am Thanos'],
            [
                'cachePoint' => [
                    'type' => 'default',
                ],
            ],
        ],
    ]]);
});

it('maps system messages with a cache breakpoint correctly', function (): void {
    expect(MessageMap::mapSystemMessages([
        (new SystemMessage('The answer to life is 42.'))->withProviderOptions(['cacheType' => 'default']),
        (new SystemMessage('Convert any numbers in your answer to their word format.')),
    ]))->toBe([
        [
            'text' => 'The answer to life is 42.',
        ],
        [
            'cachePoint' => [
                'type' => 'default',
            ],
        ],
        [
            'text' => 'Convert any numbers in your answer to their word format.',
        ],
    ]);
});

it('maps system messages with a cache breakpoint and 1h ttl correctly', function (): void {
    expect(MessageMap::mapSystemMessages([
        (new SystemMessage('The answer to life is 42.'))->withProviderOptions(['cacheType' => 'default', 'cacheTtl' => '1h']),
    ]))->toBe([
        [
            'text' => 'The answer to life is 42.',
        ],
        [
            'cachePoint' => [
                'type' => 'default',
                'ttl' => '1h',
            ],
        ],
    ]);
});

it('maps user messages with a cache breakpoint and 1h ttl correctly', function (): void {
    expect(MessageMap::map([
        (new UserMessage('Who are you?'))->withProviderOptions(['cacheType' => 'default', 'cacheTtl' => '1h']),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            ['text' => 'Who are you?'],
            [
                'cachePoint' => [
                    'type' => 'default',
                    'ttl' => '1h',
                ],
            ],
        ],
    ]]);
});

it('maps assistant messages with a cache breakpoint and 1h ttl correctly', function (): void {
    expect(MessageMap::map([
        (new AssistantMessage('I am Thanos'))->withProviderOptions(['cacheType' => 'default', 'cacheTtl' => '1h']),
    ]))->toBe([[
        'role' => 'assistant',
        'content' => [
            ['text' => 'I am Thanos'],
            [
                'cachePoint' => [
                    'type' => 'default',
                    'ttl' => '1h',
                ],
            ],
        ],
    ]]);
});
