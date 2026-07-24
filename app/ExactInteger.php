<?php

namespace App;

use InvalidArgumentException;

final readonly class ExactInteger
{
    /**
     * @param  numeric-string  $value
     */
    private function __construct(private string $value) {}

    public static function from(int|string $value): self
    {
        $value = (string) $value;

        if (! is_numeric($value) || preg_match('/^-?\d+$/D', $value) !== 1) {
            throw new InvalidArgumentException('An exact integer value is required.');
        }

        return new self($value);
    }

    public function add(self $other): self
    {
        return self::from(bcadd($this->value, $other->value));
    }

    public function subtract(self $other): self
    {
        return self::from(bcsub($this->value, $other->value));
    }

    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value);
    }

    /**
     * @return numeric-string
     */
    public function value(): string
    {
        return $this->value;
    }
}
