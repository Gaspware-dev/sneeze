<?php

declare(strict_types=1);

/**
 * Failure of the plugin contract (bad manifest, missing capability, unsafe path).
 * Site validation errors still use Response::error, matching the studio API.
 */
class PluginException extends RuntimeException
{
}
