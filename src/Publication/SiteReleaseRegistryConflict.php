<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use RuntimeException;

/** A current release revision or immutable reference changed; reread before retrying. */
final class SiteReleaseRegistryConflict extends RuntimeException {}
