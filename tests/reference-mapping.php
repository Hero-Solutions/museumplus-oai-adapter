<?php
declare(strict_types=1);

// Synthetic local dictionary and XML only. No kernel, environment or HTTP clients.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Axiell\{AxiellProfile, AxiellRecordMapper, AxiellReference, AxiellXmlRenderer};
use App\Mapping\{DigitalReferenceValidator, MuseumPlusExportParser, XmlValueExtractor};
use App\Normalizer\{CreatorNormalizer, DimensionNormalizer, LabelNormalizer};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function leaf(string $name, string $value, array $attributes = []): array { return ['name'=>$name,'value'=>$value,'children'=>[],'attributes'=>$attributes]; }
function type(string $code, string $nl): array {
    return ['name'=>'content.subject.type','value'=>null,'children'=>[
        leaf('value', $code, ['lang'=>'neutral']), leaf('value', 'English label', ['lang'=>'0']), leaf('value', $nl, ['lang'=>'1']),
    ],'attributes'=>[]];
}
function mapFixture(AxiellReference $reference, string $id = '123', string $number = 'REF-1', string $institution = 'Musea Brugge', string $materialNote = 'olieverf op doek', string $type = 'eigen afgebeeld object', AxiellProfile $profile = AxiellProfile::Vkc, ?string $materials = null): DOMXPath {
    $fields = [
        'NumberTxt'=>'<object_number>'.$number.'</object_number>',
        'MuseumTxt'=>'<institution.name>'.$institution.'</institution.name>',
        'MaterialTxt'=>$materials ?? '<Material><material>doek</material><material.notes>'.$materialNote.'</material.notes></Material>',
        'ContentSubjectTxt'=>'<Content_subject><content.subject>Voorwerp</content.subject><content.subject.type>'.$type.'</content.subject.type></Content_subject>',
        'ContentDateTxt'=>'<content.date.period>Parijs</content.date.period><content.date.period>19de eeuw</content.date.period>',
    ];
    $source = '<ObjectList><Object><priref>'.$id.'</priref>';
    foreach ($fields as $field=>$fragment) $source .= '<'.$field.'>'.htmlspecialchars($fragment, ENT_XML1|ENT_QUOTES, 'UTF-8').'</'.$field.'>';
    $record = (new MuseumPlusExportParser(new XmlValueExtractor()))->parse($source.'</Object></ObjectList>')[0];
    $mapper = new AxiellRecordMapper(new CreatorNormalizer(), new DimensionNormalizer(), new LabelNormalizer(), new DigitalReferenceValidator(), $reference);
    $renderer = new AxiellXmlRenderer(new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates')));
    $doc = new DOMDocument(); check($doc->loadXML($renderer->renderRecord($mapper->map($record->values, $profile)), LIBXML_NONET), 'Invalid output.');
    return new DOMXPath($doc);
}

$file = dirname(__DIR__).'/var/test-reference-'.bin2hex(random_bytes(6)).'.json';
$entries = [
    'records:vkc|123'=>['number'=>'REF-1','institution'=>'Musea Brugge','priref'=>'42',
        'types'=>['content.subject.type|Voorwerp'=>type('OBJECT','voorwerp')],
        'content_dates'=>['Parijs'=>['lref'=>'77','type'=>type('GEOKEYW','geografisch trefwoord')]],
        'material_description'=>['olieverf op doek']],
    'terms:vkc|Material/material|doek'=>[leaf('notes','canvas')],
    'terms:mskObj|Material/material|doek'=>[leaf('notes','ancienne traduction'), leaf('notes','old translation')],
    'enums:content.subject.type|onderwerp'=>type('SUBJECT','onderwerp')['children'],
    'records:vkc|999'=>['number'=>'CONFLICT','institution'=>'Musea Brugge','priref'=>'40'],
    'records:musea_brugge|999'=>['number'=>'CONFLICT','institution'=>'Musea Brugge','priref'=>'41'],
];
$lines = ''; $index = [];
foreach ($entries as $key=>$entry) { $index[$key] = strlen($lines); $lines .= json_encode($entry, JSON_THROW_ON_ERROR)."\n"; }
file_put_contents($file.'l', $lines);
file_put_contents($file, json_encode(['version'=>1,'sha256'=>hash('sha256',$lines),'index'=>$index], JSON_THROW_ON_ERROR));
try {
    $reference = new AxiellReference($file);
    $x = mapFixture($reference);
    check($x->evaluate('string(/record/priref)') === '42', 'Verified old priref not retained.');
    foreach (AxiellProfile::cases() as $profile) {
        $native = mapFixture($reference, profile: $profile);
        check($native->evaluate('string(/record/priref)') === '42', 'Shared Axiell identity lost in '.$profile->value);
        if ($profile !== AxiellProfile::Vkc) check($native->evaluate('count(/record/material_description)') === 0.0, 'Field interpretations leaked between profiles.');
    }
    try {
        mapFixture($reference, id: '999', number: 'CONFLICT');
        throw new LogicException('Conflicting historical identities were accepted.');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'Conflicting Axiell identities'), 'Unexpected identity failure.');
    }
    check($x->evaluate('string(/record/material_description)') === 'olieverf op doek', 'Verified current material description missing.');
    check($x->evaluate('string(/record/Material/material/notes)') === 'canvas', 'Terminology metadata missing.');
    check($x->evaluate('string(/record/Content_subject[content.subject="Parijs"]/content.subject.lref)') === '77', 'Known subject in ContentDate not recovered.');
    check($x->evaluate('count(/record/content.date.period)') === 1.0 && $x->evaluate('string(/record/content.date.period)') === '19de eeuw', 'New period was guessed to be a subject.');
    check($x->evaluate('string(/record/Content_subject[content.subject="Voorwerp"]/content.subject.type/value[@lang="neutral"])') === 'OBJECT', 'Ambiguous type not disambiguated.');
    check($x->evaluate('string(/record/Content_subject[content.subject="Voorwerp"]/content.subject.type/value[3]/@lang)') === '1', 'Enumeration ordering/attributes changed.');
    $x = mapFixture($reference, materialNote: 'nieuw materiaal', type: 'onderwerp');
    check($x->evaluate('count(/record/material_description)') === 0.0, 'Old description substituted for changed current text.');
    check($x->evaluate('string(/record/Content_subject[content.subject="Voorwerp"]/content.subject.type/value[@lang="neutral"])') === 'SUBJECT', 'Old type overrode an explicit current type.');
    foreach ([['124','REF-1','Musea Brugge'],['123','NEW-1','Musea Brugge'],['123','REF-1','Museum voor Schone Kunsten Gent']] as [$id,$number,$institution]) {
        $x = mapFixture($reference, $id, $number, $institution);
        check($x->evaluate('string(/record/priref)') === $id, 'Identity guard allowed an old record interpretation.');
        check($x->evaluate('count(/record/material_description | /record/Content_subject[content.subject="Parijs"])') === 0.0, 'Reference content crossed record identities.');
        check($x->evaluate('string(/record/Content_subject[content.subject="Voorwerp"]/content.subject.type/value/@lang)') === 'source', 'Unverified ambiguous type was invented.');
    }
    $materials = '<Material><material>doek</material><material.fr>toile actuelle</material.fr><material.en>current canvas</material.en><material.part>drager</material.part><material.notes>Vrije bronnotitie</material.notes></Material>'
        .'<Material><material>Frans ontbreekt</material><material.en>English only</material.en><material.part>lijst</material.part></Material>'
        .'<Material><material>Engels ontbreekt</material><material.fr>Français seulement</material.fr></Material>'
        .'<Material><material>identieke vertalingen</material><material.fr>bronze</material.fr><material.en>bronze</material.en></Material>'
        .'<Material><material>geen vertalingen</material><material.notes>Geen vertaling</material.notes></Material>'
        .'<Material><material>doek</material><material.part>zonder bronvertaling</material.part></Material>';
    foreach (AxiellProfile::cases() as $profile) {
        $x = mapFixture($reference, profile: $profile, materials: $materials);
        check($x->evaluate('count(/record/Material)') === 6.0, 'Repeated material groups merged in '.$profile->value);
        check($x->evaluate('string(/record/Material[1]/material.notes)') === 'Vrije bronnotitie', 'Material note mixed with translations.');
        check($x->evaluate('string(/record/Material[2]/material.part)') === 'lijst', 'Material part shifted.');
        if ($profile === AxiellProfile::Brugge || $profile === AxiellProfile::Muzee) {
            check($x->evaluate('string(/record/Material[1]/material)') === 'doek' && $x->evaluate('count(/record/Material/material/*)') === 0.0, 'Native flat material format changed.');
            continue;
        }
        foreach ([['toile actuelle','current canvas'],['','English only'],['Français seulement',''],['bronze','bronze']] as $index => $translations) {
            $path = '/record/Material['.($index + 1).']/material';
            check($x->evaluate('count('.$path.'/notes)') === 2.0, 'Material translation positions missing or duplicated.');
            check($x->evaluate('string('.$path.'/notes[1])') === $translations[0], 'Source French material translation overwritten or shifted.');
            check($x->evaluate('string('.$path.'/notes[2])') === $translations[1], 'Source English material translation overwritten or shifted.');
        }
        check($x->evaluate('count(/record/Material[5]/material/notes)') === 0.0, 'Invented translation from a free note.');
        check($x->evaluate('string(/record/Material[6]/material/notes[1])') === ($profile === AxiellProfile::Msk ? 'ancienne traduction' : 'canvas'), 'Existing reference fallback lost for a group without source translations.');
        foreach (['fr'=>'source français', 'en'=>'source English'] as $language => $translation) {
            $single = mapFixture($reference, profile: $profile, materials: '<Material><material>doek</material><material.'.$language.'>'.$translation.'</material.'.$language.'></Material>');
            check($single->evaluate('count(/record/Material/material/notes)') === 2.0, 'Reference translations filled or shifted a missing source language.');
            check($single->evaluate('string(/record/Material/material/notes[1])') === ($language === 'fr' ? $translation : ''), 'Missing French slot was filled from stale reference data.');
            check($single->evaluate('string(/record/Material/material/notes[2])') === ($language === 'en' ? $translation : ''), 'Missing English slot was filled from stale reference data.');
        }
    }
    unset($reference);
} finally {
    unlink($file); unlink($file.'l');
}
echo "PASS: guarded reference identities, current-value preservation, subject recovery, enum attributes and terminology enrichment\n";
echo "PASS: source material translations take precedence in MSK/VKC; language positions, optional fields, reference fallback and flat native profiles preserved\n";
