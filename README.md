# MuseumPlus OAI-adapter

De adapter haalt MuseumPlus op en bewaart de ongewijzigde responses. Per herkend
object maakt hij alleen de XML-versies die de publicatievlaggen toelaten.
De OAI-API leest deze opgeslagen versies. De afzonderlijke setformaten blijven
gericht op de bestaande Catmandu-fixes; `vkc.fix` wordt niet gebruikt.

## Importeren

Voer de beheercommando’s zelf uit op de bedoelde omgeving, vanuit deze projectmap.

```bash
php bin/console app:import-museumplus-records
```

Dit hervat een onvoltooide import met dezelfde instellingen en mappingversie,
of begint een nieuwe volledige ronde. Bij gewijzigde instellingen of mapping
begint het automatisch opnieuw vanaf nul. De reden verschijnt in het log.
De huidige OAI-dataset blijft beschikbaar totdat de nieuwe import klaar is.

- `--resume`: alleen hervatten; ontbrekende of onbruikbare voortgang geeft een fout. Na voltooiing gebeurt niets meer.
- `--restart`: tijdelijke import wissen en bij nul beginnen. Bestaande OAI-records en hun raw bronnen blijven beschikbaar tot de nieuwe import klaar is.
- `--batch-size=1000`: aantal bronobjecten per aanvraag; standaard 1000.

Bij een storing blijft de voortgang bewaard. Ongeldige XML wordt waar mogelijk
tot één object geïsoleerd; overgeslagen objecten staan in `museumplus_import_errors`.
Een lege import wordt niet gepubliceerd. De volgende standaardrun probeert
opnieuw vanaf nul. Dat gebeurt ook na een onderbroken reset, bij ontbrekende
voortgang of wanneer de tijdelijke records niet meer bij de voortgang passen.
Alleen de tijdelijke import wordt gewist; er worden nooit verschillende
mappingversies samengevoegd.

Voor een dagelijkse cronjob gebruik je het commando zonder `--resume`:

```cron
0 8 * * * cd /opt/museumplus-oai-adapter && php bin/console app:import-museumplus-records --no-interaction >> /opt/.repos/museumplus-oai-adapter/shared/log/import.log 2>&1
```

Een bestaande cronjob met `bin/resume-import.sh` blijft bruikbaar. Dit script
hervat met `--resume` en schrijft naar `~/museumplus-import.log`. Het is bedoeld
om één bestaande import af te werken, niet voor dagelijkse verversing of
automatisch herstarten na een mappingwijziging.

## Heropbouwen na een mappingwijziging

```bash
php bin/console app:rebuild-museumplus-records --replace
```

Berekent de publicatievlaggen en toegestane XML-versies uit de opgeslagen bron
voor alle huidige records, zonder MuseumPlus te benaderen. Vult een verse
`records_rebuild` in batches, met dezelfde kolommen en indexen als `records`.
Na controle van het aantal bronrecords vervangt één atomische tabelwissel de
actieve dataset. Tot dan blijft de API de huidige dataset lezen. Daarna wordt
de oude tabel `records_rebuild_old` verwijderd.

Raw bestanden, bron-XML, bestaande identifiers, `records_import` en de
importvoortgang blijven behouden. Ingetrokken vlaggen verdwijnen uit de
OAI-uitvoer, ook als geen enkel bronrecord nog gepubliceerd mag worden.
Een volledig lege brontabel wordt geweigerd.

Bij een onderbreking: voer hetzelfde commando opnieuw uit. Het bouwt opnieuw
een lege `records_rebuild` op en draait een uitgevoerde tabelwissel nooit terug.
Een fout vóór de wissel laat de actieve tabel intact; na de wissel is de nieuwe
dataset actief. Voor crashbestendige tabelwissels op MariaDB is InnoDB met
[MariaDB 10.6.1 of nieuwer](https://mariadb.com/docs/server/reference/sql-statements/data-definition/rename-table) nodig.

Alleen voor herstel van een afgebroken oude import: voeg
`--source-table=records_import` toe. Dit vervangt de OAI-dataset door de aanwezige
stagingrecords, die onvolledig kunnen zijn.

## OAI-API

Gebruik `/oai`, met `metadataPrefix=oai_adlib`.

| Set | Identifier |
| --- | --- |
| `mskObj` | `mskobj:<priref>` |
| `musea_brugge` | `musea_brugge:<priref>` |
| `muzee` | `muzee:<priref>` |
| `vkc` | `vkc:<priref>` |

Voorbeeld: `/oai?verb=ListRecords&metadataPrefix=oai_adlib&set=mskObj`.
Volg daarna de `resumptionToken` met alleen dezelfde `verb` en de token.
`GetRecord` gebruikt de volledige identifier uit de header.
Ook `Identify`, `ListSets`, `ListMetadataFormats` en `ListIdentifiers` zijn beschikbaar.
GET, POST en datumfilters worden ondersteund.

Inhoud komt uitsluitend uit MuseumPlus. De Axiell-referentie bewaart alleen de
koppeling met bestaande OAI-identifiers. Oude vertalingen, thesaurusnummers,
materiaalomschrijvingen en veldinterpretaties worden niet meer aangevuld.
De afzonderlijk ingestelde FR/EN-namen van MSK Gent blijven behouden.
Materiaalvertalingen en bronverwijzingen die MuseumPlus zelf levert, blijven doorstromen.

De ongewijzigde Brugge- en Mu.ZEE-fixes gebruiken bij sommige termen een parallelle
lijst met ID's. Daarvoor maakt de adapter stabiele sleutels met prefix
`adapter-local:`, afgeleid van museum, veld en term. Het zijn lokale koppelsleutels,
geen oorspronkelijke thesaurusnummers. Zo blijven meerdere termen behouden en
verschijnen geen interne Perl-verwijzingen (`HASH(...)`) in de LIDO-uitvoer.
De bestaande fixes zetten hierbij nog hun vaste bronlabel `Adlib`.

De algemene materiaalomschrijving komt uit `physical_description`. Voor Mu.ZEE
geeft de adapter die ook door als `material_description`, de veldnaam die hun
museum- en VKC-fix verwachten. Oudere expliciete materiaalomschrijvingsvelden
blijven ondersteund als `physical_description` ontbreekt. Er wordt nooit een
omschrijving uit `material.notes` afgeleid. De afzonderlijke materiaaltermen
en oorspronkelijke nota's blijven bij hun materiaal staan.

Vertaalde titels worden alleen opgenomen met een herkenbaar, eenduidig
taallabel (NL/FR/EN/DE). Zonder bruikbaar label worden ze overgeslagen en gemeld;
de adapter leidt de taal nooit af uit de positie. De Brugge-museumfix verwerkt
maar één Nederlandse titel: een expliciete NL-vertaling komt daarom vooraan.
De oorspronkelijke titel blijft daarnaast in de adapter-XML bewaard.
Beschrijvingen zonder betrouwbare taal komen na de gereserveerde taalposities,
zodat MSK en VKC ze zonder gegokt taallabel verwerken.
Geboorte- en sterfteplaatsen blijven bij hun vervaardiger,
objectrelaties bij hun bronverwijzing. Samengestelde afmetingen worden alleen
opgesplitst wanneer types en waarden overeenkomen. Meerdere afmetingsteksten
worden samengevoegd tot één displayveld, zonder de volgende tekst te verliezen.
Een enkelvoudige duur wordt gesplitst in getal en eenheid; de volledige tekst en
eventuele benadering blijven bewaard. Expliciete tentoonstellingsdatumbereiken
leveren begin en einde op, zonder bestaande datums te overschrijven.
Vervaardigingsplaatsen blijven volledig, inclusief land. Alleen bij één vervaardiger
en één plaats wordt die plaats voor de MSK-museumfix bij de vervaardiger gezet.
Andere gevallen blijven apart in XML en krijgen een melding.

Nieuwe bronvelden vereisen een nieuwe import. Heropbouwen verwerkt alleen wat
al in de opgeslagen raw XML aanwezig is.

De paginering selecteert eerst de pagina-ID's via de database-index en haalt in
dezelfde query alleen de benodigde XML-versie voor die pagina op. `ListIdentifiers`
leest alleen de headers. Volgorde, identifiers en resumptionTokens blijven gelijk;
deze optimalisatie vereist geen migratie, import of heropbouw.

Een gecontroleerde match met de oude Axiell-export behoudt de bestaande priref.
Anders wordt het MuseumPlus-ID gebruikt. Voor herkende instellingen bepaalt
`WebPublicationTxt/webpublication` de ontsluiting:

| Vlag | OAI-set |
| --- | --- |
| `Extranet` | Eigen museumset: `mskObj`, `musea_brugge` of `muzee` |
| `VKC datahub` | `vkc` |
| Beide | Eigen museumset én `vkc` |
| Geen van beide | Geen OAI-records en geen gemapte XML |

Hoofdletters en omringende spaties maken geen verschil; andere waarden geven
geen publicatierecht. Alle opgehaalde bronrecords blijven bewaard. Onbekende
instellingen worden niet ontsloten, ook niet met een publicatievlag.
De MuseumPlus-aanvraag selecteert de ingestelde webpublicatie-ID's met OF;
deze controle bepaalt vervolgens welke OAI-versies elk opgehaald object krijgt.

Na uitrol van deze regel: herbouw met `app:rebuild-museumplus-records --replace`.
Tot die heropbouw voltooid is, blijft de eerder opgeslagen settoewijzing actief.
Heropbouwen gebruikt de opgeslagen vlaggen; wijzigingen in MuseumPlus vereisen
een nieuwe import. De OAI-API meldt ingetrokken records niet als verwijderd:
eerder doorgestuurde records verdwijnen hierdoor niet automatisch uit de Datahub.

## Configuratie en bewaren

PHP 8.4+, Composer-afhankelijkheden en MySQL/MariaDB zijn nodig.
De webserver gebruikt `public/` als documentroot. Beheer de omgevingsinstellingen
via Ansible of pas ze zelf aan op de bedoelde omgeving.

| Instelling | Doel |
| --- | --- |
| `DATABASE_URL` | Databaseverbinding |
| `MUSEUMPLUS_EXPORT_URL`, `MUSEUMPLUS_USERNAME`, `MUSEUMPLUS_PASSWORD` | Export en aanmelding |
| `MUSEUMPLUS_WEBPUBLICATION_IDS` | Positieve numerieke webpublicatie-ID's, gescheiden door komma's of witruimte |
| `MUSEUMPLUS_ORG_UNITS` | MuseumPlus-organisatiecodes, gescheiden door komma's of witruimte |
| `MUSEUMPLUS_TIMEOUT_SECONDS` | Timeout per aanvraag |
| `MUSEUMPLUS_OAI_IDENTIFIER_PREFIX`, `MUSEUMPLUS_DEFAULT_SET_SPEC` | Interne opslagwaarden; bepalen de publieke identifiers niet |

Een ingevulde `MUSEUMPLUS_WEBPUBLICATION_IDS` selecteert op
`ObjWebPublicationGrp.WebPublicationVoc`. Bij een ontbrekende of lege waarde
gebruikt de import `MUSEUMPLUS_ORG_UNITS` en zoekt op `__orgUnit`.
De export selecteert records die minstens één waarde uit de actieve lijst matchen.
De import vereist een geldige actieve lijst. De publicatievlaggen op de
opgehaalde records bepalen de OAI-sets.

Stel in Ansible `webpublication_ids` of `org_units` in onder
`museumplus_oai_adapter.museumplus`. Ansible schrijft enkel de actieve lijst.
De volgorde en dubbele waarden veranderen de selectie niet. Na een filterwijziging begint de
volgende standaardimport automatisch opnieuw. Voor een onmiddellijke import
voer je zelf `php bin/console app:import-museumplus-records --env=prod` uit.
Heropbouwen haalt ontbrekende bronrecords niet alsnog op.

Instellingsgroepen staan in `config/museum-groups.php`. Bewaar en deploy ook
`config/axiell-reference.json` en `config/axiell-reference.jsonl`: deze behouden
de gecontroleerde oude identifiers en veldinterpretaties.

De MSK-configuratie bevat vaste Franse en Engelse instellingsnamen. Beide
verschijnen in `mskObj` en de VKC-versie als `institution.name/language_script`,
eerst Frans en dan Engels, zoals de bestaande Catmandu-fixes verwachten.
MSK-periodes krijgen in beide versies de bekende Franse en Engelse vertalingen
uit het Axiell-woordenboek als `production.period/notes`, in dezelfde taalvolgorde.
Voor onbekende periodes worden geen vertalingen verzonnen.
Herbouw na uitrol de opgeslagen records en laat de pipeline opnieuw ophalen.

Samengestelde afmetingen worden in alle vier de sets opgesplitst: bijvoorbeeld
`Hoogte x Breedte` met `12 x 15` wordt `hoogte = 12` en `breedte = 15`.
Eenheid, onderdeel, precisie en notities blijven bij de afmetingen. Als de types
en waarden niet overeenkomen, blijft de oorspronkelijke combinatie behouden.
Een ontbrekend afmetingstype wordt alleen aangevuld als `duur` wanneer de
bronnotitie dit uitdrukkelijk vermeldt op de eerste regel. Waarde en eenheid
blijven behouden.

Materiaalvertalingen komen uitsluitend uit `material.fr` en `material.en`.
MSK en VKC ontvangen ze als `material/notes`: eerst Frans,
dan Engels. Een ontbrekende taal houdt een lege plaats; vrije `material.notes`
blijven apart. Brugge en Mu.ZEE behouden hun platte materiaalveld. Techniek heeft
in de huidige MuseumPlus-export nog geen Franse of Engelse bronvelden.

Raw responses staan in `var/museumplus-raw/`: één ongewijzigde `.xml` per poging,
met `.json` voor positie, status en checksum. Foutantwoorden blijven beschikbaar
tot de volgende importpoging; overgeslagen ongeldige objecten blijven ook in
`museumplus_import_errors` bewaard.
De database verwijst naar deze bestanden via `raw_response_id`. Maak de map
blijvend en schrijfbaar, en neem XML én JSON mee in de databaseback-up.
De locatie staat in `config/services.yaml` bij `museumplus.raw_directory`.
Bij het starten of hervatten en na publicatie verwijdert de import automatisch
raw bestanden waarnaar noch `records` noch `records_import` verwijst. Na een
geslaagde import blijven dus alleen de responses voor de nieuwe dataset over.
Tijdens het ophalen is ruimte nodig voor de actieve én de nieuwe dataset.
Ook oude `offset-*.xml`-dumps en hun `manifest.jsonl` worden opgeruimd; andere
bestanden, submappen en symbolische links blijven staan. Een opruimfout geeft een
waarschuwing; een geslaagde publicatie wordt niet teruggedraaid. Met `--resume`
kan je de opruiming ook na een voltooide import opnieuw laten proberen.
Oude records zonder raw koppeling gebruiken bij heropbouwen hun opgeslagen
`museumplus_xml`; die is niet byte-exact.

Bij uitrol voert de operator ontbrekende migraties uit:
`php bin/console doctrine:migrations:migrate --no-interaction`.
De eerdere migraties voor setprofielen, Axiell-identifiers en raw koppelingen
blijven nodig. Een herstelmigratie maakt ontbrekende importtabellen opnieuw aan,
ook als de eerdere migraties al geregistreerd zijn. Bestaande gegevens blijven behouden.

## Lokaal testen

`php -n -d allow_url_fopen=0 tests/run.php` draait elf suites met lokale fixtures
en mocks, zonder applicatiekernel, echte database of API-aanvragen.

De losse dump/exportcommando’s, gedeeltelijke importopties en ongebruikte
MSK-inventarislijst zijn verwijderd. De OAI-API gebruikt alleen bovenstaand
contract; extra `oai_adlib_*`-prefixen, `msk_gent` en interne adapter-ID-aliases
zijn vervallen. De bestaande Axiell-identifiers blijven behouden.
