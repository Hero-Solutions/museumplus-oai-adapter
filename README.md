# MuseumPlus OAI-adapter

De adapter haalt MuseumPlus op, bewaart de ongewijzigde responses en maakt per
herkend object twee XML-versies: één voor het museum en één voor VKC.
De OAI-API leest deze opgeslagen versies. De afzonderlijke setformaten blijven
gericht op de bestaande Catmandu-fixes; `vkc.fix` wordt niet gebruikt.

## Importeren

Voer de beheercommando’s zelf uit op de bedoelde omgeving, vanuit deze projectmap.

```bash
php bin/console app:import-museumplus-records
```

Dit hervat een onvoltooide import of begint een nieuwe volledige ronde.
De huidige OAI-dataset blijft beschikbaar totdat de nieuwe import klaar is.

- `--resume`: alleen hervatten; na voltooiing gebeurt niets meer.
- `--restart`: tijdelijke import wissen en bij nul beginnen. Bestaande OAI-records blijven beschikbaar tot de nieuwe import klaar is. Raw bestanden worden nooit gewist.
- `--batch-size=1000`: aantal bronobjecten per aanvraag; standaard 1000.

Bij een storing blijft de voortgang bewaard. Ongeldige XML wordt waar mogelijk
tot één object geïsoleerd; overgeslagen objecten staan in `museumplus_import_errors`.
Een lege import wordt niet gepubliceerd. Gewijzigde bronfilters of mappingversies
vereisen een nieuwe ronde met `--restart`.

Een bestaande cronjob met `bin/resume-import.sh` blijft bruikbaar. Dit script
hervat met `--resume` en schrijft naar `~/museumplus-import.log`.

## Heropbouwen na een mappingwijziging

```bash
php bin/console app:rebuild-museumplus-records --replace
```

Maakt beide XML-versies opnieuw uit de opgeslagen bron voor alle huidige records,
zonder MuseumPlus te benaderen. Een fout draait de volledige wijziging terug.
Raw bestanden en bestaande importvoortgang blijven behouden.

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

Een gecontroleerde match met de oude Axiell-export behoudt de bestaande priref.
Anders wordt het MuseumPlus-ID gebruikt. Alle herkende museumrecords komen in
hun eigen set én VKC, ook zonder publicatievlag. Onbekende instellingen worden
bewaard, maar niet ontsloten.

## Configuratie en bewaren

PHP 8.4+, Composer-afhankelijkheden en MySQL/MariaDB zijn nodig.
De webserver gebruikt `public/` als documentroot. Pas bestaande omgevingsinstellingen
zelf aan; voor deze opschoning zijn geen nieuwe variabelen nodig.

| Instelling | Doel |
| --- | --- |
| `DATABASE_URL` | Databaseverbinding |
| `MUSEUMPLUS_EXPORT_URL`, `MUSEUMPLUS_USERNAME`, `MUSEUMPLUS_PASSWORD` | Export en aanmelding |
| `MUSEUMPLUS_SEARCH_FIELD_PATH`, `MUSEUMPLUS_SEARCH_OPERAND` | Basisfilter, bijvoorbeeld `ObjCategoryVoc` en de bijbehorende code |
| `MUSEUMPLUS_ORG_UNITS` | Organisatiecodes, gescheiden door komma’s of nieuwe regels; leeg betekent geen organisatiefilter |
| `MUSEUMPLUS_TIMEOUT_SECONDS` | Timeout per aanvraag |
| `MUSEUMPLUS_OAI_IDENTIFIER_PREFIX`, `MUSEUMPLUS_DEFAULT_SET_SPEC` | Interne opslagwaarden; bepalen de publieke identifiers niet |

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

Raw responses staan in `var/museumplus-raw/`: één ongewijzigde `.xml` per poging,
met `.json` voor positie, status en checksum. Ook foutantwoorden blijven bewaard.
De database verwijst naar deze bestanden via `raw_response_id`. Maak de map
blijvend en schrijfbaar, en neem XML én JSON mee in de databaseback-up.
De locatie staat in `config/services.yaml` bij `museumplus.raw_directory`.
Er is geen automatische verwijdering. Oude records zonder raw koppeling gebruiken
bij heropbouwen hun opgeslagen `museumplus_xml`; die is niet byte-exact.

Bij uitrol voert de operator ontbrekende migraties uit:
`php bin/console doctrine:migrations:migrate --no-interaction`.
De eerdere migraties voor setprofielen, Axiell-identifiers en raw koppelingen
blijven nodig. Een herstelmigratie maakt ontbrekende importtabellen opnieuw aan,
ook als de eerdere migraties al geregistreerd zijn. Bestaande gegevens blijven behouden.

## Lokaal testen

`php -n -d allow_url_fopen=0 tests/run.php` draait tien suites met lokale fixtures
en mocks, zonder applicatiekernel, echte database of API-aanvragen.

De losse dump/exportcommando’s, gedeeltelijke importopties en ongebruikte
MSK-inventarislijst zijn verwijderd. De OAI-API gebruikt alleen bovenstaand
contract; extra `oai_adlib_*`-prefixen, `msk_gent` en interne adapter-ID-aliases
zijn vervallen. De bestaande Axiell-identifiers blijven behouden.
