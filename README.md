# MuseumPlus-import hervatten

Een volledige import bewaart na elke batch de volgende offset en de tellingen in
`museumplus_import_state`. De records en hun hervatpunt worden in dezelfde
databasetransactie opgeslagen. Een afgebroken batch wordt opnieuw opgehaald;
eerder opgeslagen batches blijven in `records_import` staan.

De onderstaande installatie- en importcommando's zijn voor de operator om zelf
uit te voeren. Ze verbinden met de geconfigureerde database en MuseumPlus-API.

## Gewijzigde export met priref en Txt-velden

De op 14 oktober aangeleverde response gebruikt `priref` in plaats van `ID` en
nieuwe kolomnamen, zoals `NumberTxt`, `MuseumTxt` en `TitleTxt_nl`. De parser
ondersteunt beide formaten en vertaalt de nieuwe namen naar de bestaande
mappingpaden. De datumgroep `Production_date` in `ProductionDateTxt` wordt daarbij
ook herkend. Opgeslagen bron-XML behoudt de oorspronkelijke veldnamen.

De oudere parser sloeg objecten met alleen `priref` over: daardoor kon een volle
batch `Stored 0 records` melden en toch de volgende offset ophalen. Een niet-lege
batch zonder enig herkenbaar `ID` of `priref` stopt nu vóór opslag of voortgang.
Tegenstrijdige waarden wanneer beide ID-velden aanwezig zijn stoppen eveneens.
Deze formaatfouten worden niet automatisch overgeslagen als ongeldige XML.
Meldingen over herstelde tekstescaping staan hier los van: die inhoud blijft behouden.

Heeft de oude run al records overgeslagen, start dan **na uitrol opnieuw vanaf nul**.
De operator voert dit zelf uit:

```bash
php bin/console app:import-museumplus-records --restart
```

Gebruik hiervoor geen `--resume`: dat haalt de eerder overgeslagen offsets niet
opnieuw op. `--restart` wist de tijdelijke import; gepubliceerde records blijven
staan totdat de nieuwe volledige import succesvol wordt omgewisseld. Er is geen
nieuwe migratie nodig. De meegeleverde regressietest controleert de aangeleverde
response en synthetische varianten; niet alle nieuwe lege velden zijn hiermee
inhoudelijk gevalideerd.

Controle van de gevulde velden in record `25001583` / `BL-2021-002` bracht nog zes
ontbrekende subvelden in de uitvoer aan het licht. De mapping behoudt nu ook:

- `Production/creator.lref`;
- `Reproduction/reproduction.reference.lref`;
- `Current_location/current_location.lref`, `.type`, `.date` en `.notes`.

Deze paden komen voor in de lokale oude Adlib-referentie van set `vkc`. De waarden
blijven de aangeleverde MuseumPlus-verwijzingen; ze bewijzen geen overeenkomst met
oude Adlib-ID's. Herhaalde groepen houden hun eigen optionele details. Het publieke
label `Depot` blijft onder `current_location/op`; `extern` blijft de interne naam
en context. Het vraagteken in de makersnaam blijft tekst en wordt niet geïnterpreteerd
als een afzonderlijke kwalificatie. Alle gevulde velden van dit voorbeeld worden
getest; volledige compatibiliteit met de afzonderlijke oude exportprofielen is
hiermee niet vastgesteld.

## Museumselectie via .env

`MUSEUMPLUS_ORG_UNITS` beperkt de export vooraf tot de opgegeven organisaties.
Gebruik één code per regel binnen één paar aanhalingstekens. Komma's en spaties
als scheiding werken ook. Lege regels zijn toegestaan; dubbele codes worden
verwijderd. Onderstaand volledig voorbeeld bevat de drie groepen van Caroline:
Brugge, MSK en Mu.ZEE, gescheiden door lege regels.

Voeg dit zelf toe aan de `.env` van de installatie:

```dotenv
MUSEUMPLUS_ORG_UNITS='
org_1_MuseaBrugge
org_1_Groeningemuseum
org_1_Gruuthusemuseum
org_1_SintJanshospitaal
org_1_OnzeLieveVrouwterPotterie
org_1_StadsarchiefBrugge
org_1_BibliotheekBiekorfBrugge
org_1_BisdomBrugge
org_1_EdeleConfrerievanhetHeiligBloed
org_1_ErfgoedbibliotheekBiekorf
org_1_extern
org_1_BrugseVrije
org_1_PriveverzamelingBrugge
org_1_InFlandersFieldsMuseumMuseaBrugge
org_1_MuseumvoorSchoneKunstenGentMuseaBrugge

org_31_MuseumvoorSchoneKunstenGent
org_31_SintBaafskathedraalGent
org_31_SintNiklaaskerkWillebroek
org_31_VoormaligecollectieMSKGent

org_32_MuZEEKunstmuseumaanzee
org_32_StadOostende
org_32_ProvincieWest-VlaanderenMuZee
org_32_GroeningemuseumMuZee
org_32_particulierMuZee
org_32_OnbekendMuZee
org_32_KoninklijkMuseumvoorMiddenAfrika
org_32_KoninklijkMuseumvoorSchoneKunstenAntwerpenKMSKA
org_32_FondationJeanneMatossian
org_32_FondsRaoulServais
org_32_DelenPrivateBank
'
```

Zet eventuele opmerkingen buiten de aanhalingstekens. De selectie wordt:
`bestaande categorievoorwaarde AND (__orgUnit = code1 OR __orgUnit = code2 …)`.
Zonder deze variabele, of met een lege waarde, blijft alleen de bestaande
categorievoorwaarde actief. Een lijst met uitsluitend komma's wordt geweigerd.
De import en `app:dump-museumplus-response` gebruiken dezelfde selectie.

Een gewijzigde selectie kan niet hervatten vanaf een oud hervatpunt: de offsets
hebben dan een andere betekenis. Start in dat geval handmatig een nieuwe import
met `--restart`; dat wist de tijdelijke import en begint bij offset 0.
Alleen de volgorde, witruimte of dubbele codes veranderen vereist geen herstart.
Bestaande hervatpunten zonder organisatiefilter blijven bruikbaar zolang de
variabele leeg blijft. URL, authenticatie en export-ID worden niet door deze
instelling gewijzigd.

## Installatie van deze wijziging

Laat een lopende import eerst eindigen of onderbreek hem. Rol de gewijzigde code
uit en voer de migraties uit voordat je importeert:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

`Version20260930120000` voegt de voortgangstabel toe. De nieuwe migratie
`Version20260930140000` voegt het foutenlog `museumplus_import_errors` en een teller
voor opeenvolgende ongeldige objecten toe. Bestaande records, tijdelijke
importgegevens en het opgeslagen hervatpunt blijven behouden.

## Een eerder afgebroken import behouden

Oude imports hebben nog geen hervatpunt. Als `records_import` nog de batches van
de afgebroken volledige import bevat, gebruik je eenmalig de offset uit de
laatste mislukte `Fetching offset ...`-melding. Bijvoorbeeld:

```bash
php bin/console app:import-museumplus-records --resume --start-offset=309000
```

Dit behoudt die rijen en haalt vanaf 309000 verder op in `records_import`. Gebruik
dezelfde export, filters en identificatie-instellingen als bij de afgebroken run.
De code controleert dat de bestaande rijen één importdatum hebben en dat hun
aantal niet groter is dan de opgegeven offset. De juiste bron en offset van een
oude run kunnen niet automatisch worden bewezen. Gebruik nooit zomaar het aantal
opgeslagen rijen als offset: records zonder ID worden overgeslagen. De oude
telling van ongeldige XML-fragmenten is niet beschikbaar en begint bij herstel
op nul.

Bij elke volgende onderbreking volstaat:

```bash
php bin/console app:import-museumplus-records --resume
```

## Elk uur automatisch hervatten

Het script `bin/resume-import.sh` hervat de bestaande import met `--resume`.
Het gebruikt `flock` om overlappende runs over te slaan. Bij een API-fout stopt
het script; cron probeert het het volgende uur opnieuw. Na een voltooide import
worden geen records meer opgehaald. Zonder bestaand hervatpunt geeft het een fout.

Voer dit na het uitrollen handmatig uit op de server als gebruiker `vkc`:

```bash
bash /opt/museumplus-oai-adapter/bin/resume-import.sh
```

Gebruik `crontab -e` als diezelfde gebruiker en voeg deze regel toe, of vervang
de eerder ingestelde importregel ermee:

```cron
0 * * * * /bin/bash /opt/museumplus-oai-adapter/bin/resume-import.sh
```

PHP en `flock` moeten beschikbaar zijn in het zoekpad van cron. Het script werkt
vanuit elke werkmap. Het schrijft de uitvoer naar `~/museumplus-import.log` en
overschrijft dat bestand alleen wanneer een nieuwe poging daadwerkelijk start.
Een overgeslagen run verandert het logbestand niet. De foutcode van PHP blijft
behouden; een overgeslagen run eindigt zonder fout.

```bash
tail -f ~/museumplus-import.log
```

Start ook handmatige hervatpogingen via dit script om dezelfde lock te gebruiken.
Verwijder `~/.museumplus-import.lock` niet: het bestand mag blijven staan en de
lock wordt automatisch vrijgegeven wanneer het proces eindigt. De applicatie
heeft daarnaast een databaselock tegen imports in dezelfde database.

## Nieuwe imports

```bash
php bin/console app:import-museumplus-records
```

Een onvoltooide import wordt automatisch hervat. Anders begint een nieuwe
volledige import. `--resume` vereist bestaande voortgang en doet niets als die
import al gepubliceerd is. Dat maakt de optie ook bruikbaar als het proces vlak
na het publiceren stopte. `--restart` gooit bewust de tijdelijke import weg en
begint vanaf nul. Zonder die optie worden bestaande tijdelijke gegevens zonder
hervatpunt nooit stilzwijgend gewist.

Een nieuwe volledige import leegt `records_import` met `TRUNCATE TABLE`.
Bij `--restart` wordt ook de voorafgaande volledige telling overgeslagen.
`TRUNCATE` is niet terugdraaibaar en kan wachten op bestaande databaselocks.
Laat een eerdere import en eventuele rollback dus eerst eindigen.

De adapter bewaart vóór het leegmaken de status `resetting`, buiten de
`TRUNCATE`. Pas daarna wordt het hervatpunt op `fetching` gezet. Bij een
onderbreking tijdens deze reset weigert de adapter gewoon hervatten en vraagt
hij opnieuw `--restart`. Zo wordt een oud hervatpunt nooit gebruikt met een
leeggemaakte tabel. De gepubliceerde tabel `records` en het foutenlog blijven
behouden. Voor deze wijziging is geen extra migratie nodig; `TRUNCATE` vereist
het bestaande `DROP`-recht dat ook bij publiceren wordt gebruikt.

De standaardbatch is 1000. `--batch-size` mag ook tijdens hervatten veranderen.
Transportfouten en HTTP 500/502/503/504 krijgen samen maximaal drie herpogingen
per batch. Ongeldige XML wordt niet opnieuw geprobeerd. Herkenbare objectexports
met ongeldige XML worden automatisch verkleind, zoals hieronder beschreven. De volledige XML
wordt eerst gecontroleerd: ook complete records uit een afgebroken response
worden niet rechtstreeks opgeslagen.
Bij langdurige uitval stopt het commando met een fout; na herstel start je het opnieuw met
`--resume`. Er draait geen achtergrondproces dat op herstel blijft wachten.

Bijvoorbeeld: na `Stored 1000 records` voor offset 393000 en een aanhoudende
verbindingsfout op offset 394000 bewaart de import 394000 als hervatpunt. Start opnieuw met alleen
`--resume`. Laat de eenmalige oude `--start-offset=309000` weg: die is niet meer
gelijk aan het opgeslagen hervatpunt.

`--max-records` en een losse `--start-offset` behouden hun eerdere betekenis:
rechtstreeks bijwerken in `records`, zonder de volledige import te hervatten.
Gebruik voor herstel dus altijd `--resume`. `--max-records` kan niet gecombineerd
worden met `--resume` of `--restart`.

## Records opnieuw opbouwen voor API-tests

`app:rebuild-museumplus-records` leest de bewaarde `museumplus_xml` uit
`records_import` en verwerkt die opnieuw met de huidige parser, mapping en
XML-renderer. De gewone import gebruikt dezelfde `MuseumPlusRecordBuilder`.
Oude `oai_xml`, objectnummers, sets en OAI-identifiers worden niet overgenomen.
Dit commando vervangt het eerdere voorstel om oude omzettingen via SQL te kopiëren.

Na uitrollen van de code voert de operator op **UAT/test** zelf uit:

```bash
cd /opt/museumplus-oai-adapter
php bin/console app:rebuild-museumplus-records
```

`records` moet leeg zijn. Er wordt geselecteerd op de vier bronlabels
`Musea Brugge`, `Musea Brugge - Groeningemuseum`, `Mu.ZEE, Kunstmuseum aan zee`
en `Museum voor Schone Kunsten Gent`. Andere labels worden niet meegenomen.
Alleen deze selectie wordt opgebouwd, niet de volledige verzameling van ruim
659.000 records. Het commando leest telkens maximaal 100 bronrecords in geheugen.

De huidige instellingen bepalen de OAI-prefix en de terugvalset; de huidige
importlogica bepaalt de museumsets. Alle nieuwe records krijgen als datestamp
het UTC-tijdstip waarop de herbouw start. Dat is de omzettingsdatum, niet de datum
waarop MuseumPlus opnieuw werd bevraagd.

Na een mappingwijziging kun je expliciet opnieuw met een schone tabel beginnen:

```bash
php bin/console app:rebuild-museumplus-records --replace
```

`--replace` vervangt **alle bestaande records** door de opnieuw opgebouwde
museumselectie. Verwijderen en invoegen gebeuren in één transactie: bij een fout,
ongeldige Object-XML, een afwijkend MuseumPlus-ID of een lege selectie wordt alles
teruggedraaid. Zonder `--replace` weigert het commando een niet-lege tabel.

Bij ongeldige veld-XML herstelt de parser twee bekende escapeproblemen: losse
ampersands in tekst en letterlijke termen zoals `<weeginstrumenten naar vorm>`
in `object_name` of `object_category`. Het herstel wordt alleen gebruikt als het
volledige fragment daarna geldige XML is. Import en herbouw melden elk herstel
met het MuseumPlus-ID en het veld; de herbouw toont ook een afzonderlijk totaal.
De oorspronkelijke `museumplus_xml` blijft intact.

Andere ongeldige XML-fragmenten binnen een veld worden weggelaten. Het commando
meldt daarvoor telkens het MuseumPlus-ID, het veld en de fout, plus een eindtotaal.
Afgebroken Object-XML wordt niet hersteld. Een succesvolle run garandeert dus geen
volledige veldinhoud: controleer deze waarschuwingen en de API-output.

De databaselock voorkomt overlap met een import. `records_import`, de historische
foutmeldingen en het hervatpunt blijven intact. Zet de importcron tijdens de
analyse tijdelijk uit: als een volledige import later afloopt, vervangt die
`records` opnieuw. Je kunt de cron na het testen hervatten.

De adapter doet bij deze herbouw geen MuseumPlus-aanvragen. Dit test de huidige
adapter op eerder ontvangen brondata; of MuseumPlus vandaag dezelfde data
terugstuurt, kan pas na het onderhoud opnieuw gecontroleerd worden.

Na afloop is de selectie beschikbaar via de gewone test-API, bijvoorbeeld:

```text
/oai?verb=ListRecords&metadataPrefix=oai_adlib
```

Om identifiers voor gerichte `GetRecord`-tests te vinden:

```sql
SELECT museumplus_id, oai_identifier, set_spec, object_number
FROM records
ORDER BY set_spec, museumplus_id;
```

## Ongeldige objecten automatisch isoleren

Dit werkt standaard, ook bij `--resume`:

1. Bij ongeldige of afgebroken XML van een herkenbare objectexport halveert de
   import de batchgrootte op dezelfde offset: bijvoorbeeld 1000 → 500 → 250 → … → 1.
2. Geldige kleinere batches worden opgeslagen. Alleen de nog niet verwerkte
   posities worden verder onderzocht; er worden geen hele batches overgeslagen.
3. Bij limit 1 wordt die ene positie meteen overgeslagen als het ongeldige
   antwoord één herkenbaar Object met een numeriek `ID` of `priref` bevat. Er zijn geen
   herpogingen of wachttijden voor XML-fouten.
4. Offset, MuseumPlus-ID, foutmelding, ruwe response en importcontext worden in
   `museumplus_import_errors` bewaard. Het foutenlog en het hervatpunt worden
   samen vastgelegd. Als het loggen faalt, schuift het hervatpunt niet op.
5. Na een overgeslagen object wordt meteen de volgende positie met limit 1
   opgehaald. Ook als meerdere objecten achter elkaar fout zijn, blijft de import
   per record verdergaan: elk fout object krijgt één aanvraag en een eigen logregel.
6. Na iedere geldige batch verdubbelt de grootte: 1 → 2 → 4 → 8 → …, tot de
   ingestelde batchgrootte. Bij een nieuwe XML-fout wordt weer gehalveerd. Als een
   verkleind interval volledig geldig blijkt zonder skip, wordt de ingestelde
   grootte direct hersteld.

Transportfouten, HTTP-fouten, lege antwoorden en foutpagina's worden nooit
automatisch als een kapot object overgeslagen. Lege of onherkenbare antwoorden
stoppen de import meteen.
Een volgende run met `--resume` begint dan bij het bewaarde hervatpunt.

Opeenvolgende ongeldige objecten stoppen de import niet meer na drie skips.
De bestaande teller `consecutive_invalid` wordt gebruikt om ook na een
onderbreking midden in zo'n reeks met limit 1 te hervatten. Na een handmatige
`--skip-offset` wordt eveneens eerst één volgend object getest. Hiervoor is geen
extra migratie nodig bovenop `Version20260930140000`.

Automatisch overgeslagen objecten ontbreken in de gepubliceerde volledige import.
Het foutenlog blijft behouden na publiceren en na `--restart`. Een nieuwe import
probeert deze objecten opnieuw; het log vormt geen permanente uitsluitingslijst.
Bij een gedeeltelijke import telt een skip mee voor `--max-records`; het hervatpunt
van een eventuele volledige import blijft ongewijzigd.

## Eén bevestigd problematisch object overslaan

Als een aanvraag met limit 1 steeds faalt op dezelfde positie, kun je die positie
expliciet overslaan. Voor de onderzochte offset 394807 (MuseumPlus-ID 20051081):

```bash
php bin/console app:import-museumplus-records --resume --skip-offset=394807 --batch-size=1
```

Dit werkt alleen als het opgeslagen hervatpunt precies 394807 is en de import nog
bezig is met ophalen. Het commando bewaart eerst 394808 als volgende offset, telt
één overgeslagen record en meldt dit in de console. Daarna haalt het 394808 op en
gaat verder. Eerder opgeslagen batches blijven behouden. Er wordt geen aanvraag
voor 394807 meer gedaan en dit object ontbreekt in de gepubliceerde volledige import.

Als een latere aanvraag faalt, hervat je met alleen `--resume`, zonder
`--skip-offset`. Het opnieuw gebruiken van dezelfde skipoptie wordt geweigerd
zodra de opgeslagen offset verder staat; zo wordt niet per ongeluk nog een object
overgeslagen. De optie werkt alleen voor deze import, niet als permanente uitsluiting
van het object-ID. Het automatische mechanisme blijft actief voor volgende objecten.
Bij deze handmatige skip wordt geen response opgehaald en dus geen foutantwoord
aan het automatische foutenlog toegevoegd.

Gebruik `--skip-offset` niet samen met `--start-offset`, `--max-records` of
`--restart`. Bewaar het ruwe foutantwoord en de consolemelding voor de opvolging.

## Het ruwe antwoord voor één object bekijken

Voer dit na uitrol zelf uit vanuit de projectmap:

```bash
php bin/console app:dump-museumplus-response 394807 var/museumplus-394807.xml
less -N var/museumplus-394807.xml
```

Dit doet één aanvraag met dezelfde export en filters als de import, met limit 1.
394807 is een **offset**, geen object-ID. De response wordt ongewijzigd opgeslagen,
ook bij ongeldige XML of een HTTP-fout. Er worden geen records of hervatpunten
gewijzigd. Een bestaand bestand wordt niet overschreven; kies voor een nieuwe
aanvraag een andere bestandsnaam.

Ook bij een transportfout blijven de al ontvangen bytes bewaard. Het commando
meldt dan een fout. Een bestand met afgebroken XML bevat alleen wat de API heeft
teruggestuurd; ontbrekende inhoud wordt niet gereconstrueerd. In `less` springt
`58g` naar regel 58. Bewaar zulke bestanden buiten de publieke webmap.

## Consistentie

- Bij normale imports blijft de bestaande tabel `records` beschikbaar tot de volledige import klaar is.
- Een databaselock voorkomt twee gelijktijdige importprocessen in dezelfde database.
- Gewijzigde bron- of filterinstellingen blokkeren hervatten. Inloggegevens worden
  niet in de voortgangstabel opgeslagen.
- Het hervatpunt telt verwerkte bronposities, inclusief alle overgeslagen records.
- Een foutpagina met HTTP 200 of ongeldige XML wordt niet als einde van de import gezien.
- De voltooiingsstatus wisselt samen met de records in één `RENAME TABLE`.
  Daardoor wisselt `--resume` de tabellen niet terug na een verloren antwoord.
  Hiervoor worden kort de hulptabellen `museumplus_import_state_next` en
  `museumplus_import_state_old` gebruikt. De databasegebruiker heeft dus ook
  `CREATE`, `DROP`, `ALTER` en `INSERT` nodig.

De tabelwissel vereist MySQL 8/InnoDB of MariaDB 10.6.1+/InnoDB voor herstel bij
een databasecrash. Zie de documentatie van
[MariaDB](https://mariadb.com/docs/server/reference/sql-statements/data-definition/rename-table)
en [MySQL](https://dev.mysql.com/doc/refman/8.0/en/rename-table.html).

Hervatten gebruikt de offset van dezelfde export. Als de bron intussen objecten
toevoegt, verwijdert of anders sorteert, is geen consistente snapshot gegarandeerd.
Een hervatpunt kan veranderingen in de externe export niet compenseren.

## Mapping van de exportstructuur van oktober 2026

Herhaalde groepen blijven bij elkaar, ook wanneer optionele subvelden ontbreken.
De adapter gebruikt nu de afzonderlijke makerrol, kwalificatie en levensdata.
Dubbele datumwaarden zoals `1883;1883` worden één datum; `1946-12-02;1946` wordt
de volledige datum. Tegenstrijdige of ongeldige datums worden weggelaten en gemeld.
Geboorte- en overlijdensintervallen blijven afzonderlijk behouden; datums worden
niet meer uit de weergavenaam afgeleid.

Alle collecties, categorieën en periodes blijven behouden, evenals het nieuwe
specifieke-motiefveld. Verwerving, collectie en techniek volgen waar nodig de
veldstructuur van de lokale MSK-mapping. Vrije objectnaam-, categorie- en
materiaalnotities worden niet als FR/EN-thesaurusvertalingen aangeboden.

Publieke standplaatsen komen uitsluitend uit `Current_location/op`, na controle
tegen de bekende publieke labels in `LabelNormalizer::publicLocation`.
Onbekende labels en verpakkingsnotities worden weggelaten en gemeld. De interne
locatie blijft in `current_location/name`, maar wordt nooit meer als `op` gebruikt.
Breid de lijst alleen uit na controle van een nieuwe legitieme publieke waarde.

`DigitalReferenceValidator` vergelijkt de herkenbare museum-URL's onder
`/collection/work/id|data|representation/` met de objectnummers van hetzelfde
bronrecord. Een afwijkende identifier of verkeerd URL-type wordt weggelaten en
gemeld met MuseumPlus-ID, objectnummer en oorspronkelijke URL. Dit controleert
de bekende Musea Brugge-, MSK- en Mu.ZEE-hostnamen, inclusief het historische
`collectie.museabrugge.be`. Er worden geen URL's opgevraagd. Andere HTTP(S)-vormen
blijven behouden: hun eigendom kan deze lokale controle niet vaststellen.
Er worden geen vervangende PID's bedacht en de losse MuseumPlus-ARK vervangt
de bestaande workPID niet. Het object zelf blijft beschikbaar zonder die PID.
Deze controle garandeert geen globale uniciteit bij onbekende URL-vormen.
Voor MSK wordt een geaccepteerde workPID ook als `Alternative_number` met type
`workpid` doorgegeven: beide bestaande MSK DataHub-mappings lezen hem daar.
Dit is dezelfde bron-URL, zonder nieuw identifierformaat. Een reeds aanwezig
alternatief nummer van type workPID wordt eveneens gevalideerd en niet verdubbeld.

Import en herbouw tonen deze waarschuwingen in de console; het cron-script neemt
ze op in zijn bestaande logbestand. De oorspronkelijke XML blijft bewaard.
Reeds opgebouwde records veranderen pas bij een nieuwe omzetting. Ook hervatten
werkt eerder opgeslagen batches niet automatisch bij.

Na uitrol kan de **operator zelf** de drie testmusea opnieuw opbouwen uit
`records_import`, zonder nieuwe MuseumPlus-aanvragen:

```bash
php bin/console app:rebuild-museumplus-records --replace > var/rebuild-mapping.log 2>&1
```

Dit vervangt de volledige inhoud van `records` door de herbouwde selectie.
`records_import`, het hervatpunt en opgeslagen bron-XML blijven behouden.
Lees `var/rebuild-mapping.log` voor de weggelaten velden en identifiers.
Voor deze mappingwijziging is geen nieuwe migratie nodig.

## Lokale tests zonder externe verbindingen

```bash
php -n tests/import-transport.php
php -n tests/import-resume.php
php -n tests/dump-response.php
php -n tests/rebuild-records.php
php -n tests/xml-fragments.php
php -n tests/mapping.php
php -n tests/export-format.php
```

Deze tests gebruiken uitsluitend een HTTP-mock, een database-mock in geheugen en
fictieve instellingen. Ze starten geen Symfony-kernel en lezen geen `.env`.
Ze controleren onder meer het halveren, behouden van geldige records, automatisch
overslaan, atomair loggen, hervatten en efficiënt ophalen bij opeenvolgende fouten.
De migratie en de SQL-tabelwissel zijn niet op een echte databaseserver uitgevoerd.
De herbouwtests controleren nieuwe omzettingen, behoud van brondata en voortgang,
de lege-tabelcontrole, expliciet vervangen, rollback, batching en waarschuwingen.
Ook deze tests gebruiken uitsluitend een database-mock, geen MariaDB-server.
De fragmenttests controleren tekstherstel, behoud van geldige XML en brondata,
afwijzing van andere XML-fouten en de omzetting van categorieën en periodes met
zowel de oude als de nieuwe bronstructuur.
De exportformaattests controleren ook de aangeleverde `priref`-response tot en met
de uiteindelijke record-XML. De importtests controleren opslag van dit formaat en
behoud van data en hervatpunt bij een ontbrekend of tegenstrijdig ID-formaat.
