<?php
declare(strict_types=1);

// Synthetic MuseumPlus XML; real parser/mapper/renderer, no kernel or clients.
require __DIR__.'/mapping.php';
use App\Axiell\AxiellProfile;

/** Check the scalar inputs consumed by the existing fixes' parallel-array assoc. */
function associations(DOMXPath $x, string $field): array
{
    $terms = $x->query('/record/'.$field);
    $keys = $x->query('/record/'.$field.'.lref');
    check($terms->length === $keys->length, 'Misaligned '.$field.' keys.');
    $result = [];
    foreach ($terms as $i => $term) {
        $key = $keys[$i];
        check($key->childElementCount === 0 && $key->textContent !== '' && str_starts_with($key->textContent, 'adapter-local:'), 'Empty/nested ID would be stringified as HASH(...).');
        $label = $x->query('term', $term)->length ? $x->evaluate('string(term)', $term) : $term->textContent;
        $result[$label] = $key->textContent;
    }
    check(count(array_unique($result)) === count($result), 'Different terms collapse in assoc.');
    ksort($result);
    return $result;
}

$paired = [
    'object_category'=>['ObjectCategoryTxt','Object_category'],
    'production.period'=>['PeriodTxt','Production_period'],
    'content.motif.general'=>['MotifGeneralTxt',null],
    'content.motif.specific'=>['MotifSpecificTxt',null],
    'acquisition.method'=>['AcquisitionTxt','Acquisition_source'],
];
$termsFor = static function (array $labels) use ($paired): array {
    $fields = [];
    foreach ($paired as $field => [$export,$group]) {
        $fields[$export] = '';
        foreach ($labels as $label) {
            $xml = '<'.$field.'>'.htmlspecialchars($label, ENT_XML1|ENT_QUOTES, 'UTF-8').'</'.$field.'>';
            $fields[$export] .= $group === null ? $xml : '<'.$group.'>'.$xml.'</'.$group.'>';
        }
    }
    return $fields;
};
$byMuseum = [];
foreach (['Mu.ZEE, Kunstmuseum aan zee'=>AxiellProfile::Muzee,'Musea Brugge'=>AxiellProfile::Brugge] as $museum=>$native) {
    $institution = ['MuseumTxt'=>'<institution.name>'.$museum.'</institution.name>'];
    $original = [];
    foreach ([$native,AxiellProfile::Vkc] as $profile) {
        [$x,$warnings] = mapped($institution+$termsFor(['Term A','Term B','Term A','Één & twee']), $profile);
        foreach ($paired as $field=>$_) {
            $a = associations($x,$field);
            check(count($a) === 3 && isset($a['Term A'],$a['Term B'],$a['Één & twee']), 'Lost or altered source label.');
            if (isset($original[$field])) check($a === $original[$field], 'Native and VKC term identities disagree.');
            $original[$field] = $a;
        }
        [$reordered] = mapped($institution+$termsFor(['Één & twee','Term B','Term A'])+['NumberTxt'=>'<object_number>OTHER</object_number>'], $profile);
        foreach ($paired as $field=>$_) check(associations($reordered,$field) === $original[$field], 'Term identity depends on order, duplicates, object or run.');
    }
    check(count(array_unique(array_map(fn($a)=>$a['Term A'],$original))) === count($paired), 'Different fields share a local identity.');
    $specific = ['MotifSpecificTxt'=>'<Content_motif_specific><content.motif.specific>Detail</content.motif.specific><broader_term>Context</broader_term></Content_motif_specific>'];
    [$flat] = mapped($institution+$specific, $native);
    [$nested] = mapped($institution+$specific, AxiellProfile::Vkc);
    check(associations($flat, 'content.motif.specific') === associations($nested, 'content.motif.specific'), 'Extra source context changed the term identity across profiles.');
    check($nested->evaluate('string(/record/content.motif.specific/broader_term)') === 'Context', 'Broader term lost while adding a local key.');
    $byMuseum[] = $original;
}
check($byMuseum[0]['object_category']['Term A'] !== $byMuseum[1]['object_category']['Term A'], 'Museum vocabularies conflated.');

$museum = ['MuseumTxt'=>'<institution.name>Mu.ZEE, Kunstmuseum aan zee</institution.name>'];
$materials = static fn(array $notes): string => implode('', array_map(static fn($note)=>'<Material><material>papier</material><material.part>drager</material.part><material.notes>'.htmlspecialchars($note,ENT_QUOTES|ENT_XML1,'UTF-8').'</material.notes></Material>', $notes));
foreach ([AxiellProfile::Muzee,AxiellProfile::Vkc] as $profile) {
    [$x] = mapped($museum+['MaterialTxt'=>$materials(['Oost-Indische inkt op papier'])],$profile);
    check($x->evaluate('string(/record/material_description)') === 'Oost-Indische inkt op papier', 'Raw material description not restored.');
    check($x->evaluate('string(/record/Material/material.notes)') === 'Oost-Indische inkt op papier' && $x->evaluate('string(/record/Material/material.part)') === 'drager', 'Original note or part lost.');
    [$x,$warnings] = mapped($museum+['MaterialTxt'=>$materials(['Arches'."\n".'ets op papier','Arches  ets op papier',''])],$profile);
    check($x->evaluate('string(/record/material_description)') === 'Arches ets op papier' && $warnings === [], 'Source context stripped or equivalent notes treated as ambiguous.');
    [$x] = mapped($museum+['MaterialTxt'=>$materials(['Letterlijk &amp; op papier'])],$profile);
    check($x->evaluate('string(/record/material_description)') === $x->evaluate('string(/record/Material/material.notes)'), 'Material description decoded twice.');
    [$x,$warnings] = mapped($museum+['MaterialTxt'=>$materials(['ets op papier','drager'])],$profile);
    check($x->evaluate('count(/record/material_description)') === 0.0 && count($warnings) === 1 && $warnings[0]['field'] === 'material_description', 'Conflicting notes silently selected/combined.');
    check($x->evaluate('count(/record/Material/material.notes)') === 2.0, 'Ambiguous source notes discarded.');
    [$x,$warnings] = mapped($museum+['MaterialTxt'=>$materials(['ets op papier','drager']),'MaterialDescriptionTxt'=>'<material_description>Explicit current display</material_description>'],$profile);
    check($x->evaluate('string(/record/material_description)') === 'Explicit current display' && $warnings === [], 'Explicit description not given priority.');
    [$x,$warnings] = mapped($museum+['MaterialTxt'=>$materials(['',' '])],$profile);
    check($x->evaluate('count(/record/material_description)') === 0.0 && $warnings === [], 'Empty notes invented a description.');
}
foreach (['Museum voor Schone Kunsten Gent'=>AxiellProfile::Msk,'Musea Brugge'=>AxiellProfile::Brugge] as $name=>$native) {
    foreach ([$native,AxiellProfile::Vkc] as $profile) {
        [$x] = mapped(['MuseumTxt'=>'<institution.name>'.$name.'</institution.name>','MaterialTxt'=>$materials(['A note about one material'])],$profile);
        check($x->evaluate('count(/record/material_description)') === 0.0,'Mu.ZEE display convention applied to another museum.');
    }
}
echo "PASS: scalar stable term keys, multiple terms, cross-profile identity and source-only material display with ambiguity guards\n";
