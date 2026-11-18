<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use RuntimeException;

/** The recorded operation or its fence changed; reread before deciding what to do. */
final class PublicationJournalConflict extends RuntimeException {}
