<?php
declare(strict_types=1);

namespace App\Axiell;

use App\Mapping\RecordValues;

/** Frozen object identities only. No terminology or record content may be read from this index. */
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
            if ($json === false) throw new \RuntimeException('Missing Axiell identity index.');
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (hash_file('sha256', $file.'l') !== $data['sha256']) throw new \RuntimeException('Axiell reference index and data disagree.');
            self::$cache[$file] = [$data, hash('sha256', $json)];
        }
        [$this->data, $this->hash] = self::$cache[$file];
        $this->stream = fopen($file.'l', 'rb');
        if ($this->stream === false) throw new \RuntimeException('Cannot read Axiell identity index.');
    }

    public function fingerprint(): string { return $this->hash; }
    public static function clean(string $v): string { return trim(preg_replace('/\s+/u', ' ', $v) ?? $v); }

    private function record(RecordValues $record, AxiellProfile $profile): array
    {
        $ref = $this->lookup('records', $profile->value.'|'.$record->sourceIdentifier) ?? [];
        return ($ref['number'] ?? null) === self::clean($record->objectNumber ?? '')
            && ($ref['institution'] ?? null) === self::clean($record->valuesByPath['Naam_bewaarinstelling::institution.name'][0] ?? '') ? array_intersect_key($ref, array_flip(['number', 'institution', 'priref'])) : [];
    }

    /** One verified object identity across all sets. */
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

}
