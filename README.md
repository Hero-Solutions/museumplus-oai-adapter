# MuseumPlus-import hervatten

Een volledige import bewaart na elke batch de volgende offset en de tellingen in
`museumplus_import_state`. De records en hun hervatpunt worden in dezelfde
databasetransactie opgeslagen. Een afgebroken batch wordt opnieuw opgehaald;
eerder opgeslagen batches blijven in `records_import` staan.

De onderstaande installatie- en importcommando's zijn voor de operator om zelf
uit te voeren. Ze verbinden met de geconfigureerde database en MuseumPlus-API.

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

De standaardbatch is 1000. `--batch-size` mag ook tijdens hervatten veranderen.
Transportfouten, HTTP 500/502/503/504 en lege of onherkenbare responses krijgen
samen maximaal drie herpogingen per batch. Herkenbare objectexports met ongeldige
XML worden automatisch verkleind, zoals hieronder beschreven. De volledige XML
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

## Ongeldige objecten automatisch isoleren

Dit werkt standaard, ook bij `--resume`:

1. Bij ongeldige of afgebroken XML van een herkenbare objectexport halveert de
   import de batchgrootte op dezelfde offset: bijvoorbeeld 1000 → 500 → 250 → … → 1.
2. Geldige kleinere batches worden opgeslagen. Alleen de nog niet verwerkte
   posities worden verder onderzocht; er worden geen hele batches overgeslagen.
3. Bij limit 1 volgen nog drie herpogingen. Alleen als alle vier antwoorden
   byte voor byte dezelfde ongeldige XML bevatten, met één herkenbaar Object en
   een numeriek ID, wordt die ene positie overgeslagen.
4. Offset, MuseumPlus-ID, foutmelding, ruwe response en importcontext worden in
   `museumplus_import_errors` bewaard. Het foutenlog en het hervatpunt worden
   samen vastgelegd. Als het loggen faalt, schuift het hervatpunt niet op.
5. Daarna gaat de import verder vanaf de volgende positie, met de ingestelde
   batchgrootte. Ook na volledig herstel van een verkleind interval wordt die
   batchgrootte hersteld.

Transportfouten, HTTP-fouten, lege antwoorden, foutpagina's en wisselende
ongeldige antwoorden worden nooit automatisch als een kapot object overgeslagen.
Een volgende run met `--resume` begint dan bij het bewaarde hervatpunt.

Na drie opeenvolgende automatische overslagen stopt de import als ook het vierde
object ongeldige XML blijft geven. Die teller blijft bewaard bij hervatten en
wordt gereset zodra een geldig antwoord volgt, of bij een expliciete handmatige
skip. Zo kan een aanhoudende algemene fout niet stilzwijgend duizenden objecten
doen verdwijnen. Identieke ongeldige antwoorden zijn een praktische aanwijzing,
geen sluitend bewijs dat de fout in het object zit.

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

- De bestaande tabel `records` blijft beschikbaar tot de volledige import klaar is.
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

## Lokale tests zonder externe verbindingen

```bash
php -n tests/import-transport.php
php -n tests/import-resume.php
php -n tests/dump-response.php
```

Deze tests gebruiken uitsluitend een HTTP-mock, een database-mock in geheugen en
fictieve instellingen. Ze starten geen Symfony-kernel en lezen geen `.env`.
Ze controleren onder meer het halveren, behouden van geldige records, automatisch
overslaan, atomair loggen, hervatten en het begrenzen van opeenvolgende fouten.
De migratie en de SQL-tabelwissel zijn niet op een echte databaseserver uitgevoerd.
