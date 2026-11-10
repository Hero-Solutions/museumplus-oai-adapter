<?php
declare(strict_types=1);

// Offline regressions for the source-only mapping and the existing fix contracts.
require __DIR__.'/mapping.php';
use App\Axiell\AxiellProfile;

$msk = ['MuseumTxt' => '<institution.name>Museum voor Schone Kunsten Gent</institution.name>'];
$description = '<Description><description>Unlabelled first</description><description.name>Author A</description.name></Description>'
    .'<Description><description>English description</description><description.language>en</description.language></Description>'
    .'<Description><description>Unlabelled second</description><description.date>2026</description.date></Description>';
foreach ([AxiellProfile::Msk, AxiellProfile::Vkc] as $profile) {
    [$x] = mapped($msk + ['DescriptionTxt' => $description], $profile);
    check($x->evaluate('count(/record/Description)') === 5.0, 'Description slot count changed.');
    check($x->evaluate('string(/record/Description[1]/description)') === '' && $x->evaluate('string(/record/Description[2]/description)') === '', 'Unlabelled description assigned a language.');
    check($x->evaluate('string(/record/Description[3]/description)') === 'English description', 'Explicit English description shifted.');
    check($x->evaluate('string(/record/Description[4]/description.name)') === 'Author A' && $x->evaluate('string(/record/Description[5]/description.date)') === '2026', 'Description context shifted.');
    [$x] = mapped($msk, $profile);
    check($x->evaluate('count(/record/Description)') === 0.0, 'Empty description groups emitted without source text.');
}
[$x] = mapped(['MuseumTxt' => '<institution.name>Mu.ZEE, Kunstmuseum aan zee</institution.name>', 'DescriptionTxt' => $description], AxiellProfile::Vkc);
check($x->evaluate('count(/record/Description)') === 4.0 && $x->evaluate('string(/record/Description[1]/description)') === '', 'Mu.ZEE VKC positional Dutch fallback still applies to unlabelled text.');
foreach ([AxiellProfile::Brugge, AxiellProfile::Muzee] as $profile) {
    [$x] = mapped(['DescriptionTxt' => $description], $profile);
    check($x->evaluate('count(/record/Description)') === 3.0 && $x->evaluate('string(/record/Description[1]/description)') === 'Unlabelled first', 'Non-positional description contract changed.');
}

$place = ['ProductionPlaceTxt' => '<production.place>Augsburg, Duitsland</production.place>'];
[$x] = mapped($msk + $place + ['CreatorTxt' => '<Production><creator>Only maker</creator><creator.role>schilder</creator.role></Production>'], AxiellProfile::Msk);
check($x->evaluate('string(/record/Production[creator/name="Only maker"]/production.place/term)') === 'Augsburg, Duitsland', 'Single-creator place not available to MSK fix.');
check($x->evaluate('count(/record/Production/production.place)') === 1.0, 'Production place duplicated.');
foreach (['<Production><creator>Maker A</creator></Production><Production><creator>Maker B</creator></Production>', ''] as $creators) {
    [$x, $warnings] = mapped($msk + $place + ['CreatorTxt' => $creators], AxiellProfile::Msk);
    check($x->evaluate('count(/record/Production[creator and production.place])') === 0.0, 'Place assigned to arbitrary creator.');
    check($x->evaluate('string(/record/Production[not(creator)]/production.place/term)') === 'Augsburg, Duitsland' && count($warnings) === 1, 'Unassociated place lost or not reported.');
}

foreach (AxiellProfile::cases() as $profile) {
    [$x, $warnings] = mapped(['ExhibitionTxt' =>
        '<Exhibition><exhibition>Year range</exhibition><exhibition.date>1998 - 1998</exhibition.date></Exhibition>'
        .'<Exhibition><exhibition>Month range</exhibition><exhibition.date>1961-03 - 1961-06</exhibition.date></Exhibition>'
        .'<Exhibition><exhibition>Conflict</exhibition><exhibition.date>2001 - 2002</exhibition.date><exhibition.date.start>1999</exhibition.date.start></Exhibition>'
        .'<Exhibition><exhibition>Invalid day</exhibition><exhibition.date>2023-02-29 - 2023-03-01</exhibition.date></Exhibition>'
        .'<Exhibition><exhibition>Reversed</exhibition><exhibition.date>2002 - 2001</exhibition.date></Exhibition>'
        .'<Exhibition><exhibition>Ambiguous</exhibition><exhibition.date>1932</exhibition.date></Exhibition>'], $profile);
    check($x->evaluate('string(/record/Exhibition[1]/exhibition.date.start)') === '1998' && $x->evaluate('string(/record/Exhibition[1]/exhibition.date.end)') === '1998', 'Explicit year range lost.');
    check($x->evaluate('string(/record/Exhibition[2]/exhibition.date.start)') === '1961-03' && $x->evaluate('string(/record/Exhibition[2]/exhibition.date.end)') === '1961-06', 'Date precision changed.');
    check($x->evaluate('string(/record/Exhibition[3]/exhibition.date.start)') === '1999' && $x->evaluate('count(/record/Exhibition[position()>2]/exhibition.date.end)') === 0.0 && count($warnings) === 1, 'Conflicting/invalid/ambiguous bounds guessed.');
    check($x->evaluate('count(/record/Exhibition/exhibition.date)') === 6.0, 'Free exhibition dates were lost.');

    [$x] = mapped(['DimensionTxt' => '<Dimension><dimension.type>duur</dimension.type><dimension.value>ca. 8 min. (geheel)</dimension.value><dimension.unit>min.</dimension.unit><dimension.part>film</dimension.part><dimension.notes>Current note</dimension.notes></Dimension>'
        .'<Dimension><dimension.type>duur</dimension.type><dimension.value>16.24 min.</dimension.value></Dimension>'
        .'<Dimension><dimension.type>duur</dimension.type><dimension.value>24 min. 2 sec.</dimension.value></Dimension>'
        .'<Dimension><dimension.type>duur</dimension.type><dimension.value>9 min.</dimension.value><dimension.unit>cm</dimension.unit></Dimension>'
        .'<dimension.free>Original display</dimension.free>'], $profile);
    check($x->evaluate('string(/record/Dimension[1]/dimension.value)') === '8' && $x->evaluate('string(/record/Dimension[1]/dimension.precision)') === 'ca.', 'Duration number/precision not split.');
    check($x->evaluate('string(/record/Dimension[1]/dimension.part)') === 'film' && $x->evaluate('string(/record/Dimension[1]/dimension.notes[2])') === 'ca. 8 min. (geheel)', 'Duration part changed or source text lost.');
    check($x->evaluate('string(/record/Dimension[2]/dimension.value)') === '16.24', 'Duration reinterpreted as minutes and seconds.');
    check($x->evaluate('string(/record/Dimension[3]/dimension.value)') === '24 min. 2 sec.' && $x->evaluate('string(/record/Dimension[4]/dimension.value)') === '9 min.', 'Compound/conflicting duration guessed.');
    check($x->evaluate('string(/record/dimension.free)') === 'Original display', 'Display dimension changed.');
}

$titles = ['TitleTxt_nl' => '<Title><title>Original source title</title><title.type>oorspronkelijke titel</title.type></Title>',
    'TranslatedTitleTxt' => '<Titel_translation><title.translation>Nederlandse titel</title.translation><title.language>nl</title.language></Titel_translation><Titel_translation><title.translation>English title</title.translation><title.language>en</title.language></Titel_translation>'];
[$x] = mapped($titles, AxiellProfile::Brugge);
check($x->evaluate('string(/record/Title[1]/title)') === 'Nederlandse titel', 'Explicit Dutch translation not consumed by the first-title-only Brugge fix.');
check($x->evaluate('string(/record/Title[2]/title)') === 'Original source title' && $x->evaluate('string(/record/Title[2]/title.type)') === 'oorspronkelijke titel', 'Original title/context discarded.');
check($x->evaluate('string(/record/Titel_translation/title.language)') === 'Engels', 'English title moved to another language.');

echo "PASS: positional language guards, unambiguous MSK production places, complete place names, explicit exhibition ranges, safe duration splitting and Dutch Brugge title selection\n";
