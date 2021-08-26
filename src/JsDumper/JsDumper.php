<?php

/*
 * This file is part of the "elao/enum" package.
 *
 * Copyright (C) Elao
 *
 * @author Elao <contact@elao.com>
 */

namespace Elao\Enum\JsDumper;

use Elao\Enum\EnumInterface;
use Elao\Enum\FlaggedEnum;
use Elao\Enum\ReadableEnumInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
class JsDumper
{
    public const DISCLAIMER = <<<JS
/*
 * This file was generated by the "elao/enum" PHP package.
 * The code inside belongs to you and can be altered, but no BC promise is guaranteed.
 */
JS;
    /** @var string */
    private $libPath;

    /** @var string|null */
    private $baseDir;

    /** @var Filesystem */
    private $fs;

    public function __construct(string $libPath, string $baseDir = null)
    {
        $this->fs = new Filesystem();
        $this->baseDir = $baseDir;
        $this->libPath = $this->normalizePath($libPath);
    }

    public function dumpLibrarySources()
    {
        $disclaimer = self::DISCLAIMER;
        $sourceCode = file_get_contents(__DIR__ . '/../../res/js/Enum.js');
        $this->baseDir !== null && $this->fs->mkdir($this->baseDir);
        $this->fs->dumpFile($this->libPath, "$disclaimer\n\n$sourceCode");
    }

    /**
     * @param class-string<EnumInterface> $enumFqcn
     */
    public function dumpEnumToFile(string $enumFqcn, string $path)
    {
        !file_exists($this->libPath) && $this->dumpLibrarySources();
        $this->baseDir !== null && $this->fs->mkdir($this->baseDir);

        $path = $this->normalizePath($path);

        $disclaimer = self::DISCLAIMER;
        $this->fs->dumpFile($path, "$disclaimer\n\n");

        $code = '';
        $code .= $this->dumpImports($path, $enumFqcn);
        $code .= $this->dumpEnumClass($enumFqcn);
        // End file with export
        $code .= "\nexport default {$this->getShortName($enumFqcn)}\n";

        // Dump to file:
        $this->fs->appendToFile($path, $code);
    }

    /**
     * @param class-string<EnumInterface> $enumFqcn
     */
    public function dumpEnumClass(string $enumFqcn): string
    {
        $code = '';
        $code .= $this->startClass($enumFqcn);
        $code .= $this->generateEnumerableValues($enumFqcn);
        $code .= $this->generateMasks($enumFqcn);
        $code .= $this->generateReadables($enumFqcn);
        $code .= "}\n"; // End class

        return $code;
    }

    private function dumpImports(string $path, string $enumFqcn): string
    {
        $relativeLibPath = preg_replace('#.js$#', '', rtrim(
            $this->fs->makePathRelative(realpath($this->libPath), realpath(\dirname($path))),
            '\\/'
        ));

        if (is_a($enumFqcn, FlaggedEnum::class, true)) {
            return "import { FlaggedEnum } from '$relativeLibPath';\n\n";
        }

        if (is_a($enumFqcn, ReadableEnumInterface::class, true)) {
            return "import { ReadableEnum } from '$relativeLibPath';\n\n";
        }

        return "import Enum from '$relativeLibPath';\n\n";
    }

    private function startClass(string $enumFqcn): string
    {
        $shortName = $this->getShortName($enumFqcn);
        $baseClass = 'Enum';

        if (is_a($enumFqcn, FlaggedEnum::class, true)) {
            $baseClass = 'FlaggedEnum';
        } elseif (is_a($enumFqcn, ReadableEnumInterface::class, true)) {
            $baseClass = 'ReadableEnum';
        }

        return "class $shortName extends $baseClass {\n";
    }

    private function generateEnumerableValues(string $enumFqcn): string
    {
        $code = '';
        foreach ($this->getEnumerableValues($enumFqcn) as $constant => $value) {
            $jsValue = \is_string($value) ? "'$value'" : $value;
            $code .= "  static $constant = $jsValue\n";
        }

        return $code;
    }

    private function generateMasks(string $enumFqcn)
    {
        if (!is_a($enumFqcn, FlaggedEnum::class, true)) {
            return '';
        }

        $code = "\n  // Named masks\n";
        foreach ($this->getMasks($enumFqcn) as $constant => $value) {
            $jsValue = \is_string($value) ? "'$value'" : $value;
            $code .= "  static $constant = $jsValue\n";
        }

        return $code;
    }

    private function generateReadables(string $enumFqcn): string
    {
        if (!is_a($enumFqcn, ReadableEnumInterface::class, true)) {
            return '';
        }

        $shortName = $this->getShortName($enumFqcn);
        // Get readable entries
        $readablesCode = '';
        $readables = $enumFqcn::readables();

        // Generate all values
        foreach ($this->getEnumerableValues($enumFqcn) as $constant => $value) {
            if (!$readable = $readables[$value] ?? false) {
                continue;
            }

            $flags = JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR;

            $readable = json_encode($readable, $flags);

            $readablesCode .=
                    <<<JS

      [{$shortName}.{$constant}]: {$readable},
JS;
        }

        if (is_a($enumFqcn, FlaggedEnum::class, true)) {
            $readablesCode .= "\n\n      // Named masks";
            foreach ($this->getMasks($enumFqcn) as $constant => $value) {
                if (!$readable = $readables[$value] ?? false) {
                    continue;
                }

                $flags = JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR;

                $readable = json_encode($readable, $flags);

                $readablesCode .=
                    <<<JS

      [{$shortName}.{$constant}]: {$readable},
JS;
            }
        }

        // Generate readables method:
        return <<<JS

  static get readables() {
    return {{$readablesCode}
    };
  }

JS;
    }

    /**
     * @param class-string<EnumInterface> $enumFqcn
     */
    private function getEnumerableValues(string $enumFqcn): array
    {
        $constants = $this->getConstants($enumFqcn);

        $enumerableValues = [];
        foreach ($constants as $constant) {
            $value = \constant("$enumFqcn::$constant");

            if (is_a($enumFqcn, FlaggedEnum::class, true)) {
                // Continue if not a bit flag:
                if (!(\is_int($value) && 0 === ($value & $value - 1) && $value > 0)) {
                    continue;
                }
            } elseif (!\is_int($value) && !\is_string($value)) {
                // Continue if not an int or string:
                continue;
            }

            $enumerableValues[$constant] = $value;
        }

        return $enumerableValues;
    }

    /**
     * @param class-string<FlaggedEnum> $enumFqcn
     */
    private function getMasks(string $enumFqcn): array
    {
        if (!is_a($enumFqcn, FlaggedEnum::class, true)) {
            return [];
        }

        $constants = $this->getConstants($enumFqcn);

        $masks = [];
        foreach ($constants as $constant) {
            $value = \constant("$enumFqcn::$constant");

            // Continue if it's not part of the flagged enum bitmask:
            if (!\is_int($value) || $value <= 0 || !$enumFqcn::accepts($value)) {
                continue;
            }

            // Continue it's a single bit flag:
            if (\in_array($value, $enumFqcn::values(), true)) {
                continue;
            }

            $masks[$constant] = $value;
        }

        return $masks;
    }

    /**
     * @param class-string<EnumInterface> $enumFqcn
     */
    private function getShortName(string $enumFqcn): string
    {
        static $cache = [];

        return $cache[$enumFqcn] = $cache[$enumFqcn] ?? (new \ReflectionClass($enumFqcn))->getShortName();
    }

    /**
     * @param class-string<EnumInterface> $enumFqcn
     */
    private function getConstants(string $enumFqcn): array
    {
        $r = new \ReflectionClass($enumFqcn);
        $constants = array_filter(
            $r->getConstants(),
            static function (string $k) use ($r, $enumFqcn) {
                $rConstant = $r->getReflectionConstant($k);
                $public = $rConstant->isPublic();
                $value = $rConstant->getValue();

                // Only keep public constants, for which value matches enumerable values set:
                return $public && $enumFqcn::accepts($value);
            },
            ARRAY_FILTER_USE_KEY
        );

        $constants = array_flip($constants);

        return $constants;
    }

    public function normalizePath(string $path): string
    {
        if (null === $this->baseDir) {
            return $path;
        }

        if ($this->fs->isAbsolutePath($path)) {
            return $path;
        }

        if (0 === strpos($path, './')) {
            return $path;
        }

        return rtrim($this->baseDir, '\\/') . '/' . $path;
    }
}
