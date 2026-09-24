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
        // A channel with no secret cannot authenticate anything, so it fails
        // closed. Returning true here would make a misconfigured channel — one
        // whose `signing_secret` is absent, or whose env var resolved to null
        // in production — accept every request that reached it: an open
        // workflow trigger rather than a lax one. The ledger still records the
        // attempt; only the dispatch is refused.
        if (! $config->verifiesSignatures()) {
            return false;
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
