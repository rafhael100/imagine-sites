<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use RuntimeException;

/** A concurrent edit changed the expected revision. Contains no database details. */
final class PreviewRevisionConflict extends RuntimeException {}
