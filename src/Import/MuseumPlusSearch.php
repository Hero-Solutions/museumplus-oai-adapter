<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

final class MuseumPlusSearch
{
    /** @return list<string> */
    public static function parseOrgUnits(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $orgUnits = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (!$orgUnits) {
            throw new RuntimeException('MUSEUMPLUS_ORG_UNITS must contain organisation codes or be empty.');
        }

        $orgUnits = array_values(array_unique($orgUnits));
        sort($orgUnits, SORT_STRING);

        return $orgUnits;
    }

    /** @param list<string> $orgUnits */
    public static function xml(string $fieldPath, string $operand, int $limit, int $offset, array $orgUnits = []): string
    {
        $criterion = sprintf(
            '<equalsField fieldPath="%s" operand="%s"/>',
            self::escape($fieldPath),
            self::escape($operand),
        );

        if ($orgUnits !== []) {
            $organisations = array_map(
                static fn (string $orgUnit): string => sprintf(
                    '<equalsField fieldPath="__orgUnit" operand="%s"/>',
                    self::escape($orgUnit),
                ),
                $orgUnits,
            );
            $criterion = '<and>'.$criterion.'<or>'.implode('', $organisations).'</or></and>';
        }

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

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
