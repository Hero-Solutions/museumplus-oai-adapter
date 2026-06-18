<?php

declare(strict_types=1);

namespace App\Axiell;

use Twig\Environment;

final class AxiellXmlRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    public function renderRecord(AxiellRecord $record): string
    {
        return trim($this->twig->render('axiell/record.xml.twig', [
            'record' => $record,
        ])).PHP_EOL;
    }
}
