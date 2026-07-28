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

    public function multiply(self $other): self
    {
        return self::from(bcmul($this->value, $other->value));
    }

    public function divide(self $other): self
    {
        if ($other->compare(self::from(0)) === 0) {
            throw new InvalidArgumentException('Cannot divide an exact integer by zero.');
        }

        return self::from(bcdiv($this->value, $other->value, 0));
    }

    public function floorDivide(self $positiveDivisor): self
    {
        if ($positiveDivisor->compare(self::from(0)) !== 1) {
            throw new InvalidArgumentException('Floor division requires a positive divisor.');
        }

        $quotient = $this->divide($positiveDivisor);

        return $this->remainder($positiveDivisor)->compare(self::from(0)) === -1
            ? $quotient->subtract(self::from(1))
            : $quotient;
    }

    public function remainder(self $other): self
    {
        if ($other->compare(self::from(0)) === 0) {
            throw new InvalidArgumentException('Cannot divide an exact integer by zero.');
        }

        return self::from(bcmod($this->value, $other->value));
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
