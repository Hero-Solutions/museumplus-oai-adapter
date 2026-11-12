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
    foreach (AxiellProfile::cases() as $profile) {
        $x = mapFixture($reference, profile: $profile);
        check($x->evaluate('string(/record/priref)') === '42', 'Existing identity lost in '.$profile->value);
        check($x->evaluate('count(/record/material_description | /record/Material/material/notes | /record/Material/material/source | /record/Material/material/term.number)') === 0.0, 'Historical content leaked into '.$profile->value);
        check($x->evaluate('count(/record/content.date.period)') === 2.0 && $x->evaluate('count(/record/Content_subject[content.subject="Parijs"])') === 0.0, 'Historical ContentDate interpretation was reused.');
        check($x->evaluate('string(/record/Content_subject/content.subject.type/value/@lang)') === 'source', 'Historical subject type was reused.');
        check($x->evaluate('string(/record/Material/material.notes)') === 'olieverf op doek', 'Current material note lost.');
        $x = mapFixture($reference, profile: $profile, type: 'onderwerp');
        check($x->evaluate('string(/record/Content_subject/content.subject.type/value[3])') === 'onderwerp', 'Current enum label is not in the Catmandu Dutch slot.');
        check($x->evaluate('string(/record/Content_subject/content.subject.type/value[1])') === '' && $x->evaluate('string(/record/Content_subject/content.subject.type/value[2])') === '', 'Historical enum code/translation reused.');
    }
    foreach ([['124','REF-1','Musea Brugge'], ['123','NEW-1','Musea Brugge'], ['123','REF-1','Museum voor Schone Kunsten Gent']] as [$id,$number,$institution]) {
        check(mapFixture($reference, $id, $number, $institution)->evaluate('string(/record/priref)') === $id, 'Historical identity guard bypassed.');
    }
    try {
        mapFixture($reference, id: '999', number: 'CONFLICT');
        throw new LogicException('Conflicting historical identities accepted.');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'Conflicting Axiell identities'), 'Unexpected identity failure.');
    }
    foreach (['Mu.ZEE, Kunstmuseum aan zee' => AxiellProfile::Muzee, 'Musea Brugge' => AxiellProfile::Brugge] as $institution => $museum) {
        foreach ([$museum, AxiellProfile::Vkc] as $profile) {
            $fields = [];
            foreach ($pairedFields as $field => [$exportField,$group]) {
                $fields[$exportField] = '';
                foreach (['Testterm A','Onbekende term','Testterm B'] as $label) {
                    $fragment = '<'.$field.'>'.$label.'</'.$field.'>';
                    $fields[$exportField] .= $group === null ? $fragment : '<'.$group.'>'.$fragment.'</'.$group.'>';
                }
            }
            $x = mapFixture($reference, institution: $institution, profile: $profile, extraFields: $fields);
            foreach ($pairedFields as $field => $_) {
                check($x->evaluate('count(/record/'.$field.')') === 3.0, 'Current term lost.');
                check($x->evaluate('count(/record/'.$field.'.lref)') === 3.0, 'Parallel term keys missing.');
                foreach ($x->query('/record/'.$field.'.lref') as $key) {
                    check(str_starts_with($key->textContent, 'adapter-local:'.$museum->value.':'.$field.':'), 'Historical authority ID reused instead of an adapter-local key.');
                }
            }
        }
    }
    foreach ([AxiellProfile::Msk, AxiellProfile::Vkc] as $profile) {
        $materials = '<Material><material>doek</material><material.fr>toile actuelle</material.fr><material.en>current canvas</material.en><material.source>https://current.invalid/</material.source><material.term.number>current-id</material.term.number></Material>'
            .'<Material><material>doek</material><material.en>English only</material.en></Material><Material><material>doek</material></Material>';
        $x = mapFixture($reference, institution: 'Museum voor Schone Kunsten Gent', profile: $profile, materials: $materials, extraFields: [
            'CreatorTxt' => '<Production><creator>Testmaker</creator><creator.role>testrol</creator.role><birth.place>Testplaats</birth.place></Production>',
        ]);
        check($x->evaluate('string(/record/Material[1]/material/notes[1])') === 'toile actuelle' && $x->evaluate('string(/record/Material[1]/material/notes[2])') === 'current canvas', 'Explicit source translations lost.');
        check($x->evaluate('count(/record/Material[2]/material/notes)') === 2.0 && $x->evaluate('string(/record/Material[2]/material/notes[1])') === '' && $x->evaluate('string(/record/Material[2]/material/notes[2])') === 'English only', 'English shifted into French.');
        check($x->evaluate('count(/record/Material[3]/material/notes | /record/Production/creator.role/notes | /record/Production/creator/birth.place/notes)') === 0.0, 'Missing source translations supplemented from history.');
        check($x->evaluate('string(/record/Material[1]/material/source)') === 'https://current.invalid/' && $x->evaluate('string(/record/Material[1]/material/term.number)') === 'current-id', 'Source vocabulary reference lost.');
    }
    $x = mapFixture($reference, '222', 'MUZ-TEST', 'Mu.ZEE, Kunstmuseum aan zee', materialNote: "Arches\nets op papier", profile: AxiellProfile::Muzee);
    check($x->evaluate('count(/record/material_description)') === 0.0, 'Material description inferred from a note or historical hint.');
    check($x->evaluate('string(/record/Material/material.notes)') === 'Arches ets op papier', 'Current material note lost or shortened.');
    $x = mapFixture($reference, extraFields: ['MaterialDescriptionTxt' => '<material_description>Current explicit display</material_description>']);
    check($x->evaluate('string(/record/material_description)') === 'Current explicit display', 'Explicit material display lost.');
    unset($reference);
} finally {
    unlink($file); unlink($file.'l');
}
echo "PASS: source-only mapping, guarded historical OAI identities, current translations and no historical content enrichment\n";
