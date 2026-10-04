<?php

namespace Base\Video\Tests;

use Base\Entity\User;

/**
 * An account for a unit test: omnibase's User names classes only a booted
 * application has (its App\ aliases), so a mock of it cannot be generated
 * here. This one answers what the bundle asks, made without its constructor.
 */
class Member extends User
{
    public int $memberId = 1;
    public bool $verified = true;

    public static function make(int $id = 1, bool $verified = true): self
    {
        $member = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $member->memberId = $id;
        $member->verified = $verified;

        return $member;
    }

    public function getId(): ?int { return $this->memberId; }
    public function isVerified(): bool { return $this->verified; }
    public function isBanned(): bool { return false; }
    public function isLocked(): bool { return false; }
    public function __toString(): string { return 'member'.$this->memberId; }
}
