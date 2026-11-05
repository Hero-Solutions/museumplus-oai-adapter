<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

final class MuseumPlusSearch
{
    public const FIELD_PATH = 'ObjWebPublicationGrp.WebPublicationVoc';
    public const ORG_FIELD_PATH = '__orgUnit';

    /** @return list<string> */
    public static function parsePublicationIds(string $value): array
    {
        return self::parseIds($value, self::FIELD_PATH);
    }

    /** @return list<string> */
    public static function parseOrgUnits(string $value): array
    {
        return self::parseIds($value, self::ORG_FIELD_PATH);
    }

    /** @param list<string> $ids */
    public static function xml(array $ids, int $limit, int $offset, string $fieldPath = self::FIELD_PATH): string
    {
        $ids = self::validateIds($ids, $fieldPath);
        if ($limit < 1 || $offset < 0) {
            throw new RuntimeException('MuseumPlus pagination requires a positive limit and a non-negative offset.');
        }
        $criteria = array_map(
            static fn (string $id): string => sprintf('<equalsField fieldPath="%s" operand="%s"/>', $fieldPath, $id),
            $ids,
        );
        $criterion = count($criteria) === 1 ? $criteria[0] : '<or>'.implode('', $criteria).'</or>';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <application xmlns="http://www.zetcom.com/ria/ws/module/search">
              <modules>
                <module name="Object">
                  <search limit="$limit" offset="$offset">
                    <expert>$criterion</expert>
                  </search>
                </module>
              </modules>
            </application>
            XML;
    }

    /** @return list<string> */
    private static function parseIds(string $value, string $fieldPath): array
    {
        return self::validateIds(preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [], $fieldPath);
    }

    /** @param list<string> $ids
     *  @return list<string>
     */
    private static function validateIds(array $ids, string $fieldPath): array
    {
        [$pattern, $variable, $description] = match ($fieldPath) {
            self::FIELD_PATH => ['/^[1-9][0-9]*$/D', 'MUSEUMPLUS_WEBPUBLICATION_IDS', 'positive numeric IDs'],
            self::ORG_FIELD_PATH => ['/^org_[0-9]+_[A-Za-z0-9_-]+$/D', 'MUSEUMPLUS_ORG_UNITS', 'organisation codes'],
            default => throw new RuntimeException('Unsupported MuseumPlus search field.'),
        };
        if ($ids === []) {
            throw new RuntimeException($variable.' must contain at least one value.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match($pattern, $id) !== 1) {
                throw new RuntimeException($variable.' must contain '.$description.', separated by commas or whitespace.');
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
