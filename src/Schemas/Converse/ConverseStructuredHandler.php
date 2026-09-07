<?php

namespace Prism\Bedrock\Schemas\Converse;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Prism\Bedrock\Contracts\BedrockStructuredHandler;
use Prism\Bedrock\Schemas\Converse\Concerns\ExtractsToolCalls;
use Prism\Bedrock\Schemas\Converse\Maps\FinishReasonMap;
use Prism\Bedrock\Schemas\Converse\Maps\MessageMap;
use Prism\Bedrock\Schemas\Converse\Maps\ToolChoiceMap;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class ConverseStructuredHandler extends BedrockStructuredHandler
{
    use ExtractsToolCalls;

    public const STRUCTURED_OUTPUT_TOOL_NAME = 'output_structured_data';

    protected StructuredResponse $tempResponse;

    protected Response $httpResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->responseBuilder = new ResponseBuilder;
    }

    #[\Override]
    public function handle(Request $request): StructuredResponse
    {
        if ($request->providerOptions('validated_schema') && $request->providerOptions('use_structured_output_tool')) {
            throw new PrismException('Converse: validated_schema and use_structured_output_tool cannot both be enabled');
        }

        if (! $request->providerOptions('validated_schema') && ! $request->providerOptions('use_structured_output_tool')) {
            $this->appendMessageForJsonMode($request);
        }

        $this->sendRequest($request);

        $this->prepareTempResponse();

        if ($request->providerOptions('use_structured_output_tool')) {
            $this->tempResponse = $this->applyStructuredOutputTool($this->tempResponse);
        }

        $responseMessage = new AssistantMessage(
            content: $this->tempResponse->text,
            toolCalls: [],
            additionalContent: $this->tempResponse->additionalContent
        );

        $request->addMessage($responseMessage);

        $this->responseBuilder->addStep(new Step(
            text: $this->tempResponse->text,
            finishReason: $this->tempResponse->finishReason,
            usage: $this->tempResponse->usage,
            meta: $this->tempResponse->meta,
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: $this->tempResponse->additionalContent,
            structured: $this->tempResponse->structured ?? [],
        ));

        return $this->responseBuilder->toResponse();
    }

    protected function applyStructuredOutputTool(StructuredResponse $response): StructuredResponse
    {
        $toolCalls = $this->extractToolCalls($this->httpResponse->json());

        $structuredCall = Arr::first($toolCalls, fn (ToolCall $toolCall): bool => $toolCall->name === self::STRUCTURED_OUTPUT_TOOL_NAME);

        if (! $structuredCall instanceof ToolCall) {
            throw new PrismException('Converse: expected a call to the structured output tool but none was returned');
        }

        return new StructuredResponse(
            steps: $response->steps,
            text: $response->text,
            structured: $structuredCall->arguments(),
            finishReason: $response->finishReason,
            usage: $response->usage,
            meta: $response->meta,
            additionalContent: $response->additionalContent,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildPayload(Request $request): array
    {
        // NOTE: additionalModelRequestFields (e.g. `thinking`) is forwarded, but this handler does not
        // extract/round-trip reasoningContent the way ConverseTextHandler/ConverseStreamHandler do.
        // Structured-output + thinking is not supported end-to-end here.
        return array_filter([
            'additionalModelRequestFields' => $request->providerOptions('additionalModelRequestFields'),
            'additionalModelResponseFieldPaths' => $request->providerOptions('additionalModelResponseFieldPaths'),
            'guardrailConfig' => $request->providerOptions('guardrailConfig'),
            'inferenceConfig' => array_filter([
                'maxTokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'topP' => $request->topP(),
            ], fn (mixed $value): bool => $value !== null),
            'messages' => MessageMap::map($request->messages()),
            'performanceConfig' => $request->providerOptions('performanceConfig'),
            'promptVariables' => $request->providerOptions('promptVariables'),
            'requestMetadata' => $request->providerOptions('requestMetadata'),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
            ...($request->providerOptions('validated_schema')
                ? [
                    'outputConfig' => [
                        'textFormat' => [
                            'type' => 'json_schema',
                            'structure' => [
                                'jsonSchema' => [
                                    'schema' => json_encode($request->schema()->toArray()),
                                    'name' => $request->schema()->name(),
                                    'description' => 'The output schema',
                                ],
                            ],
                        ],
                    ],
                ] : []),
            ...($request->providerOptions('use_structured_output_tool')
                ? [
                    'toolConfig' => [
                        'tools' => [[
                            'toolSpec' => [
                                'name' => self::STRUCTURED_OUTPUT_TOOL_NAME,
                                'description' => data_get($request->schema()->toArray(), 'description') ?: 'Output data in the requested structure',
                                'inputSchema' => [
                                    'json' => array_filter([
                                        'type' => 'object',
                                        'properties' => data_get($request->schema()->toArray(), 'properties', (object) []),
                                        'required' => data_get($request->schema()->toArray(), 'required', []),
                                    ]),
                                ],
                            ],
                        ]],
                        'toolChoice' => ToolChoiceMap::map(self::STRUCTURED_OUTPUT_TOOL_NAME),
                    ],
                ] : []),
        ]);
    }

    protected function sendRequest(Request $request): void
    {
        try {
            $this->httpResponse = $this->client->post(
                'converse',
                static::buildPayload($request)
            );
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    protected function prepareTempResponse(): void
    {
        $data = $this->httpResponse->json();

        $this->tempResponse = new StructuredResponse(
            steps: new Collection,
            text: data_get($data, 'output.message.content.0.text', ''),
            structured: [],
            finishReason: FinishReasonMap::map(data_get($data, 'stopReason')),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.inputTokens'),
                completionTokens: data_get($data, 'usage.outputTokens')
            ),
            meta: new Meta(id: '', model: '') // Not provided in Converse response.
        );
    }

    protected function appendMessageForJsonMode(Request $request): void
    {
        $request->addMessage(new UserMessage(sprintf(
            "%s \n %s",
            $request->providerOptions('jsonModeMessage') ?? 'Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema:',
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }
}
