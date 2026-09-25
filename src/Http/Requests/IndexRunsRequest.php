<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\ListRunsAction;
use JayI\Impex\Http\Request;
use JayI\Impex\Http\Resources\RunResource;
use JayI\Impex\Models\Run;

final class IndexRunsRequest extends Request
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->allows('viewAny', Run::class);
    }

    public function rules(): array
    {
        return ListRunsAction::rules();
    }

    public function persist(): JsonResponse
    {
        $runs = app(ListRunsAction::class)->execute($this->validated(), $this->actor());

        return RunResource::collection($runs)->response();
    }
}
