<?php

declare(strict_types=1);

// php -n tests/mapping.php — synthetic XML only; no kernel, configuration or clients.
use App\Axiell\AxiellRecordMapper;
use App\Axiell\AxiellXmlRenderer;
use App\Mapping\DigitalReferenceValidator;
use App\Mapping\MuseumPlusExportParser;
use App\Mapping\XmlValueExtractor;
use App\Normalizer\CreatorNormalizer;
use App\Normalizer\DimensionNormalizer;
use App\Normalizer\LabelNormalizer;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__).'/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mapped(array $fields, App\Axiell\AxiellProfile $profile = App\Axiell\AxiellProfile::Vkc): array
{
    $source = '<Object><ID>test-1</ID>';
    foreach ($fields as $field => $fragment) {
        $source .= '<'.$field.'>'.htmlspecialchars($fragment, ENT_QUOTES | ENT_XML1, 'UTF-8').'</'.$field.'>';
    }
    $source .= '</Object>';
    $parsed = (new MuseumPlusExportParser(new XmlValueExtractor()))->parse('<ObjectList>'.$source.'</ObjectList>')[0];
    $mapped = (new AxiellRecordMapper(new CreatorNormalizer(), new DimensionNormalizer(), new LabelNormalizer()))->map($parsed->values, $profile);
    $renderer = new AxiellXmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__).'/templates')));
    $doc = new DOMDocument();
    check($doc->loadXML($renderer->renderRecord($mapped), LIBXML_NONET), 'Malformed output.');

    return [new DOMXPath($doc), $mapped->warnings];
}

[$x] = mapped([
    'Term_materiaal' => '<Material><material>papier</material><material.part>drager</material.part></Material><Material><material>krijt</material><material.part>medium</material.part><material.notes>wit gehoogd</material.notes></Material>',
    'Opschrift' => '<Inscription><inscription.content>74</inscription.content></Inscription><Inscription><inscription.content>149/150</inscription.content><inscription.type>oplage</inscription.type></Inscription>',
    'Titel_nl' => '<Title><title>A</title></Title><Title><title>B</title><title.type>oude titel</title.type><title.language>nl</title.language></Title><Title><title>A</title><title.type>verzamelnaam</title.type></Title>',
    'Toestand' => '<Condition><condition>goed</condition></Condition><Condition><condition>matig</condition><condition.date>2020</condition.date></Condition>',
    'Alternatief_nummer' => '<Alternative_number><alternative_number>A</alternative_number></Alternative_number><Alternative_number><alternative_number>B</alternative_number><alternative_number.type>oud nummer</alternative_number.type></Alternative_number>',
    'Afmeting' => '<Dimension><dimension.value>10 x 20 cm</dimension.value></Dimension><Dimension><dimension.value>8</dimension.value><dimension.unit>mm</dimension.unit><dimension.part>lijst</dimension.part><dimension.precision>circa</dimension.precision><dimension.type>diameter</dimension.type></Dimension>',
]);
check($x->evaluate('string(/record/Material[material/term="krijt"]/material.notes)') === 'wit gehoogd', 'Material note shifted.');
check($x->evaluate('count(/record/Material[material/term="papier"]/material.notes)') === 0.0, 'Paper inherited another material note.');
check($x->evaluate('count(/record/Inscription[inscription.content="74"]/inscription.type)') === 0.0, 'Inscription type shifted.');
check($x->evaluate('string(/record/Inscription[inscription.content="149/150"]/inscription.type)') === 'oplage', 'Inscription lost its type.');
check($x->evaluate('count(/record/Title)') === 3.0 && $x->evaluate('string(/record/Title[title="B"]/title.type)') === 'oude titel', 'Title/type alignment lost.');
check($x->evaluate('string(/record/Condition[condition="matig"]/condition.date)') === '2020', 'Condition date shifted.');
check($x->evaluate('count(/record/Condition[condition="goed"]/condition.date)') === 0.0, 'Condition inherited a date.');
check($x->evaluate('count(/record/Alternative_number[alternative_number="A"]/alternative_number.type)') === 0.0, 'Alternative number type shifted.');
check($x->evaluate('count(/record/Dimension[dimension.unit="cm" and dimension.part="geheel"])') === 2.0, 'Dimension unit/part shifted.');
check($x->evaluate('string(/record/Dimension[dimension.value="8"]/dimension.type)') === 'diameter', 'Explicit dimension type lost.');
check($x->evaluate('string(/record/Dimension[dimension.value="8"]/dimension.precision)') === 'circa', 'Dimension precision lost.');
echo "PASS: missing optional fields stay with their source group, including repeated titles and dimensions\n";

[$x, $warnings] = mapped([
    'Naam_vervaardiger' => '<Production><creator>Atelier (coll.)</creator></Production>'
        .'<Production><creator>Maker A</creator><creator.role>tekenaar</creator.role><creator.qualifier>toegeschreven aan</creator.qualifier><birth.date.start>1592-03-25</birth.date.start><birth.date.end>1592-08-21</birth.date.end><birth.date.precision>tussen</birth.date.precision><death.date.start>1635-03-24</death.date.start></Production>'
        .'<Production><creator>Maker B</creator><birth.date.start>1946-12-02;1946</birth.date.start><death.date.start>2000;2000</death.date.start></Production>'
        .'<Production><creator>Maker C</creator><birth.date.start>1900;1901</birth.date.start></Production>',
    'Naam_plaats_vervaardiging' => '<production.place>Gent, België</production.place>',
]);
check($x->evaluate('string(/record/Production[1]/creator/name)') === 'Atelier (coll.)', 'Name suffix removed.');
check($x->evaluate('count(/record/Production[1]/creator.role)') === 0.0, 'Role moved to another creator.');
check($x->evaluate('string(/record/Production[2]/creator.role/term)') === 'tekenaar', 'Role and qualifier reversed.');
check($x->evaluate('string(/record/Production[2]/creator.qualifier)') === 'toegeschreven aan', 'Qualifier lost.');
check($x->evaluate('string(/record/Production[2]/creator/birth.date.end)') === '1592-08-21', 'Birth range lost.');
check($x->evaluate('string(/record/Production[2]/creator.date_of_death)') === '1635-03-24', 'Birth range mistaken for life dates.');
check($x->evaluate('string(/record/Production[3]/creator.date_of_birth)') === '1946-12-02', 'Compatible dates not normalised.');
check($x->evaluate('string(/record/Production[3]/creator.date_of_death)') === '2000', 'Duplicate dates not normalised.');
check($x->evaluate('count(/record/Production[4]/creator.date_of_birth)') === 0.0 && count($warnings) === 1, 'Conflicting dates guessed or not reported.');
check($x->evaluate('count(/record/Production[creator and production.place])') === 0.0, 'Object-level place attached to an arbitrary creator.');
check($x->evaluate('string(/record/Production/production.place)') === 'Gent', 'Production place lost.');
foreach (['2023-02-29', '2020-13', '1900;1901', 'circa 1900', '1900;'] as $invalid) {
    check((new CreatorNormalizer())->lifeDate($invalid) === null, 'Invalid date accepted: '.$invalid);
}
echo "PASS: source roles, names, life-date ranges and compatible semicolon dates; ambiguous dates reported\n";

[$x, $warnings] = mapped([
    'Huidige_standplaats' => '<Current_location><current_location>MSKzaal_12</current_location><op>Te zien op zaal</op><op>Œuvre exposée en salle</op><op>Work on display</op></Current_location>'
        .'<Current_location><current_location>MSKkrat_00120</current_location><op>kunststof, inert, gesloten</op><op>60*40*42 cm</op></Current_location>'
        .'<Current_location><current_location>onbekend intern adres</current_location></Current_location>',
]);
check($x->evaluate('count(/record/Current_location[1]/current_location/op)') === 3.0, 'Public languages lost.');
check($x->evaluate('count(/record/Current_location[position()>1]/current_location/op)') === 0.0, 'Packaging or internal address published as public label.');
check(count($warnings) === 2, 'Rejected public locations not reported.');
echo "PASS: public location labels retained, packaging excluded, no fallback to internal location\n";

$references = '';
foreach (['id' => 'workpid', 'data' => 'datapid', 'representation' => 'representationpid'] as $route => $label) {
    foreach (['BMV_07098', 'BMV_09102'] as $number) {
        $references .= '<Digital_reference><digital_reference>https://www.museabrugge.be/collection/work/'.$route.'/'.$number.'</digital_reference><digital_reference.description>'.$label.'</digital_reference.description></Digital_reference>';
    }
}
[$x, $warnings] = mapped([
    'Objectnummer' => '<object_number>BMV.09102</object_number>',
    'PID' => 'https://id.erfgoeddata.be/ark:/23947/another-identity',
    'Persistente_uri' => $references,
]);
check($x->evaluate('count(/record/Digital_reference)') === 3.0, 'Valid references not retained or wrong references published.');
check(count($warnings) === 3 && str_contains($warnings[0]['message'], 'BMV_07098'), 'Wrong PID and related links not reported.');
check($x->evaluate('count(/record/Digital_reference[contains(digital_reference,"BMV_07098")])') === 0.0, 'Foreign work links retained.');
check($x->evaluate('count(/record/Digital_reference[contains(digital_reference,"ark:")])') === 0.0, 'ARK substituted for existing work identity.');
$validator = new DigitalReferenceValidator();
foreach ([
    ['https://www.museabrugge.be/collection/work/id/2022_GRO0993_II', 'workpid', ['2022.GRO0993.II']],
    ['https://muzee.be/collection/work/id/MZ_1', 'workpid', ['MZ/1']],
    ['https://mskgent.be/collection/work/id/1920-A', 'workpid', ['OTHER', '1920-A']],
    ['https://identifiers.invalid/opaque-123', 'workpid', ['1920-A']],
] as [$url, $label, $numbers]) {
    check($validator->problem($url, $label, $numbers) === null, 'Matching or unverifiable identifier was changed.');
}
check($validator->problem('https://mskgent.be/collection/work/data/1920-A', 'workpid', ['1920-A']) !== null, 'Wrong PID type accepted.');
check($validator->problem('https://muzee.be/collection/work/id/MZ000470', 'workpid', ['MZ000470a-b']) !== null, 'Possible parent-work PID accepted as verified identity.');
check($validator->problem('not a URL', 'workpid', ['1920-A']) !== null, 'Invalid URL accepted.');
check($validator->problem('https://collectie.museabrugge.be/collection/work/id/BMV_27595', 'datapid', ['BMV.27616']) !== null, 'Wrong historical Bruges link accepted.');
echo "PASS: foreign work/data/representation identifiers omitted and reported; aliases and opaque identifiers retained; no invented PID\n";

$mskPid = 'https://mskgent.be/collection/work/id/1920-A';
$mskFields = [
    'Naam_bewaarinstelling' => '<institution.name>Museum voor Schone Kunsten Gent</institution.name>',
    'Objectnummer' => '<object_number>1920-A</object_number>',
    'Persistente_uri' => '<Digital_reference><digital_reference>'.$mskPid.'</digital_reference><digital_reference.description>workpid</digital_reference.description></Digital_reference>',
];
[$x] = mapped($mskFields);
check($x->evaluate('string(/record/Alternative_number[alternative_number.type="workpid"]/alternative_number)') === $mskPid, 'MSK DataHub workPID field absent.');
$mskFields['Alternatief_nummer'] = '<Alternative_number><alternative_number>'.$mskPid.'</alternative_number><alternative_number.type>workpid</alternative_number.type></Alternative_number>'
    .'<Alternative_number><alternative_number>https://mskgent.be/collection/work/id/OTHER</alternative_number><alternative_number.type>workpid</alternative_number.type></Alternative_number>';
[$x, $warnings] = mapped($mskFields);
check($x->evaluate('count(/record/Alternative_number[alternative_number.type="workpid"])') === 1.0 && count($warnings) === 1, 'MSK workPID duplicated or unvalidated alternative PID retained.');
$mskFields['Objectnummer'] = '<object_number>NEW-NUMBER</object_number>';
[$x] = mapped($mskFields);
check($x->evaluate('count(/record/Alternative_number[alternative_number.type="workpid"])') === 0.0, 'Rejected workPID leaked through the MSK compatibility field.');
echo "PASS: both MSK DataHub mappings receive the same validated workPID without duplicates or rejected aliases\n";

$common = [
    'Naam_collectie' => '<collection>collectie A</collection><collection>collectie B</collection>',
    'Term_objectcategorie' => '<Object_category><object_category>prent</object_category><object_category.notes>gele fond</object_category.notes></Object_category><Object_category><object_category>tekening</object_category></Object_category>',
    'Term_objectnaam' => '<Object_name><object_name>affiche</object_name><object_name.notes>Vooruit 19-2-1933</object_name.notes></Object_name>',
    'Periode' => '<Production_period><production.period>19de eeuw</production.period></Production_period><Production_period><production.period>20ste eeuw</production.period></Production_period>',
    'Term_motief_specifiek' => '<Content_motif_specific><content.motif.specific>hond</content.motif.specific><broader_term>dieren</broader_term></Content_motif_specific>',
    'Term_techniek' => '<Technique><technique>etsen</technique></Technique>',
    'Verwerving' => '<Acquisition_source><acquisition.source>Test schenker</acquisition.source><acquisition.method>schenking</acquisition.method><acquisition.date>2020</acquisition.date><acquisition.place>Gent</acquisition.place></Acquisition_source>',
    'Herkomst_eigendom' => '<current_owner>Stad</current_owner>',
];
[$x] = mapped($common);
check($x->evaluate('count(/record/collection)') === 2.0 && $x->evaluate('count(/record/object_category)') === 2.0 && $x->evaluate('count(/record/production.period)') === 2.0, 'Repeated collections/categories/periods lost.');
check($x->evaluate('string(/record/Object_name/object_name.notes)') === 'Vooruit 19-2-1933', 'Descriptive note lost.');
check($x->evaluate('string(/record/object_category[term="prent"]/object_category.notes)') === 'gele fond' && $x->evaluate('count(/record/object_category[term="tekening"]/object_category.notes)') === 0.0, 'Category note shifted.');
check($x->evaluate('count(/record/Object_name/object_name/notes | /record/object_category/notes)') === 0.0, 'Free notes treated as authority translations.');
check($x->evaluate('string(/record/content.motif.specific/broader_term)') === 'dieren', 'Specific motif wrapper not mapped.');
check($x->evaluate('string(/record/acquisition.method)') === 'schenking' && $x->evaluate('string(/record/acquisition.date)') === '2020', 'Acquisition root fields absent.');
check($x->evaluate('string(/record/current_owner)') === 'Stad', 'Current owner lost.');
[$x] = mapped($common + ['Naam_bewaarinstelling' => '<institution.name>Museum voor Schone Kunsten Gent</institution.name>'], App\Axiell\AxiellProfile::Msk);
check($x->evaluate('count(/record/collection/term)') === 2.0, 'MSK collection shape wrong.');
check($x->evaluate('string(/record/Technique/technique/term)') === 'etsen', 'MSK technique shape wrong.');
check($x->evaluate('string(/record/Acquisition_source/acquisition.source/name)') === 'Test schenker', 'MSK acquisition source shape wrong.');
check($x->evaluate('string(/record/acquisition.method/term)') === 'schenking' && $x->evaluate('string(/record/acquisition.place/term)') === 'Gent', 'MSK acquisition fields wrong.');
echo "PASS: repeated values, specific motifs, descriptive notes, owner and museum-specific DataHub field structures\n";
