<?php

namespace AiOssAssistant\Services;

interface OptOutRegistryInterface
{
    public function isOptedOut(string $fullName): bool;
}

class OptOutRegistryService implements OptOutRegistryInterface
{
    private array $optOutList;

    public function __construct(?array $optOutList = null)
    {
        $this->optOutList = $optOutList ?? [
            'torvalds/linux',
            'python/cpython',
            'no-ai-org/opted-out-repo',
        ];
    }

    public function isOptedOut(string $fullName): bool
    {
        $lower = strtolower($fullName);
        foreach ($this->optOutList as $optedOut) {
            if (strtolower($optedOut) === $lower || str_starts_with($lower, strtolower($optedOut) . '/')) {
                return true;
            }
        }
        return false;
    }
}
