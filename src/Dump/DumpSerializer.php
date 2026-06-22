<?php

declare(strict_types=1);

namespace Lookout\Tracing\Dump;

/**
 * Serializes an arbitrary PHP value into a normalized, language-agnostic dump tree.
 *
 * Node shape: {type, class?, key?, value?, preview?, children?, truncated?, ref?}. Bounded by depth,
 * child count, string length and total size, with cycle detection and key-based redaction so secrets
 * never leave the process.
 *
 * @phpstan-type DumpNode array<string, mixed>
 */
final class DumpSerializer
{
    private int $totalBytes = 0;

    private bool $truncated = false;

    /** @var array<int, true> */
    private array $seen = [];

    /**
     * @param  array{
     *     max_depth?: int,
     *     max_children?: int,
     *     max_string?: int,
     *     max_total_bytes?: int,
     *     redact_keys?: list<string>,
     * }  $options
     */
    public function __construct(private array $options = []) {}

    /**
     * @return array{tree: DumpNode, preview: string, root_type: string, root_class: ?string, truncated: bool}
     */
    public function serialize(mixed $value, ?string $label = null): array
    {
        $this->totalBytes = 0;
        $this->truncated = false;
        $this->seen = [];

        $tree = $this->node($value, $label, 0);

        return [
            'tree' => $tree,
            'preview' => is_string($tree['preview'] ?? null) ? $tree['preview'] : $this->describe($value),
            'root_type' => is_string($tree['type'] ?? null) ? $tree['type'] : $this->typeOf($value),
            'root_class' => is_object($value) ? $value::class : null,
            'truncated' => $this->truncated,
        ];
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * @return DumpNode
     */
    private function node(mixed $value, ?string $key, int $depth): array
    {
        $node = [];
        if ($key !== null) {
            $node['key'] = $this->cap((string) $key, 128);
        }

        if ($key !== null && $this->shouldRedact((string) $key)) {
            $this->truncated = true;
            $node['type'] = 'redacted';
            $node['preview'] = '[redacted]';

            return $node;
        }

        if ($depth >= $this->maxDepth()) {
            $this->truncated = true;
            $node['type'] = 'truncated';
            $node['preview'] = $this->describe($value);

            return $node;
        }

        if (is_array($value)) {
            return $this->container($node, $value, 'array', null, $depth);
        }

        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($this->seen[$id])) {
                $node['type'] = 'ref';
                $node['ref'] = $id;
                $node['preview'] = $value::class.' {ref #'.$id.'}';

                return $node;
            }
            $this->seen[$id] = true;
            $props = $this->objectProperties($value);
            $node = $this->container($node, $props, 'object', $value::class, $depth);
            unset($this->seen[$id]);

            return $node;
        }

        return $this->scalar($node, $value);
    }

    /**
     * @param  DumpNode  $node
     * @param  array<array-key, mixed>  $items
     * @return DumpNode
     */
    private function container(array $node, array $items, string $type, ?string $class, int $depth): array
    {
        $node['type'] = $type;
        if ($class !== null) {
            $node['class'] = $this->cap($class, 255);
        }

        $count = count($items);
        $node['preview'] = $class !== null
            ? $class.' {#'.$count.'}'
            : 'array:'.$count.' […]';

        $maxChildren = $this->maxChildren();
        $children = [];
        $i = 0;
        foreach ($items as $k => $v) {
            if ($i >= $maxChildren) {
                $this->truncated = true;
                $children[] = [
                    'type' => 'truncated',
                    'preview' => '+'.($count - $maxChildren).' more',
                ];
                break;
            }
            if ($this->totalBytes >= $this->maxTotalBytes()) {
                $this->truncated = true;
                $children[] = ['type' => 'truncated', 'preview' => '…'];
                break;
            }
            $children[] = $this->node($v, (string) $k, $depth + 1);
            $i++;
        }

        if ($children !== []) {
            $node['children'] = $children;
        }

        return $node;
    }

    /**
     * @param  DumpNode  $node
     * @return DumpNode
     */
    private function scalar(array $node, mixed $value): array
    {
        $node['type'] = $this->typeOf($value);

        if (is_string($value)) {
            $max = $this->maxString();
            $capped = $this->cap($value, $max);
            if (strlen($capped) < strlen($value)) {
                $this->truncated = true;
                $node['truncated'] = true;
            }
            $node['value'] = $capped;
            $this->totalBytes += strlen($capped);

            return $node;
        }

        if (is_resource($value)) {
            $node['type'] = 'resource';
            $node['preview'] = 'resource ('.get_resource_type($value).')';

            return $node;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            $node['value'] = $value;
            $this->totalBytes += 8;

            return $node;
        }

        $node['value'] = $this->cap((string) json_encode($value), $this->maxString());

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function objectProperties(object $value): array
    {
        if ($value instanceof \JsonSerializable) {
            $data = $value->jsonSerialize();
            if (is_array($data)) {
                return $data;
            }
        }

        $out = [];
        try {
            $reflection = new \ReflectionObject($value);
            foreach ($reflection->getProperties() as $prop) {
                if ($prop->isStatic()) {
                    continue;
                }
                $prop->setAccessible(true);
                if (! $prop->isInitialized($value)) {
                    continue;
                }
                $out[$prop->getName()] = $prop->getValue($value);
            }
        } catch (\Throwable) {
            return get_object_vars($value);
        }

        return $out;
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            $value === null => 'null',
            is_array($value) => 'array',
            is_object($value) => 'object',
            default => get_debug_type($value),
        };
    }

    private function describe(mixed $value): string
    {
        if (is_object($value)) {
            return $value::class;
        }
        if (is_array($value)) {
            return 'array:'.count($value);
        }
        if (is_string($value)) {
            return $this->cap($value, 64);
        }

        return get_debug_type($value);
    }

    private function shouldRedact(string $key): bool
    {
        $needle = strtolower($key);
        foreach ($this->redactKeys() as $bad) {
            if ($needle === $bad || str_contains($needle, $bad)) {
                return true;
            }
        }

        return false;
    }

    private function cap(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    private function maxDepth(): int
    {
        return max(1, (int) ($this->options['max_depth'] ?? 6));
    }

    private function maxChildren(): int
    {
        return max(1, (int) ($this->options['max_children'] ?? 100));
    }

    private function maxString(): int
    {
        return max(64, (int) ($this->options['max_string'] ?? 8192));
    }

    private function maxTotalBytes(): int
    {
        return max(1024, (int) ($this->options['max_total_bytes'] ?? 262144));
    }

    /**
     * @return list<string>
     */
    private function redactKeys(): array
    {
        $keys = $this->options['redact_keys'] ?? null;

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : self::DEFAULT_REDACT_KEYS;
    }

    /** @var list<string> */
    public const DEFAULT_REDACT_KEYS = [
        'password', 'pass', 'pwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'auth', 'access_token', 'refresh_token', 'private_key',
        'card', 'card_number', 'cvv', 'cvc', 'ssn',
    ];
}
