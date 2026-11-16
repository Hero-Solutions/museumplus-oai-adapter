<?php
declare(strict_types=1);

// Synthetic export fields and the rendered Axiell contract; no application kernel.
require __DIR__.'/mapping.php';

use App\Axiell\AxiellProfile;

foreach (AxiellProfile::cases() as $profile) {
    [$x, $warnings] = mapped([
        'TitleTxt_nl'=>'<Title><title>Hoofdtitel</title><title.language>nl</title.language></Title>',
        'TranslatedTitleTxt'=>'<Titel_translation><title.translation>Niet publiceren</title.translation></Titel_translation>'
            .'<Titel_translation><title.translation>English first</title.translation><title.language>en</title.language><title.type>alternatieve titel</title.type></Titel_translation>'
            .'<Titel_translation><title.translation>Deutscher Titel</title.translation><title.language>de</title.language></Titel_translation>'
            .'<Titel_translation><title.translation>Dubbelzinnig</title.translation><title.language>fr</title.language><title.language>en</title.language></Titel_translation>'
            .'<Titel_translation><title.translation>Onbekend</title.translation><title.language>vertaalde titel</title.language></Titel_translation>'
            .'<Titel_translation><title.translation>Leeg label</title.translation><title.language> </title.language></Titel_translation>'
            .'<Titel_translation><title.translation>Andere taal</title.translation><title.language>es</title.language></Titel_translation>'
            .'<Titel_translation><title.translation>Titre français</title.translation><title.language>Franstalige titel</title.language></Titel_translation>',
    ], $profile);
    check($x->evaluate('string(/record/Title/title)') === 'Hoofdtitel', 'Primary Dutch title lost.');
    check($x->evaluate('count(/record/Titel_translation)') === 3.0 && count($warnings) === 5, 'Unsafe title languages retained or not reported.');
    foreach (['Engels','Duits','Frans'] as $i=>$language) {
        check($x->evaluate('string(/record/Titel_translation['.($i+1).']/title.language)') === $language, 'A title language was inferred from position.');
    }
    check($x->evaluate('string(/record/Titel_translation[1]/title.type)') === 'alternatieve titel', 'Title type moved during filtering.');
    [$x] = mapped(['TranslatedTitleTxt'=>'<Title><title>Legacy English</title><title.language>en</title.language></Title><Title><title>No label</title></Title>'], $profile);
    check($x->evaluate('count(/record/Titel_translation)') === 1.0 && $x->evaluate('string(/record/Titel_translation/title.language)') === 'Engels', 'Old export format bypassed language validation.');

    [$x] = mapped([
        'CreatorTxt'=>'<Production><creator>Maker A</creator><birth.place>Testplaats A</birth.place><birth.place.lref>place-a</birth.place.lref><creator.role>schilder</creator.role><creator.qualifier>naar</creator.qualifier></Production>'
            .'<Production><creator>Maker B</creator><death.place>Testplaats B</death.place><death.place.lref>place-b</death.place.lref></Production>'
            .'<Production><creator>Maker C</creator></Production>',
        'RelationsTxt'=>'<Parts><parts_reference>CHILD-A</parts_reference><parts_reference.lref>child-a</parts_reference.lref><parts.title>Deel A</parts.title></Parts>'
            .'<Related_object><related_object.reference>OTHER-A</related_object.reference><related_object.reference.lref>other-a</related_object.reference.lref><related_object.association>verwant aan</related_object.association></Related_object>'
            .'<Parts><parts_reference>CHILD-B</parts_reference></Parts>'
            .'<Part_of><part_of_reference>PARENT-A</part_of_reference><part_of.title>Map</part_of.title></Part_of>'
            .'<Related_object><related_object.reference>OTHER-B</related_object.reference><related_object.title>Ander werk</related_object.title></Related_object>'
            .'<Parts><parts.title>Geen verwijzing</parts.title></Parts>',
        'DimensionTxt'=>'<Dimension><dimension.type>Hoogte x Breedte x Diepte</dimension.type><dimension.value>101 x 76 x 5</dimension.value><dimension.unit>cm</dimension.unit><dimension.part>met kader</dimension.part></Dimension>'
            .'<Dimension><dimension.type>Hoogte x Breedte</dimension.type><dimension.value>75 x 50</dimension.value><dimension.unit>cm</dimension.unit><dimension.part>geheel</dimension.part></Dimension>'
            .'<Dimension><dimension.type>tijd</dimension.type><dimension.value>24 min. (video)</dimension.value><dimension.unit>min.</dimension.unit><dimension.part>video</dimension.part><dimension.notes>24 min. 2 sec.</dimension.notes></Dimension>'
            .'<dimension.free>Exacte displaytekst met alle onderdelen</dimension.free>',
        'DocumentationTxt'=>'<Documentation><documentation.title>Boek</documentation.title><author.name>Auteur A</author.name><author.name>Auteur B</author.name></Documentation><Documentation><documentation.title>Zonder auteur</documentation.title></Documentation>',
        'StyleTxt'=>'<school_style>stijl A</school_style><school_style>stijl B</school_style>',
    ], $profile);
    check($x->evaluate('string(/record/Production[1]/creator/birth.place/term)') === 'Testplaats A', 'Birthplace lost.');
    check($x->evaluate('string(/record/Production[1]/creator/birth.place.lref)') === 'place-a', 'Birthplace source reference lost.');
    check($x->evaluate('string(/record/Production[2]/creator/death.place/term)') === 'Testplaats B', 'Deathplace lost.');
    check($x->evaluate('count(/record/Production[1]/creator/death.place | /record/Production[2]/creator/birth.place | /record/Production[3]/creator/birth.place | /record/Production[3]/creator/death.place)') === 0.0, 'Places shifted between creators.');
    check($x->evaluate('count(/record/Parts)') === 2.0 && $x->evaluate('count(/record/Part_of)') === 1.0 && $x->evaluate('count(/record/Related_object)') === 2.0, 'Relations dropped or invented.');
    check($x->evaluate('string(/record/Parts[1]/parts_reference.lref)') === 'child-a', 'Relation reference changed.');
    check($x->evaluate('count(/record/Parts[2]/parts.title | /record/Parts[2]/parts_reference.lref | /record/Related_object[2]/related_object.association)') === 0.0, 'Optional relation fields shifted.');
    check($x->evaluate('count(/record/Related_object[1]/related_object.association/term)') === ($profile === AxiellProfile::Msk ? 1.0 : 0.0), 'Wrong relation type structure for the fix.');
    check($x->evaluate('count(/record/Dimension)') === 6.0, 'Compound dimensions not completely split.');
    foreach ([['hoogte','101','met kader'], ['breedte','76','met kader'], ['diepte','5','met kader'], ['hoogte','75','geheel'], ['breedte','50','geheel']] as $i=>[$type,$value,$part]) {
        $path = '/record/Dimension['.($i+1).']';
        foreach (['type'=>$type,'value'=>$value,'part'=>$part,'unit'=>'cm'] as $field=>$expected) check($x->evaluate('string('.$path.'/dimension.'.$field.')') === $expected, 'Dimension value/type/part/unit misaligned.');
    }
    check($x->evaluate('string(/record/Dimension[6]/dimension.type)') === 'duur', 'Time measurement not labelled as duration.');
    check($x->evaluate('string(/record/Dimension[6]/dimension.value)') === '24' && $x->evaluate('string(/record/Dimension[6]/dimension.notes)') === '24 min. 2 sec.', 'Textual duration lost or guessed.');
    check($x->evaluate('string(/record/dimension.free)') === 'Exacte displaytekst met alle onderdelen', 'Dimension display changed.');
    check($x->evaluate('count(/record/Documentation[1]/documentation.title/Author)') === 2.0 && $x->evaluate('string(/record/Documentation[1]/documentation.title/Author[2]/author.name)') === 'Auteur B', 'Bibliographic authors merged or lost.');
    check($x->evaluate('count(/record/Documentation[2]/documentation.title/Author)') === 0.0, 'Author shifted between documents.');
    check($x->evaluate('count(/record/school_style)') === 2.0, 'Repeated styles lost.');
    [$x] = mapped(['DimensionTxt'=>'<dimension.free>eerste beschrijving</dimension.free><dimension.free>variabel (de video duurt 28 minuten)</dimension.free><dimension.free>eerste beschrijving</dimension.free>'], $profile);
    check($x->evaluate('count(/record/dimension.free)') === 1.0, 'Display dimensions must remain one scalar field for the fixes.');
    check($x->evaluate('string(/record/dimension.free)') === 'eerste beschrijving; variabel (de video duurt 28 minuten)', 'Repeated dimension display text was lost or duplicated.');

    $translations = '';
    foreach (['Frans titel'=>'Frans', 'Engelse'=>'Engels', 'Engles'=>'Engels', 'nEngels'=>'Engels', 'Engels (Tavernier)'=>'Engels'] as $label=>$language) {
        $translations .= '<Titel_translation><title.translation>Titel '.$label.'</title.translation><title.language>'.$label.'</title.language></Titel_translation>';
    }
    [$x, $warnings] = mapped(['TranslatedTitleTxt'=>$translations], $profile);
    check($x->evaluate('count(/record/Titel_translation[title.language="Frans"])') === 1.0 && $x->evaluate('count(/record/Titel_translation[title.language="Engels"])') === 4.0 && $warnings === [], 'Recognisable explicit title labels were lost.');

    [$x, $warnings] = mapped(['CurrentLocationTxt'=>'<Current_location><current_location>intern rek</current_location><op>Op reis;Nu te zien in Permekemuseum;Nu te zien in Ensorhuis;Brugse Vrije;Arentshuis;Gezellehuis;O.L.V.-kerk Museum;kunststof, inert, gesloten;80*60*42 cm</op></Current_location>'], $profile);
    check($x->evaluate('count(/record/Current_location/current_location/op)') === 7.0 && count($warnings) === 2, 'Public locations lost or packaging text exposed as a public label.');
    check($x->evaluate('string(/record/Current_location/current_location/op[2])') === 'Nu te zien in Permekemuseum', 'Public location text changed.');

    [$x] = mapped(['DimensionTxt'=>'<Dimension><dimension.value>24 min. (video)</dimension.value><dimension.unit>min.</dimension.unit><dimension.part>video</dimension.part><dimension.notes>duur' . "\n" . '24 min. 2 sec.</dimension.notes></Dimension>'
        .'<Dimension><dimension.value>9</dimension.value><dimension.unit>cm</dimension.unit><dimension.notes>een onduidelijke tekst</dimension.notes></Dimension>'
        .'<Dimension><dimension.type>breedte</dimension.type><dimension.value>5</dimension.value><dimension.notes>duur</dimension.notes></Dimension>'], $profile);
    check($x->evaluate('string(/record/Dimension[1]/dimension.type)') === 'duur' && $x->evaluate('string(/record/Dimension[1]/dimension.value)') === '24' && $x->evaluate('string(/record/Dimension[1]/dimension.part)') === 'video', 'Explicit duration note not recovered or source value changed.');
    check($x->evaluate('string(/record/Dimension[1]/dimension.notes)') === 'duur 24 min. 2 sec.', 'Duration notes lost.');
    check($x->evaluate('count(/record/Dimension[2]/dimension.type)') === 0.0 && $x->evaluate('string(/record/Dimension[3]/dimension.type)') === 'breedte', 'Unknown measurement type guessed or explicit source type overwritten.');
}
echo "PASS: explicit title languages only; grouped life places and relations; full measurements; multiple authors/styles in all four profiles\n";

$revised = [
    'CollectionTxt' => <<<'XML'
        <collection>Museum voor Schone Kunsten Gent</collection>
        <collection>Prentenkabinet</collection>
        <collection.fr>Cabinet des estampes</collection.fr>
        <collection.en>Print room</collection.en>
        <collection>Archief</collection>
        XML,
    'ObjectNameTxt' => <<<'XML'
        <Object_name><object_name>tekening</object_name><object_name.en>drawing</object_name.en>
          <object_name.source>http://vocab.getty.edu/aat/300033973</object_name.source>
          <object_name.term.number>300033973</object_name.term.number>
          <object_name.notes>Materiële vorm</object_name.notes></Object_name>
        XML,
    'CreatorTxt' => <<<'XML'
        <Production><creator>Maker zonder plaats</creator></Production>
        <Production>
          <creator>Maker met plaats</creator><creator.fr>Nom français</creator.fr><creator.en>English name</creator.en>
          <creator.source>https://rkd.nl/artists/</creator.source><creator.source.number>1234</creator.source.number>
          <creator.source>https://www.wikidata.org/wiki</creator.source><creator.source.number>Q567</creator.source.number>
          <creator.source>http://viaf.org/viaf/9876</creator.source><creator.source.number>9876</creator.source.number>
          <creator.url>https://example.invalid/artist</creator.url><creator.place>Gent</creator.place>
          <creator.role>schilder</creator.role><creator.role.fr>peintre</creator.role.fr><creator.role.en>painter</creator.role.en>
          <creator.role.source>http://vocab.getty.edu/aat/</creator.role.source><creator.role.term.number>300025136</creator.role.term.number>
          <birth.place>Brugge</birth.place><birth.place.fr>Bruges</birth.place.fr><birth.place.en>Bruges</birth.place.en>
          <birth.place.source>http://vocab.getty.edu/tgn/;https://www.wikidata.org/wiki</birth.place.source>
          <birth.place.term.number>7008085;Q12994</birth.place.term.number>
          <death.place>Luik</death.place><death.place.fr>Liège</death.place.fr>
          <death.place.source>TGN</death.place.source><death.place.term.number>7008024</death.place.term.number>
        </Production>
        XML,
    'ProductionPlaceTxt' => <<<'XML'
        <production.place>Parijs</production.place>
        <production.place>Gent, 7007887</production.place><production.place.fr>Gand</production.place.fr><production.place.en>Ghent</production.place.en>
        <production.place.source>http://vocab.getty.edu/tgn/</production.place.source>
        XML,
    'PeriodTxt' => <<<'XML'
        <Production_period><production.period>19de eeuw</production.period><production.period.en>19th century</production.period.en>
          <production.period.notes>Bronnotitie</production.period.notes></Production_period>
        XML,
    'TechniqueTxt' => <<<'XML'
        <Technique><technique>ets</technique><technique.fr>eau-forte</technique.fr><technique.en>etching</technique.en>
          <technique.source>AAT-Ned</technique.source><technique.term.number>300041365</technique.term.number></Technique>
        XML,
    'InscriptionTxt' => <<<'XML'
        <Inscription><inscription.type>watermerk</inscription.type><inscription.type.fr>filigrane</inscription.type.fr>
          <inscription.type.source>https://iconclass.org/</inscription.type.source>
          <inscription.type.source>http://vocab.getty.edu/aat/</inscription.type.source>
          <inscription.type.term.number>300028797</inscription.type.term.number></Inscription>
        <Inscription><inscription.content>Brongeschreven tekst</inscription.content></Inscription>
        XML,
    'MotifGeneralTxt' => <<<'XML'
        <content.motif.general>dieren</content.motif.general>
        <content.motif.general.source>Iconclass</content.motif.general.source><content.motif.general.term.number>25F</content.motif.general.term.number>
        <content.motif.general>figuren</content.motif.general><content.motif.general.en>figures</content.motif.general.en>
        XML,
    'MotifSpecificTxt' => <<<'XML'
        <Content_motif_specific><content.motif.specific>stoel</content.motif.specific><broader_term>meubilair</broader_term>
          <content.motif.specific.fr>chaise</content.motif.specific.fr>
          <source>https://iconclass.org/</source><source>http://vocab.getty.edu/aat/</source>
          <term.number>300037772</term.number><term.number>41A721</term.number></Content_motif_specific>
        <Content_motif_specific><content.motif.specific>persoon</content.motif.specific>
          <source>Iconclass</source><term.number>61B2(NAAM</term.number><term.number>Voornaam)</term.number></Content_motif_specific>
        <Content_motif_specific><content.motif.specific>zonder code</content.motif.specific><source>http://iconclass.org/</source></Content_motif_specific>
        XML,
    'ExhibitionTxt' => <<<'XML'
        <Exhibition><exhibition>Tentoonstelling</exhibition>
          <exhibition.organiser>Museum A</exhibition.organiser><exhibition.organiser>Museum B</exhibition.organiser>
          <exhibition.venue.place>Gent</exhibition.venue.place><exhibition.venue.place>Brugge</exhibition.venue.place></Exhibition>
        XML,
    'DocumentationTxt' => <<<'XML'
        <Documentation><documentation.title>De publicatie</documentation.title><author.name>Auteur A</author.name>
          <author.name>Auteur B</author.name><publisher>Uitgever A</publisher><publisher>Uitgever B</publisher>
          <place_of_publication>Gent</place_of_publication><place_of_publication>Brugge</place_of_publication></Documentation>
        XML,
    'AcquisitionTxt' => '<Acquisition_source><acquisition.method>koop</acquisition.method><acquisition.method.en>purchase</acquisition.method.en></Acquisition_source>',
];

$msk = ['MuseumTxt' => '<institution.name>Museum voor Schone Kunsten Gent</institution.name>'];
[$x] = mapped($msk + $revised, AxiellProfile::Msk);
$xpathValues = [
    '/record/collection[term="Prentenkabinet"]/notes[2]' => 'Print room',
    '/record/Object_name/object_name/notes[2]' => 'drawing',
    '/record/Object_name/object_name/source' => 'http://vocab.getty.edu/page/aat/',
    '/record/Object_name/object_name.notes' => 'Materiële vorm',
    '/record/Production[creator/name="Maker met plaats"]/creator/language_script[2]' => 'English name',
    '/record/Production/creator/Source[source="RKDartists&"]/source.number' => '1234',
    '/record/Production/creator/Source[source="Wikidata"]/source.number' => 'Q567',
    '/record/Production/creator/Source[source="VIAF"]/source.number' => '9876',
    '/record/Production/creator/url' => 'https://example.invalid/artist',
    '/record/Production/creator/birth.place/source[1]' => 'http://vocab.getty.edu/page/tgn/',
    '/record/Production/creator/birth.place/term.number[2]' => 'Q12994',
    '/record/Production/creator/death.place/notes[1]' => 'Liège',
    '/record/Production[creator/name="Maker met plaats"]/creator.place' => 'Gent',
    '/record/Production[creator/name="Maker met plaats"]/production.place/notes[1]' => 'Gand',
    '/record/Production[creator/name="Maker met plaats"]/production.place/term.number' => '7007887',
    '/record/Production[not(creator)]/production.place' => 'Parijs',
    '/record/Production/creator.role/notes[2]' => 'painter',
    '/record/production.period/notes[2]' => '19th century',
    '/record/production.period.notes' => 'Bronnotitie',
    '/record/Technique/technique/notes[1]' => 'eau-forte',
    '/record/Inscription[not(inscription.content)]/inscription.type/notes[1]' => 'filigrane',
    '/record/Inscription[not(inscription.content)]/inscription.type/source[1]' => 'http://vocab.getty.edu/page/aat/',
    '/record/content.motif.general[term="dieren"]/term.code' => '25F',
    '/record/content.motif.general[term="figuren"]/notes[2]' => 'figures',
    '/record/content.motif.specific[term="stoel"]/source[2]' => 'http://iconclass.org/',
    '/record/content.motif.specific[term="stoel"]/term.number[2]' => '41A721',
    '/record/content.motif.specific[term="persoon"]/term.number' => '61B2(NAAM, Voornaam)',
    '/record/Exhibition/exhibition/organiser/organiser[.="Museum B"]' => 'Museum B',
    '/record/Exhibition/exhibition/venue/venue[.="Brugge"]' => 'Brugge',
    '/record/Documentation/documentation.title/title' => 'De publicatie',
    '/record/Documentation/documentation.title/Publisher/publisher' => 'Uitgever A; Uitgever B',
    '/record/Documentation/documentation.title/Publisher/place_of_publication' => 'Gent; Brugge',
    '/record/acquisition.method/notes[2]' => 'purchase',
];
foreach ($xpathValues as $path => $expected) {
    check($x->evaluate('string('.$path.')') === $expected, 'Incorrect field in the revised report mapping: '.$path);
}
check($x->evaluate('count(/record/collection[term="Archief"]/notes | /record/Production[creator/name="Maker zonder plaats"]/production.place)') === 0.0, 'Optional fields moved between repeated source rows.');
check($x->evaluate('count(/record/content.motif.specific[term="zonder code"]/source)') === 0.0, 'An Iconclass base URI became a concept identifier.');

foreach ([AxiellProfile::Brugge, AxiellProfile::Muzee, AxiellProfile::Vkc] as $profile) {
    [$x] = mapped(['MuseumTxt' => '<institution.name>Mu.ZEE, Kunstmuseum aan zee</institution.name>'] + $revised, $profile);
    check($x->evaluate('string(/record/Production/creator/Source[source="https://viaf.org/viaf/"]/source.number)') === '9876', 'Wrong creator authority format for '.$profile->value);
    check($x->evaluate('string(/record/Production/creator/Source[source="https://example.invalid/artist"]/source)') === 'https://example.invalid/artist', 'Creator website missing from the legacy Source format.');
    check($x->evaluate('string(/record/Production[production.place="Gent"]/production.place.en)') === 'Ghent', 'Flat place or translation missing.');
    check($x->evaluate('string(/record/Inscription/inscription.type)') === 'watermerk' && $x->evaluate('string(/record/Inscription/inscription.type.fr)') === 'filigrane', 'Type-only inscription lost or mangled.');
    check($x->evaluate('string(/record/Exhibition/exhibition.venue.place[2])') === 'Brugge', 'Exhibition places lost in '.$profile->value);
    check($x->evaluate('string(/record/Acquisition_source/acquisition.method.en)') === 'purchase', 'Acquisition translation moved out of its source row.');
}
echo "PASS: revised MuseumPlus vocabulary, linked creators and places, Iconclass, bibliography, type-only inscriptions and exhibitions\n";
