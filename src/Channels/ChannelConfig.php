<?php

declare(strict_types=1);

namespace JayI\Impex\Channels;

use JayI\Impex\Channels\Profiles\ProcessEverything;
use JayI\Impex\Channels\Validators\HmacSha256Validator;
use JayI\Impex\Enums\Direction;

/**
 * One named boundary configuration, read from `impex.channels`.
 */
final readonly class ChannelConfig
{
    /**
     * @param  array<int, string>  $storeHeaders
     */
    public function __construct(
        public string $name,
        public Direction $direction = Direction::Inbound,
        public ?string $signingSecret = null,
        public string $signatureHeader = 'X-Signature',
        public string $signatureValidator = HmacSha256Validator::class,
        public string $profile = ProcessEverything::class,
        public ?string $flow = null,
        public array $storeHeaders = [],
        public ?string $queue = null,
        public ?string $path = null,
        public ?string $idempotencyHeader = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(string $name, array $config): self
    {
        /** @var array<int, string> $storeHeaders */
        $storeHeaders = $config['store_headers'] ?? [];

        return new self(
            name: $name,
            direction: Direction::tryFrom((string) ($config['direction'] ?? 'inbound')) ?? Direction::Inbound,
            signingSecret: isset($config['signing_secret']) ? (string) $config['signing_secret'] : null,
            signatureHeader: (string) ($config['signature_header'] ?? 'X-Signature'),
            signatureValidator: (string) ($config['signature_validator'] ?? HmacSha256Validator::class),
            profile: (string) ($config['profile'] ?? ProcessEverything::class),
            flow: isset($config['flow']) ? (string) $config['flow'] : null,
            storeHeaders: $storeHeaders,
            queue: isset($config['queue']) ? (string) $config['queue'] : null,
            path: isset($config['path']) ? (string) $config['path'] : null,
            idempotencyHeader: isset($config['idempotency_header']) ? (string) $config['idempotency_header'] : null,
        );
    }

    /**
     * Whether the channel verifies signatures at all.
     */
    public function verifiesSignatures(): bool
    {
        return $this->signingSecret !== null && $this->signingSecret !== '';
    }
}
