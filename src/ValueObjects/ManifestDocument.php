<?php

declare(strict_types=1);

namespace Cbox\Engine\ValueObjects;

use RuntimeException;

/**
 * One Kubernetes object, holding the shape it arrived in.
 *
 * IT KEEPS OBJECTS AS OBJECTS, and that is the entire reason this class exists
 * rather than an associative array.
 *
 * PHP cannot tell an empty JSON object from an empty JSON array once both are
 * associative arrays, so `json_decode($raw, true)` followed by `json_encode`
 * silently rewrites every `{}` as `[]`. Measured on the Gateway API bundle: a
 * CustomResourceDefinition carrying `subresources: {status: {}}` — which is how
 * you say "this kind has a status subresource" — reached the API server as
 * `{status: []}` and was refused:
 *
 *     invalid type for ...CustomResourceSubresourceStatus: got "array",
 *     expected "map"
 *
 * Every vendored manifest and every compiled one is exposed to this, and the
 * failure lands at apply time against a real cluster with an error that reads
 * like a schema problem in upstream's chart.
 *
 * So the decoded value is carried untouched, and read through accessors that do
 * not care which it is.
 */
readonly class ManifestDocument
{
    private function __construct(public object $body) {}

    /**
     * Decode a JSON array of manifests without flattening its objects.
     *
     * @return list<self>
     */
    public static function listFromJson(string $json): array
    {
        // No `true`. That flag is the bug.
        $decoded = json_decode($json);

        if (! is_array($decoded)) {
            throw new RuntimeException('Expected a JSON array of Kubernetes objects.');
        }

        return array_values(array_map(
            static fn (object $document): self => new self($document),
            array_filter($decoded, is_object(...)),
        ));
    }

    /**
     * Wrap a manifest that arrives as a PHP array — the compiled path.
     *
     * WHAT THIS CANNOT DO, and it is worth being exact rather than implying
     * otherwise: an EMPTY PHP array carries no record of whether it meant `{}`
     * or `[]`, so `['data' => []]` becomes `"data":[]` and nothing here can
     * recover the intent. There is no flag that fixes it — `JSON_FORCE_OBJECT`
     * would turn genuine lists into objects, which is the same bug pointed the
     * other way.
     *
     * A caller that means an empty MAP has to say so, by passing an object:
     *
     *     ['data' => new stdClass]
     *
     * Everything non-empty survives, including nested maps, because the
     * conversion goes through JSON rather than a cast — a cast is shallow and
     * would leave `spec` an array.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        // Through JSON rather than a cast, so nested associative arrays become
        // nested objects too. A cast is shallow and would leave `spec` an array.
        $encoded = json_encode($body);
        $decoded = $encoded === false ? null : json_decode($encoded);

        if (! is_object($decoded)) {
            throw new RuntimeException('A manifest must be an object.');
        }

        return new self($decoded);
    }

    /**
     * A value from a path, when it is there and is the type asked for.
     *
     * Reading nested optional keys off a decoded document is the same three
     * lines everywhere it happens, and every copy is a place to forget one of
     * the checks. `metadata.labels` is absent more often than it is present.
     */
    public function stringAt(string ...$path): string
    {
        $value = $this->at(...$path);

        return is_string($value) ? $value : '';
    }

    public function intAt(string ...$path): int
    {
        $value = $this->at(...$path);

        return is_int($value) ? $value : 0;
    }

    /**
     * A list of strings from a path, with anything that is not one dropped.
     *
     * Dropped rather than refused, because this reads what a CLUSTER returned
     * and not what this tool wrote — a field somebody else set to a number is
     * a field this tool has no use for, not a reason to stop.
     *
     * @return list<string>
     */
    public function stringsAt(string ...$path): array
    {
        $value = $this->at(...$path);

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        $labels = $this->at('metadata', 'labels');

        if (! is_object($labels)) {
            return [];
        }

        /** @var array<string, string> $clean */
        $clean = array_filter((array) $labels, is_string(...));

        return $clean;
    }

    private function at(string ...$path): mixed
    {
        $node = $this->body;

        foreach ($path as $key) {
            if (! is_object($node) || ! property_exists($node, $key)) {
                return null;
            }

            $node = $node->{$key};
        }

        return $node;
    }

    public function kind(): string
    {
        return $this->stringAt('kind');
    }

    public function name(): string
    {
        return $this->stringAt('metadata', 'name');
    }
}
