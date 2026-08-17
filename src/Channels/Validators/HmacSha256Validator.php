<?php

declare(strict_types=1);

namespace JayI\Impex\Channels\Validators;

use Illuminate\Http\Request;
use JayI\Impex\Channels\ChannelConfig;
use JayI\Impex\Contracts\SignatureValidator;

/**
 * The common shape: HMAC-SHA256 of the raw body, keyed by the shared secret.
 */
final class HmacSha256Validator implements SignatureValidator
{
    public function isValid(Request $request, ChannelConfig $config): bool
    {
        if (! $config->verifiesSignatures()) {
            return true;
        }

        $provided = $request->header($config->signatureHeader);

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), (string) $config->signingSecret);

        // Constant-time: a timing-variable comparison leaks the signature one
        // byte at a time.
        return hash_equals($expected, $provided);
    }
}
