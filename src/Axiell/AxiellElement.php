<?php

declare(strict_types=1);

namespace App\Axiell;

final readonly class AxiellElement
{
    /**
     * @param list<self> $children
     */
    public function __construct(
        public string $name,
        public ?string $value = null,
        public array $children = [],
    ) {
    }

    public static function text(string $name, ?string $value): ?self
    {
        $value = self::clean($value);

        return $value === null ? null : new self($name, $value);
    }

    /**
     * @param list<self|null> $children
     */
    public static function node(string $name, array $children): ?self
    {
        $children = array_values(array_filter($children));

        return $children === [] ? null : new self($name, null, $children);
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8')) ?? $value;
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
