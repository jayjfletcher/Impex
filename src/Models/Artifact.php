<?php

declare(strict_types=1);

namespace JayI\Impex\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use JayI\Impex\Database\Factories\ArtifactFactory;
use JayI\Impex\Enums\ArtifactKind;

/**
 * @property string $id
 * @property string $disk
 * @property string $path
 * @property ArtifactKind $kind
 * @property string|null $mime
 * @property int $bytes
 * @property string|null $checksum
 * @property string|null $run_id
 * @property string|null $step_id
 * @property string|null $message_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Artifact extends Model
{
    /** @use HasFactory<ArtifactFactory> */
    use HasFactory;

    use HasUlids;

    // Prunable, not MassPrunable: mass pruning issues a bulk delete without
    // hydrating models, so pruning() never fires and every stored object is
    // orphaned on the disk.
    use Prunable;

    protected $table = 'impex_artifacts';

    protected $fillable = [
        'disk',
        'path',
        'kind',
        'mime',
        'bytes',
        'checksum',
        'run_id',
        'step_id',
        'message_id',
        'expires_at',
    ];

    /**
     * Read the artifact's contents from its disk.
     */
    public function contents(): ?string
    {
        return Storage::disk($this->disk)->get($this->path);
    }

    /**
     * @return Builder<Artifact>
     */
    public function prunable(): Builder
    {
        return $this->newQuery()->whereNotNull('expires_at')->where('expires_at', '<=', Carbon::now());
    }

    /**
     * Delete the backing object when the record is pruned.
     */
    protected function pruning(): void
    {
        Storage::disk($this->disk)->delete($this->path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ArtifactKind::class,
            'bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ArtifactFactory
    {
        return ArtifactFactory::new();
    }
}
