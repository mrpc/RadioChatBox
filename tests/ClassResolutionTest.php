<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Static guard that every class name referenced in src/ actually resolves.
 *
 * The `new X`, `X::`, `extends`/`implements`, `catch`, `instanceof` and
 * attribute (`#[X]`) references in each source file are tokenised and resolved
 * against that file's namespace + `use` imports (plus the global namespace). Any
 * name that resolves to no class/interface/trait/enum fails the test.
 *
 * This specifically catches the failure mode of moving classes between
 * namespaces: an unqualified same-namespace reference (e.g. `Log::write()`) that
 * silently breaks when its file moves to a sub-namespace and the matching `use`
 * import is forgotten — a runtime-fatal that ordinary tests only hit on the exact
 * code path. Here it is caught at load-independent parse time, for every file.
 */
class ClassResolutionTest extends TestCase
{
    /** Names that are language constructs / type keywords, not classes. */
    private const NON_CLASS = [
        'self', 'static', 'parent', 'true', 'false', 'null', 'int', 'float',
        'string', 'bool', 'array', 'object', 'callable', 'iterable', 'void',
        'mixed', 'never', 'fn', 'class',
    ];

    /**
     * Every referenced class in src/ resolves to a real class/interface/trait/enum.
     */
    public function testEverySourceClassReferenceResolves(): void
    {
        $known    = $this->knownShortNames();
        $failures = [];
        foreach ($this->sourceFiles() as $file) {
            foreach ($this->unresolved($file, $known) as $problem) {
                $failures[] = $problem;
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Unresolvable class references (missing use import or wrong namespace):\n"
                . implode("\n", $failures)
        );
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $dir = dirname(__DIR__) . '/src';
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * @param array<string,true> $known lowercased short names of app classes
     * @return list<string> "file:line → Name" for each unresolvable reference
     */
    private function unresolved(string $file, array $known): array
    {
        $tokens    = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $aliases   = []; // short name (lower) => FQCN
        $problems  = [];
        $count     = count($tokens);
        $rel       = substr($file, strlen(dirname(__DIR__)) + 1);

        // First pass: namespace + use imports.
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $namespace = $this->readName($tokens, $i + 1);
            } elseif ($t[0] === T_USE && !$this->isClosureUse($tokens, $i)) {
                foreach ($this->readUseGroup($tokens, $i) as [$fqcn, $alias]) {
                    $aliases[strtolower($alias)] = $fqcn;
                }
            }
        }

        // Second pass: class references.
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            $ref = null;
            $line = $t[2];
            switch ($t[0]) {
                case T_NEW:
                case T_INSTANCEOF:
                case T_EXTENDS:
                case T_IMPLEMENTS:
                    $ref = $this->readName($tokens, $i + 1);
                    break;
                case T_ATTRIBUTE: // #[Name(...)]
                    $ref = $this->readName($tokens, $i + 1);
                    break;
                case T_STRING:
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                    // Static reference X:: — only when immediately followed by ::
                    $j = $this->nextMeaningful($tokens, $i + 1);
                    if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) {
                        $ref = $t[1];
                        break;
                    }
                    // Any BARE occurrence of a known app class name (catches type
                    // hints, return types, typed properties, etc. that the forms
                    // above miss) — unless it is a method/property access or a
                    // declaration name.
                    if ($t[0] === T_STRING && isset($known[strtolower($t[1])])) {
                        $prev = $this->prevMeaningful($tokens, $i - 1);
                        $skip = $prev !== null && is_array($tokens[$prev]) && in_array(
                            $tokens[$prev][0],
                            [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST,
                             T_CLASS, T_INTERFACE, T_TRAIT, T_NAMESPACE, T_AS, T_USE],
                            true
                        );
                        // Also skip nullsafe -> and a following ( that means a call.
                        if (!$skip && $prev !== null && $tokens[$prev] === '(') {
                            $nx = $this->nextMeaningful($tokens, $i + 1);
                            // `foo(BAR)` where BAR is followed by ) is not a type.
                            if ($nx !== null && $tokens[$nx] === ')') {
                                $skip = true;
                            }
                        }
                        if (!$skip) {
                            $ref = $t[1];
                        }
                    }
                    break;
            }
            if ($ref === null || $ref === '') {
                continue;
            }
            foreach ($this->splitImplementsList($ref) as $name) {
                if (!$this->resolves($name, $namespace, $aliases)) {
                    $problems[] = "{$rel}:{$line} → {$name}";
                }
            }
        }

        return $problems;
    }

    /** implements A, B → ['A','B']; everything else → [name]. */
    private function splitImplementsList(string $name): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $name))));
    }

    private function resolves(string $name, string $namespace, array $aliases): bool
    {
        $name = trim($name);
        if ($name === '' || in_array(strtolower($name), self::NON_CLASS, true)) {
            return true;
        }

        // Fully qualified.
        if ($name[0] === '\\') {
            return $this->exists(ltrim($name, '\\'));
        }

        // Qualified (A\B\C): resolve first segment via alias, else prepend namespace.
        if (str_contains($name, '\\')) {
            $parts = explode('\\', $name);
            $first = strtolower($parts[0]);
            if (isset($aliases[$first])) {
                $parts[0] = $aliases[$first];
                return $this->exists(implode('\\', $parts));
            }
            $candidate = ($namespace !== '' ? $namespace . '\\' : '') . $name;
            return $this->exists($candidate) || $this->exists($name);
        }

        // Simple name.
        $lower = strtolower($name);
        if (isset($aliases[$lower])) {
            return $this->exists($aliases[$lower]);
        }
        if ($namespace !== '' && $this->exists($namespace . '\\' . $name)) {
            return true;
        }
        return $this->exists($name); // global namespace
    }

    private function exists(string $fqcn): bool
    {
        return class_exists($fqcn) || interface_exists($fqcn)
            || trait_exists($fqcn) || (function_exists('enum_exists') && enum_exists($fqcn));
    }

    /** Read a (possibly qualified) name starting at the next meaningful token. */
    private function readName(array $tokens, int $i): string
    {
        $i = $this->nextMeaningful($tokens, $i);
        if ($i === null || !is_array($tokens[$i])) {
            return '';
        }
        if (in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return $tokens[$i][1];
        }
        return '';
    }

    /** For `implements A, B`: read the full comma list of names. */
    private function nextMeaningful(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for (; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /** Previous meaningful token index (skipping whitespace/comments). */
    private function prevMeaningful(array $tokens, int $i): ?int
    {
        for (; $i >= 0; $i--) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * Lowercased short names of every class/interface/trait/enum declared under
     * src/, so bare references to them (including in type positions) can be
     * verified to resolve in each referencing file.
     *
     * @return array<string,true>
     */
    private function knownShortNames(): array
    {
        $known = [];
        foreach ($this->sourceFiles() as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $count  = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                $t = $tokens[$i];
                if (!is_array($t)) {
                    continue;
                }
                if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)
                    || (defined('T_ENUM') && $t[0] === T_ENUM)) {
                    // Skip anonymous class (new class ...) and ::class.
                    $prev = $this->prevMeaningful($tokens, $i - 1);
                    if ($prev !== null && is_array($tokens[$prev])
                        && in_array($tokens[$prev][0], [T_NEW, T_DOUBLE_COLON], true)) {
                        continue;
                    }
                    $name = $this->readName($tokens, $i + 1);
                    if ($name !== '') {
                        $known[strtolower($name)] = true;
                    }
                }
            }
        }
        return $known;
    }

    /** Distinguish statement `use A\B;` from closure `function() use ($x)`. */
    private function isClosureUse(array $tokens, int $i): bool
    {
        $j = $this->nextMeaningful($tokens, $i + 1);
        return $j !== null && $tokens[$j] === '(';
    }

    /**
     * Read a use statement (single or group) into [[fqcn, alias], ...].
     * Handles `use A\B;`, `use A\B as C;`. Group-use is uncommon here; if seen,
     * each member is expanded.
     *
     * @return list<array{0:string,1:string}>
     */
    private function readUseGroup(array $tokens, int $start): array
    {
        $count = count($tokens);
        $buf   = '';
        for ($i = $start + 1; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === ';') {
                break;
            }
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $buf .= ' ';
                    continue;
                }
                // Skip `function`/`const` imports — not class imports.
                if ($t[0] === T_FUNCTION || $t[0] === T_CONST) {
                    return [];
                }
                $buf .= $t[1];
            } else {
                $buf .= $t;
            }
        }

        $buf = trim($buf);
        if ($buf === '') {
            return [];
        }

        // `As` keyword tokenises inside the buffer as text — normalise.
        $entries = [];
        // Group use: Prefix\{A, B as C}
        if (preg_match('/^(.*)\\\\\{(.+)\}$/s', $buf, $m)) {
            $prefix = trim($m[1]);
            foreach (explode(',', $m[2]) as $member) {
                $entries[] = trim($prefix . '\\' . trim($member));
            }
        } else {
            $entries[] = $buf;
        }

        $result = [];
        foreach ($entries as $entry) {
            $entry = preg_replace('/\s+/', ' ', trim($entry));
            if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $entry, $m)) {
                $fqcn  = trim($m[1]);
                $alias = $m[2];
            } else {
                $fqcn  = $entry;
                $alias = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
            }
            if ($fqcn !== '' && $alias !== '') {
                $result[] = [$fqcn, $alias];
            }
        }
        return $result;
    }
}
