<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Builds binary `application/vnd.amazon.eventstream` bodies, the framing
 * Bedrock uses for converse-stream responses, so tests can simulate
 * arbitrary event sequences without recording them against the real API.
 */
class EventStreamEncoder
{
    /**
     * @param  array<int, array{0: string, 1: array<string, mixed>}>  $events  list of [eventType, payload]
     */
    public static function encode(array $events): string
    {
        return implode('', array_map(
            fn (array $event): string => static::message($event[0], $event[1]),
            $events
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function message(string $eventType, array $payload): string
    {
        $headers = static::header(':event-type', $eventType)
            .static::header(':content-type', 'application/json')
            .static::header(':message-type', 'event');

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return static::frame($headers, $body);
    }

    /**
     * Builds an `exception` frame, as Bedrock sends mid-stream.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function exception(string $exceptionType, array $payload): string
    {
        $headers = static::header(':exception-type', $exceptionType)
            .static::header(':content-type', 'application/json')
            .static::header(':message-type', 'exception');

        return static::frame($headers, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    protected static function frame(string $headers, string $body): string
    {
        $totalLength = 12 + strlen($headers) + strlen($body) + 4;

        $prelude = pack('N', $totalLength).pack('N', strlen($headers));
        $prelude .= pack('N', crc32($prelude));

        $message = $prelude.$headers.$body;

        return $message.pack('N', crc32($message));
    }

    protected static function header(string $name, string $value): string
    {
        return chr(strlen($name)).$name.chr(7).pack('n', strlen($value)).$value;
    }
}
