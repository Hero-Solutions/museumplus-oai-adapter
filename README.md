# MuseumPlus-import hervatten

Een volledige import bewaart na elke batch de volgende offset en de tellingen in
`museumplus_import_state`. De records en hun hervatpunt worden in dezelfde
databasetransactie opgeslagen. Een afgebroken batch wordt opnieuw opgehaald;
eerder opgeslagen batches blijven in `records_import` staan.

De onderstaande installatie- en importcommando's zijn voor de operator om zelf
uit te voeren. Ze verbinden met de geconfigureerde database en MuseumPlus-API.

## Installatie van deze wijziging

Rol de gewijzigde code uit en voer de nieuwe migratie uit voordat je importeert:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

De migratie `Version20260930120000` voegt alleen een voortgangstabel toe. Ze wist
geen bestaande records of tijdelijke importgegevens.

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
Transportfouten en HTTP 500/502/503/504 krijgen drie herpogingen. Bij langdurige
uitval stopt het commando met een fout; na herstel start je het opnieuw met
`--resume`. Er draait geen achtergrondproces dat op herstel blijft wachten.

`--max-records` en een losse `--start-offset` behouden hun eerdere betekenis:
rechtstreeks bijwerken in `records`, zonder de volledige import te hervatten.
Gebruik voor herstel dus altijd `--resume`. `--max-records` kan niet gecombineerd
worden met `--resume` of `--restart`.

## Consistentie

- De bestaande tabel `records` blijft beschikbaar tot de volledige import klaar is.
- Een databaselock voorkomt twee gelijktijdige importprocessen in dezelfde database.
- Gewijzigde bron- of filterinstellingen blokkeren hervatten. Inloggegevens worden
  niet in de voortgangstabel opgeslagen.
- Het hervatpunt telt opgehaalde records, inclusief overgeslagen records zonder ID.
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
```

Deze tests gebruiken uitsluitend een HTTP-mock, een database-mock in geheugen en
fictieve instellingen. Ze starten geen Symfony-kernel en lezen geen `.env`.
De migratie en de SQL-tabelwissel zijn niet op een echte databaseserver uitgevoerd.
