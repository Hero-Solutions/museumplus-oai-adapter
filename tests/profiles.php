<?php

declare(strict_types=1);

// Pure PHP fixtures; no kernel, environment, database or HTTP transport.
require __DIR__.'/mapping.php';

use App\Axiell\AxiellProfile;
use App\Import\PublicationSelector;
use App\Mapping\RecordValues;

$fields = [
    'MuseumTxt'=>'<institution.name>Musea Brugge</institution.name>',
    'NumberTxt'=>'<object_number>TEST-1</object_number>',
    'ObjectNameTxt'=>'<Object_name><object_name>schilderij</object_name></Object_name>',
    'ObjectCategoryTxt'=>'<Object_category><object_category>schilderkunst</object_category></Object_category>',
    'ObjectCategoryUri'=>'<object_category.uri>https://vocabulary.invalid/123</object_category.uri>',
    'MaterialTxt'=>'<Material><material>doek</material><material.notes>Geen materiaalbeschrijving</material.notes></Material>',
    'physical_description'=>'Een fysieke omschrijving',
    'CreatorTxt'=>'<Production><creator>Maker A</creator><creator.role>schilder</creator.role><creator.qualifier>naar</creator.qualifier></Production><Production><creator>Maker B</creator></Production>',
    'PeriodTxt'=>'<Production_period><production.period>20ste eeuw</production.period></Production_period>',
    'TranslatedTitleTxt'=>'<Titel_translation><title.translation>French title</title.translation><title.language>Franstalige titel</title.language></Titel_translation><Titel_translation><title.translation>Unlabelled title</title.translation></Titel_translation><Titel_translation><title.translation>English title</title.translation><title.language>Engelse titel</title.language></Titel_translation>',
    'CurrentLocationTxt'=>'<Current_location><current_location>intern adres</current_location><op>In depot;Œuvre non exposée en salle actuellement;Work currently not on display;verpakkingsdetails</op></Current_location>',
    'DocumentationTxt'=>'<Documentation><documentation.title>Boek A</documentation.title><author.name>Auteur A</author.name><publisher>Uitgever A</publisher><documentation.page_reference>12</documentation.page_reference></Documentation><Documentation><documentation.title>Boek B</documentation.title><year_of_publication>2020</year_of_publication></Documentation>',
    'ExhibitionTxt'=>'<Exhibition><exhibition>Tentoonstelling A</exhibition><exhibition.catalogue_number>15</exhibition.catalogue_number></Exhibition>',
    'ContentPersonTxt'=>'<Content_person><content.person.name>Persoon A</content.person.name><content.person.name.type>persoon</content.person.name.type><content.person.note>Notitie A</content.person.note></Content_person><Content_person><content.person.name>Persoon B</content.person.name></Content_person>',
    'ContentSubjectTxt'=>'<Content_subject><content.subject>onderwerp A</content.subject><content.subject.type>onderwerp</content.subject.type></Content_subject>',
    'DimensionTxt'=>'<Dimension><dimension.type>afmetingen object</dimension.type><dimension.value>88</dimension.value><dimension.notes>met lijst</dimension.notes></Dimension>',
];
foreach (AxiellProfile::cases() as $profile) {
    [$x, $warnings] = mapped($fields, $profile);
    $flat = in_array($profile, [AxiellProfile::Brugge,AxiellProfile::Muzee], true);
    foreach (['institution.name'=>'name','Object_name/object_name'=>'term','object_category'=>'term','Material/material'=>'term','Production/creator.role'=>'term','production.period'=>'term'] as $path=>$child) {
        check($x->evaluate('count(/record/'.$path.'/'.$child.')') === ($flat ? 0.0 : 1.0), 'Wrong shape for '.$profile->value.': '.$path);
        check($x->evaluate('string(/record/'.$path.')') !== '', 'Field lost in '.$profile->value.': '.$path);
    }
    check($x->evaluate('count(/record/Production[creator/name="Maker B"]/creator.role)') === 0.0, 'Creator role shifted.');
    check($x->evaluate('count(/record/Titel_translation)') === 3.0, 'Translation lost.');
    check($x->evaluate('string(/record/Titel_translation[1]/title.language)') === 'Frans' && $x->evaluate('string(/record/Titel_translation[3]/title.language)') === 'Engels', 'Known language aliases not normalized.');
    check($x->evaluate('count(/record/Titel_translation[2]/title.language)') === 0.0, 'Unlabelled translation inherited another language.');
    check($x->evaluate('count(/record/Current_location/current_location/op)') === 3.0 && count($warnings) === 1, 'Public location split/validation failed.');
    check($x->evaluate('count(/record/Documentation)') === 2.0 && $x->evaluate('string(/record/Documentation[1]/documentation.title/Author/author.name)') === 'Auteur A', 'Documentation missing.');
    check($x->evaluate('count(/record/Documentation[2]/documentation.title/Author)') === 0.0, 'Documentation author shifted.');
    check($x->evaluate('string(/record/Documentation[2]/documentation.title/Publisher/year_of_publication)') === '2020', 'Publication year lost.');
    check($x->evaluate('count(/record/Exhibition/exhibition/title)') === ($profile === AxiellProfile::Msk ? 1.0 : 0.0), 'Wrong exhibition profile.');
    check($x->evaluate('string(/record/Content_person[1]/content.person.name.type/value[@lang="1"])') === 'persoon', 'Person type shape wrong.');
    check($x->evaluate('count(/record/Content_person[2]/content.person.note)') === 0.0, 'Person note shifted.');
    check($x->evaluate('string(/record/Content_subject/content.subject.type/value[@lang="1"])') === 'onderwerp', 'Subject type missing.');
    check($x->evaluate('count(/record/Dimension)') === 1.0 && $x->evaluate('string(/record/Dimension/dimension.type)') === 'afmetingen object', 'Guessed missing measurements.');
    check($x->evaluate('string(/record/Dimension/dimension.notes)') === 'met lijst', 'Dimension note lost.');
    check($x->evaluate('count(/record/material_description | /record/object_category.lref)') === 0.0, 'Fabricated material description or local authority ID.');
    check($x->evaluate('string(/record/object_category.uri)') === 'https://vocabulary.invalid/123', 'Category URI lost.');
}
[$x] = mapped(['MaterialDescriptionTxt'=>'<material_description>olieverf op doek</material_description>'], AxiellProfile::Muzee);
check($x->evaluate('string(/record/material_description)') === 'olieverf op doek', 'Explicit material description missing.');
[$x] = mapped(['Vertaalde_titel'=>'<Title><title>Old export title</title><title.language>Onbekende taal</title.language></Title>']);
check($x->evaluate('string(/record/Titel_translation/title.language)') === 'Onbekende taal', 'Old title fallback or unknown language lost.');

$selector = new PublicationSelector();
$record = static fn(string $museum, string $number, array $tags): RecordValues => new RecordValues('test', $number, ['Naam_bewaarinstelling::institution.name'=>[$museum], 'WebPublicationTxt::webpublication'=>$tags], [], [], []);
check($selector->select($record('Musea Brugge','TEST',['Extranet','VKC datahub'])) === ['musea_brugge','vkc'], 'Overlapping Brugge/VKC selection failed.');
check($selector->select($record('Mu.ZEE, Kunstmuseum aan zee','TEST',['VKC datahub'])) === ['muzee','vkc'], 'Mu.ZEE must have two outputs.');
check($selector->select($record('Museum voor Schone Kunsten Gent','S-40',[])) === ['mskObj','vkc'], 'MSK without flags must have two outputs.');
check($selector->select($record('Museum voor Schone Kunsten Gent','NEW-TEST',[])) === ['mskObj','vkc'], 'Old inventory allowlist still restricts MSK.');
check($selector->select($record('Musea Brugge','S-40',[])) === ['musea_brugge','vkc'], 'Inventory collision changed institution routing.');
check($selector->select($record('Stad Oostende','TEST',[])) === ['muzee','vkc'], 'Related institution omitted.');
check($selector->select($record('Groeningemuseum','LP000G03',[])) === ['musea_brugge','vkc'], 'Confirmed Groeningemuseum routing lost.');
check($selector->select(new RecordValues('test','LP000G03',['Naam_bewaarinstelling::institution.name'=>['Groeningemuseum'],'__orgUnit'=>['org_32_GroeningemuseumMuZee']],[],[],[])) === ['muzee','vkc'], 'Explicit organisation unit must precede the fallback label.');
check($selector->select($record('Unlisted museum','TEST',['Extranet','VKC datahub'])) === [], 'Unknown institution guessed from flags.');
check($selector->select(new RecordValues('25842813','1949-AU-9-verso',[])) === ['mskObj','vkc'], 'Locally verified record without institution lost.');
check($selector->select(new RecordValues('25842813','NEW-NUMBER',[])) === [], 'Record override survived an inventory change.');
check($selector->select(new RecordValues('25842813','1949-AU-9-verso',['Naam_bewaarinstelling::institution.name'=>['Musea Brugge']])) === ['musea_brugge','vkc'], 'Record override replaced a current explicit institution.');

[$x] = mapped(['MuseumTxt'=>'<institution.name>Groeningemuseum</institution.name>', 'CollectionTxt'=>'<collection>Groeningemuseum</collection><collection>Prenten</collection>'], AxiellProfile::Brugge);
check($x->evaluate('string(/record/institution.name)') === 'Musea Brugge' && $x->evaluate('string(/record/institution.original_name)') === 'Groeningemuseum', 'Affiliate provider/original name lost.');
check($x->evaluate('count(/record/collection)') === 1.0 && $x->evaluate('string(/record/collection)') === 'Prenten', 'Institution placeholder mixed with actual collections.');

$dimension = '<Dimension><dimension.type>afmetingen object</dimension.type><dimension.value>10</dimension.value><dimension.unit>cm</dimension.unit><dimension.part>beeld</dimension.part><dimension.notes>Notitie</dimension.notes></Dimension>';
[$x] = mapped(['DimensionTxt'=>$dimension.'<dimension.free>10 x 20 x 30 cm (hxbxd)</dimension.free>']);
check($x->evaluate('count(/record/Dimension)') === 3.0 && $x->evaluate('string(/record/Dimension[dimension.type="diepte"]/dimension.value)') === '30', 'Explicit complete dimensions not recovered.');
check($x->evaluate('count(/record/Dimension[dimension.part="beeld"])') === 3.0 && $x->evaluate('count(/record/Dimension/dimension.notes)') === 1.0, 'Dimension context duplicated or lost.');
foreach (['11 x 20 cm (hxb)','10 x 20 mm (hxb)','10 x 20 cm','10 x 20 cm (hxb), met lijst','10 x 20 x 30 cm (hxb)'] as $free) {
    [$x] = mapped(['DimensionTxt'=>$dimension.'<dimension.free>'.$free.'</dimension.free>']);
    check($x->evaluate('count(/record/Dimension)') === 1.0, 'Ambiguous/conflicting dimensions were inferred: '.$free);
}
[$x] = mapped(['DimensionTxt'=>$dimension.$dimension.'<dimension.free>10 x 20 cm (hxb)</dimension.free>']);
check($x->evaluate('count(/record/Dimension)') === 2.0, 'Free dimensions assigned to an arbitrary repeated group.');
echo "PASS: four mapping profiles, source grouping, title fallback, explicit fields and overlapping publication selection\n";
