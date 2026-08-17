<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunOwner;

final class AttachRunOwnerAction
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'owner_type' => ['required', 'string', 'max:191'],
            'owner_id' => ['required', 'string', 'max:64'],
            'role' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Run $run, array $data): RunOwner
    {
        /** @var RunOwner $owner */
        $owner = $run->owners()->firstOrCreate([
            'owner_type' => $data['owner_type'],
            'owner_id' => $data['owner_id'],
            'role' => $data['role'],
        ]);

        return $owner;
    }
}
