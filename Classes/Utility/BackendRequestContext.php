<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Utility;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;

/**
 * Runs code with a defined TYPO3 request in place.
 *
 * TYPO3's configuration managers resolve TypoScript from $GLOBALS['TYPO3_REQUEST'] and
 * take no request argument. ensure() supplies a fake backend request where none exists,
 * which CLI needs because Extbase's ConfigurationManager throws a
 * NoServerRequestGivenException without one. withRequest() substitutes a given request,
 * so that TypoScript resolves for that page and site.
 *
 * TYPO3 core uses the same fake request pattern in its own CLI commands, for example
 * DeactivateExtensionCommand and ResetPasswordCommand.
 *
 * Code paths that also perform FAL operations need
 * Bootstrap::initializeBackendAuthentication() on top, or StoragePermissionsAspect
 * rejects them because it checks isAdmin() on the BE_USER.
 *
 * @see \TYPO3\CMS\Extbase\Configuration\ConfigurationManager::getConfiguration()
 * @see \TYPO3\CMS\Core\Resource\Security\StoragePermissionsAspect
 */
class BackendRequestContext
{
    /**
     * Execute a callback with a specific request in place.
     *
     * TYPO3's configuration managers resolve TypoScript from the current request, and
     * take no request argument, so the only way to resolve it for a given page or site
     * is to make that request the current one for the duration of the call.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function withRequest(ServerRequestInterface $request, callable $callback): mixed
    {
        $previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $GLOBALS['TYPO3_REQUEST'] = $request;

        try {
            return $callback();
        } finally {
            if ($previousRequest === null) {
                unset($GLOBALS['TYPO3_REQUEST']);
            } else {
                $GLOBALS['TYPO3_REQUEST'] = $previousRequest;
            }
        }
    }

    /**
     * Execute a callback with a backend ServerRequest guaranteed to exist.
     *
     * If $GLOBALS['TYPO3_REQUEST'] is already set, the callback runs as-is.
     * Otherwise a minimal fake backend request is created, the callback is
     * executed, and the fake request is cleaned up - even if the callback throws.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function ensure(callable $callback): mixed
    {
        if (isset($GLOBALS['TYPO3_REQUEST'])) {
            return $callback();
        }

        // A non-null URI is required because TYPO3's ServerRequest leaves
        // $uri as null when constructed without one, which violates PSR-7's
        // getUri(): UriInterface contract and crashes consumers that call
        // $request->getUri()->__toString().
        // Uri::fromAnyScheme() is required because the default Uri constructor
        // only accepts http/https/ws/wss; the "cli://" marker scheme would
        // otherwise be rejected by sanitizeScheme().
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest(Uri::fromAnyScheme('cli://typo3')))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        try {
            return $callback();
        } finally {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
    }
}
