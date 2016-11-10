<?php

/*
 * This file is part of the "elao/enum" package.
 *
 * Copyright (C) 2016 Elao
 *
 * @author Elao <contact@elao.com>
 */

namespace Elao\Enum;

use Elao\Enum\Exception\InvalidValueException;
use Elao\Enum\Exception\LogicException;

abstract class FlaggedEnum extends ReadableEnum
{
    const NONE = 0;

    /** @var array */
    private static $masks = [];

    /** @var int[] */
    protected $flags;

    /**
     * {@inheritdoc}
     */
    public static function accepts($value): bool
    {
        if (!is_int($value)) {
            throw new InvalidValueException($value, static::class);
        }

        if ($value === self::NONE) {
            return true;
        }

        return $value === ($value & self::getBitmask());
    }

    /**
     * {@inheritdoc}
     *
     * @param string $separator A delimiter used between each bit flag's readable string
     */
    public static function readableFor($value, string $separator = '; '): string
    {
        if (!static::accepts($value)) {
            throw new InvalidValueException($value, static::class);
        }
        if ($value === self::NONE) {
            return static::readableForNone();
        }

        $humanRepresentations = static::readables();

        if (isset($humanRepresentations[$value])) {
            return $humanRepresentations[$value];
        }

        $parts = [];

        foreach ($humanRepresentations as $flag => $readableValue) {
            if ($flag === ($flag & $value)) {
                $parts[] = $readableValue;
            }
        }

        return implode($separator, $parts);
    }

    /**
     * Gets the human representation for the none value.
     *
     * @return string
     */
    protected static function readableForNone(): string
    {
        return 'None';
    }

    /**
     * Gets an integer value of the possible flags for enumeration.
     *
     * @throws LogicException If the possibles values are not valid bit flags
     *
     * @return int
     */
    private static function getBitmask(): int
    {
        $enumType = static::class;

        if (!isset(self::$masks[$enumType])) {
            $mask = 0;
            foreach (static::values() as $flag) {
                if ($flag < 1 || ($flag > 1 && ($flag % 2) !== 0)) {
                    throw new LogicException(sprintf(
                        'Possible value %s of the enumeration "%s" is not a bit flag.',
                        json_encode($flag),
                        static::class
                    ));
                }
                $mask |= $flag;
            }
            self::$masks[$enumType] = $mask;
        }

        return self::$masks[$enumType];
    }

    /**
     * {@inheritdoc}
     *
     * @param string $separator A delimiter used between each bit flag's readable string
     */
    public function getReadable(string $separator = '; '): string
    {
        return static::readableFor($this->getValue(), $separator);
    }

    /**
     * Gets an array of bit flags of the value.
     *
     * @return array
     */
    public function getFlags(): array
    {
        if ($this->flags === null) {
            $this->flags = [];
            foreach (static::values() as $flag) {
                if ($this->hasFlag($flag)) {
                    $this->flags[] = $flag;
                }
            }
        }

        return $this->flags;
    }

    /**
     * Determines whether the specified flag is set in a numeric value.
     *
     * @param int $bitFlag The bit flag or bit flags
     *
     * @return bool True if the bit flag or bit flags are also set in the current instance; otherwise, false
     */
    public function hasFlag(int $bitFlag): bool
    {
        if ($bitFlag >= 1) {
            return $bitFlag === ($bitFlag & $this->value);
        }

        return false;
    }

    /**
     * Adds a bitmask to the value of this instance.
     *
     * @param int $flags The bit flag or bit flags
     *
     * @throws InvalidValueException When $flags is not acceptable for this enumeration type
     *
     * @return static The enum instance for computed value
     */
    public function addFlags(int $flags): self
    {
        if (!static::accepts($flags)) {
            throw new InvalidValueException($flags, static::class);
        }

        return static::get($this->value | $flags);
    }

    /**
     * Removes a bitmask from the value of this instance.
     *
     * @param int $flags The bit flag or bit flags
     *
     * @throws InvalidValueException When $flags is not acceptable for this enumeration type
     *
     * @return static The enum instance for computed value
     */
    public function removeFlags(int $flags): self
    {
        if (!static::accepts($flags)) {
            throw new InvalidValueException($flags, static::class);
        }

        return static::get($this->value & ~$flags);
    }
}
