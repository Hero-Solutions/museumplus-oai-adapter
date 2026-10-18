<?php
declare(strict_types=1);

namespace App\Axiell;

use App\Mapping\RecordValues;

/** Frozen terminology/interpretation hints, never a substitute for current record values. */
final class AxiellReference
{
    private static array $cache = [];
    private array $data;
    private string $hash;
    private $stream;
    private array $entries = [];

    public function __construct(string $file = __DIR__.'/../../config/axiell-reference.json')
    {
        if (!isset(self::$cache[$file])) {
            $json = file_get_contents($file);
            if ($json === false) throw new \RuntimeException('Missing Axiell compatibility dictionary.');
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (hash_file('sha256', $file.'l') !== $data['sha256']) throw new \RuntimeException('Axiell reference index and data disagree.');
            self::$cache[$file] = [$data, hash('sha256', $json)];
        }
        [$this->data, $this->hash] = self::$cache[$file];
        $this->stream = fopen($file.'l', 'rb');
        if ($this->stream === false) throw new \RuntimeException('Cannot read Axiell compatibility dictionary.');
    }

    public function fingerprint(): string { return $this->hash; }
    public static function clean(string $v): string { return trim(preg_replace('/\s+/u', ' ', $v) ?? $v); }

    public function record(RecordValues $record, AxiellProfile $profile): array
    {
        $ref = $this->lookup('records', $profile->value.'|'.$record->sourceIdentifier) ?? [];
        return ($ref['number'] ?? null) === self::clean($record->objectNumber ?? '')
            && ($ref['institution'] ?? null) === self::clean($record->valuesByPath['Naam_bewaarinstelling::institution.name'][0] ?? '') ? $ref : [];
    }

    /** One object identity across sets; field interpretations remain profile-specific. */
    public function priref(RecordValues $record): string
    {
        $ids = [];
        foreach (AxiellProfile::cases() as $profile) {
            $ref = $this->record($record, $profile);
            if (isset($ref['priref'])) $ids[(string) $ref['priref']] = true;
        }
        if (count($ids) > 1) {
            throw new \RuntimeException('Conflicting Axiell identities for MuseumPlus '.$record->sourceIdentifier.'.');
        }

        return $ids === [] ? $record->sourceIdentifier : (string) array_key_first($ids);
    }

    private function lookup(string $kind, string $key): mixed
    {
        $key = $kind.':'.$key;
        if (!isset($this->data['index'][$key])) return null;
        if (!array_key_exists($key, $this->entries)) {
            if (count($this->entries) >= 512) $this->entries = [];
            fseek($this->stream, $this->data['index'][$key]);
            $this->entries[$key] = json_decode(fgets($this->stream), true, flags: JSON_THROW_ON_ERROR);
        }
        return $this->entries[$key];
    }

    public function element(array $tree): AxiellElement
    {
        return new AxiellElement($tree['name'], $tree['value'], array_map($this->element(...), $tree['children']), $tree['attributes']);
    }

    public function enumeration(string $name, string $value, ?array $verified = null): AxiellElement
    {
        $aliases = ['eigen afgebeelde locatie'=>'GEOKEYW','eigen afgebeelde gebeurtenis'=>'ACTIV','afgebeelde gebeurtenis'=>'EVENT'];
        if ($verified !== null) {
            $labels = array_column($verified['children'], 'value');
            $ambiguousCodes = match ($value) {
                'institution' => ['PERSON', 'INST'],
                'eigen afgebeeld object' => ['OBJECT', 'SUBJECT'],
                default => [],
            };
            // Old types may disambiguate an unchanged label, never override a newly explicit type.
            if (in_array($aliases[$value] ?? $value, $labels, true) || array_intersect($ambiguousCodes, $labels) !== []) return $this->element($verified);
        }
        $key = $name.'|'.($aliases[$value] ?? $value);
        $values = $this->lookup('enums', $key);
        $children = $values !== null ? array_map($this->element(...), $values) : [new AxiellElement('value', $value, [], ['lang'=>'1'])];
        return new AxiellElement($name, null, $children);
    }

    public function enrich(array $elements, AxiellProfile $profile, string $parent = ''): array
    {
        $result = [];
        foreach ($elements as $element) {
            $path = $parent === '' ? $element->name : $parent.'/'.$element->name;
            $children = $this->enrich($element->children, $profile, $path);
            $label = null;
            foreach ($children as $c) if (in_array($c->name, ['term','name'], true)) { $label = $c->value; break; }
            if ($label !== null) {
                $existing = array_column($children, 'name');
                foreach ($this->lookup('terms', $profile->value.'|'.$path.'|'.self::clean($label)) ?? [] as $extra) {
                    if (!in_array($extra['name'], $existing, true)) $children[] = $this->element($extra);
                }
            }
            $result[] = new AxiellElement($element->name, $element->value, $children, $element->attributes);
        }
        return $result;
    }
}
