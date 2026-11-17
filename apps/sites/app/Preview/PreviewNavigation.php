<?php

declare(strict_types=1);

namespace App\Preview;

use Imagine\Sites\Production\PreviewQueueQuery;
use InvalidArgumentException;

/** Keeps navigation in local routes and treats every GET value as untrusted. */
final readonly class PreviewNavigation
{
    private function __construct(
        public PreviewQueueQuery $queue,
        public ?int $beforeRevision,
        public ?int $afterRevision,
        public ?int $compareFrom,
        public ?int $compareTo,
    ) {}

    public static function fromQuery(array $query, bool $projectPage = false, bool $allowComparison = true): self
    {
        $allowed = ['status', 'format', 'cursor'];
        if ($projectPage) $allowed = [...$allowed, 'before_revision', 'after_revision'];
        if ($projectPage && $allowComparison) $allowed = [...$allowed, 'compare_from', 'compare_to'];
        if (array_diff(array_keys($query), $allowed)) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        foreach ($query as $value) {
            if (!is_string($value) || strlen($value) > 192 || !mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Invalid private preview navigation');
            }
        }
        $queue = new PreviewQueueQuery($query['status'] ?? 'all', $query['format'] ?? 'all', $query['cursor'] ?? null);
        $before = self::revision($query['before_revision'] ?? null);
        $after = self::revision($query['after_revision'] ?? null);
        if ($before !== null && $after !== null) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        $from = self::revision($query['compare_from'] ?? null);
        $to = self::revision($query['compare_to'] ?? null);
        if (($from === null) !== ($to === null) || ($from !== null && $from >= $to)) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        return new self($queue, $before, $after, $from, $to);
    }

    /** Comparison is read navigation, never a mutation target or a POST prerequisite. */
    public static function fromPostQuery(array $query, bool $projectPage = false): self
    {
        unset($query['compare_from'], $query['compare_to']);
        return self::fromQuery($query, $projectPage, false);
    }

    public function queueUrl(): string
    {
        return '/_preview'.$this->queryString($this->queue->parameters());
    }

    public function queueUrlFor(?string $cursor): string
    {
        $queue = new PreviewQueueQuery($this->queue->status, $this->queue->product, $cursor);
        return '/_preview'.$this->queryString($queue->parameters());
    }

    public function createUrl(): string
    {
        return '/_preview/projects'.$this->queryString($this->queue->parameters());
    }

    public function projectUrl(string $project, ?int $before = null, ?int $after = null): string
    {
        $this->assertProject($project);
        return '/_preview/projects/'.$project.$this->queryString($this->projectParameters($before, $after));
    }

    /** History paging preserves an explicitly selected comparison; POST URLs never do. */
    public function historyUrl(string $project, ?int $before = null, ?int $after = null): string
    {
        $this->assertProject($project);
        $parameters = $this->projectParameters($before, $after);
        if ($this->compareFrom !== null) {
            $parameters['compare_from'] = (string) $this->compareFrom;
            $parameters['compare_to'] = (string) $this->compareTo;
        }
        return '/_preview/projects/'.$project.$this->queryString($parameters);
    }

    public function comparisonUrl(string $project, int $from, int $to): string
    {
        $this->assertProject($project);
        if ($from < 1 || $to > 2147483647 || $from >= $to) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        $parameters = $this->comparisonFormParameters();
        $parameters['compare_from'] = (string) $from;
        $parameters['compare_to'] = (string) $to;
        return '/_preview/projects/'.$project.$this->queryString($parameters);
    }

    /** GET forms need hidden context fields because browsers replace the action query. */
    public function comparisonFormParameters(): array
    {
        return $this->projectParameters($this->beforeRevision, $this->afterRevision);
    }

    public function reviewUrl(string $project, int $revision): string
    {
        $this->assertProject($project);
        if ($revision < 1 || $revision > 2147483647) throw new InvalidArgumentException('Invalid private preview navigation');
        $parameters = $this->projectParameters($this->beforeRevision, $this->afterRevision);
        return '/_preview/projects/'.$project.'/versions/'.$revision.'/review'.$this->queryString($parameters);
    }

    private function projectParameters(?int $before, ?int $after): array
    {
        if (($before !== null && ($before < 1 || $before > 2147483647)) ||
            ($after !== null && ($after < 1 || $after > 2147483647)) || ($before !== null && $after !== null)) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        $parameters = $this->queue->parameters();
        if ($before !== null) $parameters['before_revision'] = (string) $before;
        if ($after !== null) $parameters['after_revision'] = (string) $after;
        return $parameters;
    }

    private static function revision(?string $value): ?int
    {
        if ($value === null) return null;
        if (preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) !== 1 || (int) $value > 2147483647) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        return (int) $value;
    }

    private function assertProject(string $project): void
    {
        if (preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $project) !== 1) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
    }

    private function queryString(array $parameters): string
    {
        return $parameters ? '?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986) : '';
    }
}
