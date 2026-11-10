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
function mapFixture(AxiellReference $reference, string $id = '123', string $number = 'REF-1', string $institution = 'Musea Brugge', string $materialNote = 'olieverf op doek', string $type = 'eigen afgebeeld object', AxiellProfile $profile = AxiellProfile::Vkc, ?string $materials = null, ?string $categories = null, array $extraFields = []): DOMXPath {
    $fields = [
        'NumberTxt'=>'<object_number>'.$number.'</object_number>',
        'MuseumTxt'=>'<institution.name>'.$institution.'</institution.name>',
        'MaterialTxt'=>$materials ?? '<Material><material>doek</material><material.notes>'.$materialNote.'</material.notes></Material>',
        'ContentSubjectTxt'=>'<Content_subject><content.subject>Voorwerp</content.subject><content.subject.type>'.$type.'</content.subject.type></Content_subject>',
        'ContentDateTxt'=>'<content.date.period>Parijs</content.date.period><content.date.period>19de eeuw</content.date.period>',
    ];
    if ($categories !== null) $fields['ObjectCategoryTxt'] = $categories;
    $fields = array_replace($fields, $extraFields);
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
    'terms:vkc|Material/material|doek'=>[leaf('notes','canvas'), leaf('source','https://old.example.invalid/'), leaf('term.number','old-id')],
    'terms:mskObj|Material/material|doek'=>[leaf('notes','ancienne traduction'), leaf('notes','old translation'), leaf('source','https://old.example.invalid/'), leaf('term.number','old-id')],
    'enums:content.subject.type|onderwerp'=>type('SUBJECT','onderwerp')['children'],
    'records:vkc|999'=>['number'=>'CONFLICT','institution'=>'Musea Brugge','priref'=>'40'],
    'records:musea_brugge|999'=>['number'=>'CONFLICT','institution'=>'Musea Brugge','priref'=>'41'],
    'term_refs:muzee|object_category|testcategorie A'=>'test-authority-a',
    'term_refs:muzee|object_category|testcategorie B'=>'test-authority-b',
    'terms:mskObj|Production/creator.role|testrol'=>[leaf('notes','rôle test'), leaf('notes','test role')],
    'terms:vkc|Production/creator.role|testrol'=>[leaf('notes','test role')],
    'terms:mskObj|Production/creator/birth.place|Testplaats'=>[leaf('notes','Lieu test'), leaf('notes','Test place')],
];
foreach (['muzee', 'vkc'] as $profile) {
    $entries['records:'.$profile.'|222'] = ['number'=>'MUZ-TEST','institution'=>'Mu.ZEE, Kunstmuseum aan zee','priref'=>'222',
        'material_description'=>['ets op papier']];
    $entries['records:'.$profile.'|333'] = ['number'=>'MUZ-AMBIGUOUS','institution'=>'Mu.ZEE, Kunstmuseum aan zee','priref'=>'333',
        'material_description'=>['ets op papier', 'drukinkt op papier']];
}
$pairedFields = [
    'object_category'=>['ObjectCategoryTxt', 'Object_category'],
    'production.period'=>['PeriodTxt', 'Production_period'],
    'content.motif.general'=>['MotifGeneralTxt', null],
    'content.motif.specific'=>['MotifSpecificTxt', null],
    'acquisition.method'=>['AcquisitionTxt', 'Acquisition_source'],
];
foreach (['muzee', 'musea_brugge', 'vkc/muzee', 'vkc/musea_brugge'] as $scope) {
    foreach ($pairedFields as $field=>$_) foreach (['A','B'] as $term) {
        $entries['term_refs:'.$scope.'|'.$field.'|Testterm '.$term] = $scope.':'.$field.':'.$term;
    }
}
$lines = ''; $index = [];
foreach ($entries as $key=>$entry) { $index[$key] = strlen($lines); $lines .= json_encode($entry, JSON_THROW_ON_ERROR)."\n"; }
file_put_contents($file.'l', $lines);
file_put_contents($file, json_encode(['version'=>1,'sha256'=>hash('sha256',$lines),'index'=>$index], JSON_THROW_ON_ERROR));
try {
    $reference = new AxiellReference($file);
    $categories = '<Object_category><object_category>testcategorie A</object_category></Object_category>'
        .'<Object_category><object_category>onbekende categorie</object_category></Object_category>'
        .'<Object_category><object_category>testcategorie B</object_category><object_category.notes>Bronnotitie</object_category.notes></Object_category>';
    foreach (AxiellProfile::cases() as $profile) {
        $x = mapFixture($reference, profile: $profile, categories: $categories);
        check($x->evaluate('count(/record/object_category)') === 3.0, 'Category disappeared during compatibility mapping.');
        if ($profile !== AxiellProfile::Muzee) {
            check($x->evaluate('count(/record/object_category.lref[normalize-space()!=""])') === 0.0, 'Mu.ZEE category references leaked into another profile.');
            continue;
        }
        check($x->evaluate('count(/record/object_category.lref)') === 3.0, 'Mu.ZEE category/reference positions do not align.');
        foreach (['test-authority-a', '', 'test-authority-b'] as $i=>$id) {
            check($x->evaluate('string(/record/object_category.lref['.($i + 1).'])') === $id, 'Wrong or invented category reference.');
        }
        check($x->evaluate('string(/record/object_category[2])') === 'onbekende categorie', 'Unknown current category replaced by historical content.');
        check($x->evaluate('string(/record/object_category.notes)') === 'Bronnotitie', 'Category note lost.');
    }
    check(mapFixture($reference, profile: AxiellProfile::Muzee)->evaluate('count(/record/object_category.lref)') === 0.0, 'Category reference emitted without a category.');
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
    check(mapFixture($reference, materialNote: "Bronnotitie\nolieverf op doek")->evaluate('count(/record/material_description)') === 0.0, 'Mu.ZEE line matching leaked into Brugge.');
    foreach ([AxiellProfile::Muzee, AxiellProfile::Vkc] as $profile) {
        foreach (["Arches\nets op papier", "Arches\r\n  ets   op papier  \r\nAndere notitie", 'ets op papier'] as $note) {
            $mapped = mapFixture($reference, '222', 'MUZ-TEST', 'Mu.ZEE, Kunstmuseum aan zee', materialNote: $note, profile: $profile);
            check($mapped->evaluate('count(/record/material_description)') === 1.0
                && $mapped->evaluate('string(/record/material_description)') === 'ets op papier', 'Current Mu.ZEE material description missing in '.$profile->value);
            check(AxiellReference::clean($mapped->evaluate('string(/record/Material/material.notes)')) === AxiellReference::clean($note), 'Extracting a description changed the full material note.');
        }
        foreach (["Arches\ngeen ets op papier", "Arches\nets op papier met potlood", "Arches\npapier"] as $note) {
            $mapped = mapFixture($reference, '222', 'MUZ-TEST', 'Mu.ZEE, Kunstmuseum aan zee', materialNote: $note, profile: $profile);
            check($mapped->evaluate('count(/record/material_description)') === 0.0, 'Historical description guessed from a substring or absent source text.');
        }
        foreach ([['224','MUZ-TEST','Mu.ZEE, Kunstmuseum aan zee'], ['222','NEW-TEST','Mu.ZEE, Kunstmuseum aan zee'], ['222','MUZ-TEST','Musea Brugge'], ['333','MUZ-AMBIGUOUS','Mu.ZEE, Kunstmuseum aan zee']] as [$id,$number,$institution]) {
            $mapped = mapFixture($reference, $id, $number, $institution, materialNote: "Arches\nets op papier\ndrukinkt op papier", profile: $profile);
            check($mapped->evaluate('count(/record/material_description)') === 0.0, 'Description accepted despite an identity mismatch or ambiguous reference.');
        }
        $mapped = mapFixture($reference, '222', 'MUZ-TEST', 'Mu.ZEE, Kunstmuseum aan zee', profile: $profile,
            materials: '<Material><material>papier</material><material.notes>Arches</material.notes></Material>'
                ."<Material><material>drukinkt</material><material.notes>ets op papier\nets op papier</material.notes></Material>");
        check($mapped->evaluate('count(/record/Material)') === 2.0 && $mapped->evaluate('count(/record/material_description)') === 1.0, 'Repeated material groups lost or description duplicated.');
        $mapped = mapFixture($reference, '222', 'MUZ-TEST', 'Mu.ZEE, Kunstmuseum aan zee', materialNote: "Arches\nets op papier", profile: $profile,
            extraFields: ['MaterialDescriptionTxt'=>'<material_description>Nieuwe expliciete beschrijving</material_description>']);
        check($mapped->evaluate('string(/record/material_description)') === 'Nieuwe expliciete beschrijving', 'Note fallback overrode the explicit material description.');
    }
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
    foreach (['Mu.ZEE, Kunstmuseum aan zee'=>AxiellProfile::Muzee, 'Musea Brugge'=>AxiellProfile::Brugge] as $institution=>$museum) {
        foreach ([$museum, AxiellProfile::Vkc] as $profile) {
            $fields = [];
            foreach ($pairedFields as $field=>[$exportField,$group]) {
                $fields[$exportField] = '';
                foreach (['Testterm A','Onbekende term','Testterm B'] as $label) {
                    $fragment = '<'.$field.'>'.$label.'</'.$field.'>';
                    $fields[$exportField] .= $group === null ? $fragment : '<'.$group.'>'.$fragment.'</'.$group.'>';
                }
            }
            $x = mapFixture($reference, profile: $profile, institution: $institution, extraFields: $fields);
            $scope = $profile === AxiellProfile::Vkc ? 'vkc/'.$museum->value : $museum->value;
            foreach ($pairedFields as $field=>$_) {
                check($x->evaluate('count(/record/'.$field.'.lref)') === 3.0, 'Parallel term-reference array lost a position: '.$scope.' '.$field);
                foreach (['A',null,'B'] as $i=>$term) {
                    $expected = $term === null ? '' : $scope.':'.$field.':'.$term;
                    check($x->evaluate('string(/record/'.$field.'.lref['.($i+1).'])') === $expected, 'Authority reference shifted, guessed or borrowed from another museum/set.');
                }
                check($x->evaluate('count(/record/'.$field.'/term)') === ($profile === AxiellProfile::Vkc && $field !== 'acquisition.method' ? 3.0 : 0.0), 'Legacy native/VKC term shape changed.');
            }
        }
    }
    $creator = ['CreatorTxt'=>'<Production><creator>Testmaker</creator><creator.role>testrol</creator.role><birth.place>Testplaats</birth.place></Production>'];
    foreach (AxiellProfile::cases() as $profile) {
        $flat = in_array($profile, [AxiellProfile::Muzee,AxiellProfile::Brugge], true);
        $prefix = $flat ? '/record/Material/material.' : '/record/Material/material/';
        foreach ([['https://current.example.invalid/','current-id'], ['https://current.example.invalid/',''], ['', 'current-id']] as [$source,$id]) {
            $material = '<Material><material>doek</material><material.source>'.$source.'</material.source><material.term.number>'.$id.'</material.term.number></Material>';
            $x = mapFixture($reference, profile: $profile, institution: 'Museum voor Schone Kunsten Gent', materials: $material);
            check($x->evaluate('string('.$prefix.'source)') === $source, 'Current material source lost or combined with an old identifier.');
            check($x->evaluate('string('.$prefix.'term.number)') === $id, 'Current material identifier lost or combined with an old source.');
            check($x->evaluate('count(/record/Material/material/term)') === ($flat ? 0.0 : 1.0), 'Material structure no longer matches the museum fix.');
        }
    }
    foreach ([AxiellProfile::Msk, AxiellProfile::Vkc] as $profile) {
        $x = mapFixture($reference, institution: 'Museum voor Schone Kunsten Gent', profile: $profile, extraFields: $creator);
        check($x->evaluate('string(/record/Production/creator.role/notes[1])') === 'rôle test' && $x->evaluate('string(/record/Production/creator.role/notes[2])') === 'test role', 'MSK authority translations absent or reordered in VKC.');
        check($x->evaluate('string(/record/Production/creator/birth.place/notes[1])') === 'Lieu test', 'Known birthplace metadata missing.');
    }
    $x = mapFixture($reference, institution: 'Musea Brugge', extraFields: $creator);
    check($x->evaluate('count(/record/Production/creator/birth.place/notes)') === 0.0, 'MSK place translations leaked to Brugge.');
    unset($reference);
} finally {
    unlink($file); unlink($file.'l');
}
echo "PASS: guarded reference identities, current-value preservation, subject recovery, enum attributes and terminology enrichment\n";
echo "PASS: source material translations take precedence in MSK/VKC; language positions, optional fields, reference fallback and flat native profiles preserved\n";
echo "PASS: Mu.ZEE category references retain Axiell pairing; unknown categories get no invented code; other profiles unchanged\n";
echo "PASS: parallel term references scoped to museum and set, empty positions and MSK authority translations in VKC\n";
echo "PASS: current material source/identifier pairs override frozen metadata without mixing vocabularies; flat native fields retained\n";
