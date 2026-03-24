<?php

require_once './vendor/autoload.php';

$lastCommit = `git show -q HEAD`;

$matches = [];

if (!preg_match('/(major|minor|patch):/i', $lastCommit, $matches)) {
    echo 'No release';
    return;
}

$changeType = strtolower($matches[1]);

$tags = `git fetch -t && git tag -l`;

$latest = collect(explode("\n", $tags))
    ->map(function ($tag) {
        $parts = [];
        if (!preg_match('/v?(\d+)\.(\d+).(\d+)/', $tag, $parts)) {
            return false;
        }

        return [
            (int) $parts[1],
            (int) $parts[2],
            (int) $parts[3],
        ];
    })
    ->filter()
    ->sort(function ($a, $b) {
        return $a[0] > $b[0] 
            || ($a[0] === $b[0] && $a[1] > $b[1]) 
            || ($a[0] === $b[0] && $a[1] === $b[1] && $a[2] > $b[2])
            ? 1 : -1;
    })
    ->values()
    ->pop()
    ?? [0, 0, 0];

$next = $latest;

switch ($changeType) {
    case 'major': 
        $next[0]++;
        $next[1] = $next[2] = 0;
        break;
    case 'minor': 
        $next[1]++;
        $next[2] = 0;
        break;
    case 'patch': 
        $next[2]++;
        break;
}

echo "New $changeType version is " . implode('.', $next);

$newVersionTag = 'v' . implode('.', $next);

`git tag $newVersionTag`;