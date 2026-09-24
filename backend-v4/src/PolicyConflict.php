<?php
declare(strict_types=1);
namespace Keeper;

final class PolicyConflict extends \RuntimeException
{
    public function __construct(string $level, string $kind, string $target, int $priority, array $first, array $second)
    {
        $rules = [$first['assignment'] . '/' . $first['rule']->id, $second['assignment'] . '/' . $second['rule']->id];
        sort($rules, SORT_STRING);
        parent::__construct('policy_conflict: level=' . $level . ' kind=' . $kind . ' target_sha256=' . hash('sha256', $target) . ' priority=' . $priority . ' assignment/rule=' . implode(',', $rules));
    }
}
