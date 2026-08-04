<?php

namespace Guava\FilamentMcp\Exceptions;

use Exception;

/**
 * A remote file could not be fetched, or was refused by a safety guard.
 *
 * The message reaches the agent, so it must never disclose an internal hostname
 * or resolved address, only that the URL was not permitted.
 */
final class RemoteFetchException extends Exception {}
