<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use JayI\Impex\Models\Message;

final class ShowMessageAction
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [];
    }

    public function execute(Message $message): Message
    {
        return $message;
    }
}
