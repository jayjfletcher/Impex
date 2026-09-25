<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use JayI\Impex\Events\Action\MessageShowingActionEvent;
use JayI\Impex\Events\Action\MessageShownActionEvent;
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
        MessageShowingActionEvent::dispatch($message);

        $result = $this->perform($message);

        MessageShownActionEvent::dispatch($result);

        return $result;
    }

    private function perform(Message $message): Message
    {
        return $message;
    }
}
