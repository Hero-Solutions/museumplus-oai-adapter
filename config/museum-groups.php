<?php

// Museum routing only; PublicationSelector separately checks the publication flags.
// A bare Groeningemuseum label belongs to Brugge (confirmed by the collection operator).
return [
    'mskObj' => [
        'name' => 'Museum voor Schone Kunsten Gent', 'org_prefix' => 'org_31_',
        'name_translations' => [
            'fr' => 'Musée des Beaux-Arts de Gand',
            'en' => 'Museum of Fine Arts Ghent',
        ],
        'institutions' => ['Museum voor Schone Kunsten Gent', 'Sint-Baafskathedraal Gent', 'Sint-Niklaaskerk Willebroek', 'Voormalige collectie MSK Gent'],
        // Empty MuseumTxt in the supplied export; owner, collection and matching workPID all identify MSK.
        'records_without_institution' => ['25842813' => '1949-AU-9-verso'],
    ],
    'musea_brugge' => [
        'name' => 'Musea Brugge', 'org_prefix' => 'org_1_',
        'institutions' => ['Musea Brugge', 'Groeningemuseum', 'Gruuthusemuseum', 'Sint-Janshospitaal', 'Museum Sint-Janshospitaal', 'Onze-Lieve-Vrouw ter Potterie', 'Stadsarchief Brugge', 'Bibliotheek Biekorf Brugge', 'Bisdom Brugge', 'Edele Confrerie van het Heilig Bloed', 'Erfgoedbibliotheek Biekorf', 'extern', 'Brugse Vrije', 'Privéverzameling Brugge', 'In Flanders Fields Museum Musea Brugge', 'Museum voor Schone Kunsten Gent Musea Brugge'],
    ],
    'muzee' => [
        'name' => 'Mu.ZEE, Kunstmuseum aan zee', 'org_prefix' => 'org_32_',
        'institutions' => ['Mu.ZEE, Kunstmuseum aan zee', 'Stad Oostende', 'Provincie West-Vlaanderen', 'particulier', 'Onbekend', 'Koninklijk Museum voor Midden-Afrika', 'Koninklijk Museum voor Schone Kunsten Antwerpen (KMSKA)', 'Fondation Jeanne Matossian', 'Fonds Raoul Servais', 'Delen Private Bank', 'Groeningemuseum MuZee'],
    ],
];
