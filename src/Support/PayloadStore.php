<?php

declare(strict_types=1);

namespace JayI\Impex\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JayI\Impex\Enums\ArtifactKind;
use JayI\Impex\Exceptions\PayloadException;
use JayI\Impex\Models\Artifact;
use Throwable;

/**
 * Moves values between the database and the artifact disk.
 *
 * Queue messages carry ULIDs only, never payloads: anything serializing above
 * the inline threshold is written to a disk and referenced by artifact id, so
 * a job body can never approach SQS's 256KB message limit.
 */
final class PayloadStore
{
    public function __construct(
        private readonly Config $config,
        private readonly FilesystemFactory $filesystem,
    ) {}

    /**
     * Store a value, inlining it when small enough.
     *
     * @param  array<string, string|null>  $links
     * @return array{inline: array<string, mixed>|null, artifact_id: string|null}
     */
    public function put(mixed $value, ArtifactKind $kind = ArtifactKind::Payload, array $links = []): array
    {
        $encoded = $this->encode($value);

        if (strlen($encoded) <= $this->threshold()) {
            return ['inline' => ['value' => $value], 'artifact_id' => null];
        }

        $disk = $this->disk();
        $path = $this->path($kind);

        $this->filesystem->disk($disk)->put($path, $encoded);

        $artifact = Artifact::query()->create([
            'disk' => $disk,
            'path' => $path,
            'kind' => $kind,
            'mime' => 'application/json',
            'bytes' => strlen($encoded),
            'checksum' => hash('sha256', $encoded),
            'run_id' => $links['run_id'] ?? null,
            'step_id' => $links['step_id'] ?? null,
            'message_id' => $links['message_id'] ?? null,
            'expires_at' => $this->expiry(),
        ]);

        return ['inline' => null, 'artifact_id' => $artifact->getKey()];
    }

    /**
     * Read a value back, from the inline column or the artifact disk.
     *
     * @param  array<string, mixed>|null  $inline
     */
    public function get(?array $inline, ?string $artifactId): mixed
    {
        if ($artifactId !== null) {
            $artifact = Artifact::query()->find($artifactId);

            if (! $artifact instanceof Artifact) {
                throw PayloadException::artifactMissing($artifactId);
            }

            $contents = $artifact->contents();

            if ($contents === null) {
                throw PayloadException::artifactMissing($artifactId);
            }

            return $this->decode($contents);
        }

        if ($inline === null) {
            return null;
        }

        return $inline['value'] ?? null;
    }

    /**
     * The byte size at which a value stops being stored inline.
     */
    public function threshold(): int
    {
        /** @var int $threshold */
        $threshold = $this->config->get('impex.artifacts.inline_threshold', 65536);

        return $threshold;
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode(['value' => $value], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            throw PayloadException::notSerializable($e);
        }
    }

    private function decode(string $encoded): mixed
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        return $decoded['value'] ?? null;
    }

    private function disk(): string
    {
        /** @var string|null $disk */
        $disk = $this->config->get('impex.artifacts.disk');

        /** @var string $default */
        $default = $this->config->get('filesystems.default', 'local');

        return $disk ?? $default;
    }

    private function path(ArtifactKind $kind): string
    {
        /** @var string $prefix */
        $prefix = $this->config->get('impex.artifacts.path', 'impex');

        return sprintf(
            '%s/%s/%s.json',
            trim($prefix, '/'),
            $kind->value,
            (string) Str::ulid(),
        );
    }

    private function expiry(): ?Carbon
    {
        /** @var int|null $days */
        $days = $this->config->get('impex.retention.artifacts_days');

        return $days === null ? null : Carbon::now()->addDays($days);
    }
}
