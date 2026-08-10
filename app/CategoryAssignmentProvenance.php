<?php

namespace App;

enum CategoryAssignmentProvenance: string
{
    case Owner = 'owner';
    case LinkedRefund = 'linked_refund';
    case LearnedRule = 'learned_rule';

    public function canReplace(?self $current): bool
    {
        return $current === null || $this->priority() >= $current->priority();
    }

    private function priority(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::LinkedRefund => 3,
            self::LearnedRule => 2,
        };
    }
}
