<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

final class MuseumPlusSearch
{
    public const FIELD_PATH = 'ObjWebPublicationGrp.WebPublicationVoc';

    /** @return list<string> */
    public static function parsePublicationIds(string $value): array
    {
        return self::validateIds(preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @param list<string> $publicationIds */
    public static function xml(array $publicationIds, int $limit, int $offset): string
    {
        $publicationIds = self::validateIds($publicationIds);
        if ($limit < 1 || $offset < 0) {
            throw new RuntimeException('MuseumPlus pagination requires a positive limit and a non-negative offset.');
        }
        $criteria = array_map(
            static fn (string $id): string => sprintf('<equalsField fieldPath="%s" operand="%s"/>', self::FIELD_PATH, $id),
            $publicationIds,
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

    /** @param list<string> $ids
     *  @return list<string>
     */
    private static function validateIds(array $ids): array
    {
        if ($ids === []) {
            throw new RuntimeException('MUSEUMPLUS_WEBPUBLICATION_IDS must contain at least one positive numeric ID.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/^[1-9][0-9]*$/D', $id) !== 1) {
                throw new RuntimeException('MUSEUMPLUS_WEBPUBLICATION_IDS must contain positive numeric IDs, separated by commas or whitespace.');
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
