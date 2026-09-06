# Integracja Music Map z s-manager i automatyczne wdrażanie po merge

## Podsumowanie

Po aktywacji pierwszego wydania każdy commit w `main` pochodzący ze scalonego PR-a przejdzie pełny CI, zbuduje cztery prywatne obrazy GHCR i — jeżeli nie zmienia schematu — zostanie automatycznie wdrożony przez niezależny reconciler na hoście. Pierwszym wydaniem jest działający szkielet aplikacji, a nie dopiero kompletne MVP.

Rozwiązanie:

- nie korzysta z systemu zatwierdzania, tokenów, raportów ani deployera StorageApp;
- zachowuje dokładne wdrażanie obrazów po digestach i rollback zapewniany przez s-manager;
- blokuje bezpośrednie pushe oraz kandydatów bez powiązanego, scalonego PR-a;
- nie uruchamia migracji podczas zwykłego deployu; wydania zmieniające schemat przechodzą osobną, jawną operację s-manager z backupem, weryfikacją ledgeru i ręczną akceptacją kompatybilności;
- włącza autodeploy po ręcznym wdrożeniu szkieletu i bezpiecznym canary, aby kolejne etapy MVP żyły na hoście;
- pozostawia publiczne `503` podczas pierwszej walidacji i przy braku zdrowego upstreamu.

Zmiany kodu i konfiguracji w `music-map` oraz `/srv/manager` trafiają wyłącznie przez osobne PR-y. Sekrety i stan operacyjny pozostają poza Git.

## Interfejsy i kontrakty

### Artefakt wydania

Workflow publikuje prywatne obrazy:

- `ghcr.io/polkiujhy/music-map-fpm`
- `ghcr.io/polkiujhy/music-map-nginx`
- `ghcr.io/polkiujhy/music-map-queue`
- `ghcr.io/polkiujhy/music-map-scheduler`

Po wypchnięciu wszystkich obrazów tworzy artefakt `music-map-release`, zawierający wyłącznie `release.json`:

```json
{
  "schema_version": 1,
  "release_id": "mm-<sha12>-<run_id>-<attempt>",
  "source_sha": "<pełny 40-znakowy SHA>",
  "images": {
    "fpm": "ghcr.io/polkiujhy/music-map-fpm@sha256:<digest>",
    "nginx": "ghcr.io/polkiujhy/music-map-nginx@sha256:<digest>",
    "queue": "ghcr.io/polkiujhy/music-map-queue@sha256:<digest>",
    "scheduler": "ghcr.io/polkiujhy/music-map-scheduler@sha256:<digest>"
  }
}
```

Manifest nie dopuszcza dodatkowych pól, tagów zamiast digestów ani innych repozytoriów obrazów. Retencja artefaktu: 30 dni.

### Nowe operacje s-manager

- `s-manager initialize-schema music-map --release-file FILE --expected-current none` — jednorazowo uruchamia migracje z dokładnego obrazu FPM, niezależnie sprawdza ledger migracji i zapisuje baseline powiązany z tożsamością bazy.
- `s-manager recover-schema-initialization music-map --receipt-id ID` — usuwa blokadę po nieudanej inicjalizacji wyłącznie wtedy, gdy operator przywrócił bazę, a helper potwierdził ponownie `not_initialized`.
- `s-manager reconcile music-map --once` — sprawdza aktualny `main`, PR, workflow i artefakt, po czym wykonuje kontrolowany deploy.
- `s-manager route music-map --mode maintenance|validation|live [--allow-cidr CIDR]` — atomowo przełącza publiczny routing po walidacji konfiguracji nginx.
- `s-manager status music-map --json` — zostaje rozszerzone o stan baseline, reconciliacji i publicznej trasy.

Wszystkie mutujące operacje obsługują `--dry-run`, blokadę współbieżności, oczekiwany stan oraz sanitowane JSON receipts.

## Fazy implementacji

## Phase 1: Kontrakt i governance zmian

- Zaktualizować [infrastructure.md](/srv/music-map/context/foundation/infrastructure.md), zastępując ręczną promocję automatycznym wdrażaniem po merge i opisując niezależność od StorageApp.
- Naprawić `scripts/verify-source-contract`: usunąć nieistniejące ścieżki, objąć wymagane workflow i dodać test chroniący listę przed ponownym zestarzeniem.
- Skonfigurować regułę `main`: zmiany tylko przez PR, wymagane zielone kontrole CI, brak zwykłego bypassu przez bezpośredni push.
- Formalna liczba approvals nie jest sprawdzana przez host. Decyzja maintainera o merge jest dowodem zakończenia review; reconciler wymaga powiązanego, scalonego PR-a.

Bramka: czysty checkout przechodzi source contract, a bezpośredni commit na `main` nie może zostać wdrożony nawet przy zielonym workflow.

## Phase 2: CI i publikacja kandydatów

- Skonsolidować walidację w jednym zaufanym workflow: testy PHP, Pint, build frontendowy, audyty zależności, obecne skany źródła/SBOM oraz kontrakt kontenerów.
- Dla PR-ów budować i sprawdzać obrazy bez logowania do rejestru.
- Dla `push` do `main` po przejściu wszystkich bramek zbudować, sprawdzić i wypchnąć te same cztery obrazy, używając wyłącznie `GITHUB_TOKEN` z `contents: read` i `packages: write`. GitHub oficjalnie wspiera ten model publikacji do GHCR z workflow repozytorium: [Publishing Docker images](https://docs.github.com/en/actions/tutorials/publish-packages/publish-docker-images?learn=continuous_deployment).
- Po wszystkich pushach odczytać `RepoDigests`, wygenerować ścisły manifest i przesłać go jako artefakt. Częściowo opublikowane obrazy bez manifestu nigdy nie są kandydatem.
- Nie dodawać atestacji ani skanów gotowych obrazów; zachować obecne kontrole źródłowe i strukturalny kontrakt obrazów.

Bramka: każdy obraz ma właściwego użytkownika, healthcheck i etykietę `org.opencontainers.image.revision`; manifest wskazuje dokładnie ten sam SHA.

## Phase 3: Niezależny control plane s-manager

- Dodać konfigurację reconciliatora ze stałymi: repozytorium `Polkiujhy/music-map`, branch `main`, zaufany plik workflow, artefakt, dozwolone repozytoria GHCR i środowisko GitHub `production`.
- Utworzyć oddzielną GitHub App wyłącznie dla Music Map: `metadata:read`, `contents:read`, `pull_requests:read`, `actions:read`, `deployments:write`.
- Reconciler pobiera bieżący head `main`, wymaga powiązanego PR-a z `merged_at`, właściwą bazą oraz udanego najnowszego podejścia zaufanego workflow dla tego samego SHA. GitHub udostępnia do tego endpointy workflow runs i PR-ów powiązanych z commitem: [workflow runs](https://docs.github.com/en/rest/actions/workflow-runs?apiVersion=2026-03-10), [associated pull requests](https://docs.github.com/en/rest/commits/commits).
- Artefakt pobierać tokenem instalacyjnym GitHub App, sprawdzając jego SHA-256, wygaśnięcie, rozmiar oraz bezpieczną strukturę ZIP. Metadane artefaktów zawierają digest i head SHA: [GitHub Actions artifacts](https://docs.github.com/en/rest/actions/artifacts?apiVersion=2026-03-10).
- Prywatne obrazy pobierać osobnym PAT classic ograniczonym do `read:packages`; GitHub wymaga tego mechanizmu poza Actions dla prywatnego GHCR: [Container registry authentication](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry).
- Przed mutacją ponownie odczytać `main`, uruchomić dry-run i przekazać aktualne `expected-current`. Zmiana headu przed deployem odrzuca starego kandydata.
- Zapisywać lokalny, atomowy stan reconciliacji oraz GitHub Deployment ze statusami `in_progress`, `success` albo `failure`. Niedostępność GitHub po udanym deployu trafia do outboxa statusów i nie powoduje ponownego deployu.
- Dodać oneshot `music-map-reconciler.service` i timer co 2 minuty, z `Persistent=true`, losowym opóźnieniem do 15 sekund, hardeningiem systemd i `OnFailure` do istniejących alertów. Timer pozostaje domyślnie wyłączony.
- Dodać cztery role Music Map do centralnej polityki zasobów, ochronę bieżących/poprzednich digestów przed czyszczeniem Dockera oraz test zgodności limitów z release Compose.
- Kod usługi musi być instalowany jako root-owned, niezmienny artefakt Managera; żaden writable checkout nie może być wykonywany przez systemd.

Bramka: testy z fałszywym GitHub API dowodzą odrzucenia direct push, starego SHA, błędnego workflow, uszkodzonego artefaktu, niedozwolonego obrazu i powtórzenia zakończonego kandydata.

## Phase 4: Gotowość hosta i kopii zapasowych

- Scalić i zainstalować PR Managera, następnie naprawić właściciela provisioning helpera na `root:root`, tryb `0444`, i ponownie porównać jego SHA-256 ze źródłem.
- Utworzyć dedykowane credentials systemd dla GitHub App i GHCR. Nie używać żadnych `/etc/srv-agent/*`, tokenów StorageApp ani jej stanów.
- Potwierdzić `s-manager provision music-map --status`: właściwa baza, rola, system identifier, `schema: not_initialized` oraz brak obcego pending journal.
- Uzupełnić manager-owned env z finalnej `.env.example`: aplikacja, PostgreSQL, session/cache/queue, poczta, oba dostawcy streamingowi, konta techniczne i dokładne callback URLs. Logi i receipts nie mogą zawierać wartości.
- Poprawić host-files backup: zastąpić kandydackie `/srv/music-map/storage` produkcyjnym `/var/lib/s-manager/music-map-deploy` oraz objąć stan reconciliatora i routingu. Zachować wykluczenia cache, sesji, logów i plików tymczasowych.
- Wykonać świeży backup PostgreSQL, pełny isolated restore test, backup host-files i isolated restore z niepustą próbką `storage/app`.
- Włączyć timery dopiero po pozytywnych testach restore, freshness, alertu kontrolnego i heartbeat.
- Potwierdzić pojemność hosta oraz dostępność bieżącego i poprzedniego digestu w lokalnym Dockerze lub prywatnym GHCR.

Bramka: odtworzenie potwierdza bazę, storage, manifesty wydań, konfigurację Managera i stan reconciliatora bez odczytywania sekretów do logów.

## Phase 5: Pierwszy baseline i wdrożenie szkieletu

- Faza rozpoczyna się po scaleniu PR-a z control plane i workflow publikującym. Nie czeka na ukończenie funkcji MVP.
- Wystawić obecny szkielet Laravel jako pierwszy rzeczywisty kandydat z czterema obrazami i ścisłym manifestem. Zmiana musi trafić na `main` przez PR i zielony zaufany workflow.
- Wykonać ostatni backup bazy i restore test, po czym uruchomić `initialize-schema` dla dokładnego manifestu kandydata.
- Inicjalizator musi:
  - wymagać pustej, zweryfikowanej bazy i braku aktywnego wydania;
  - przygotować wszystkie obrazy jak zwykły deploy;
  - uruchomić `php artisan migrate --force --no-interaction` tylko z dokładnego obrazu FPM;
  - niezależnie od Artisan odczytać ledger migracji przez ograniczony helper PostgreSQL;
  - zapisać baseline tylko przy zgodnym fingerprint, zbiorze migracji i tożsamości bazy.
- Po baseline wykonać `s-manager deploy ... --expected-current none` i potwierdzić cztery zdrowe role, wewnętrzne `/up`, worker oraz scheduler. Pierwszy release staje się rollback targetem dla kolejnych zmian.
- Przełączyć routing na `validation`, ograniczony do CIDR operatora. Pozostali odbiorcy nadal otrzymują puste `503` z `Retry-After`.
- Dla szkieletu sprawdzić wyłącznie publiczną odpowiedź aplikacji, `/up`, trwałość sesji, kolejkę, scheduler, logi i zachowanie po restarcie. Akceptacja integracji i playlist pozostaje bramką końcową MVP w fazie 7.

Obsługa awarii:

- Nieudana inicjalizacja pozostawia blokadę; nie jest automatycznie ponawiana. Operator przywraca bazę z backupu, potwierdza `not_initialized`, używa `recover-schema-initialization`, a następnie rozpoczyna nowe jawne podejście.
- Pierwsze wydanie nie ma poprzednika do rollbacku. Przy błędzie pozostaje `maintenance/validation`, a wdrażany jest poprawiony kandydat z identycznym fingerprintem migracji.
- Zmiana migracji po zapisaniu baseline jest odrzucana przez zwykły deploy i reconciler. Przed pierwszym takim PR-em musi być gotowa operacja schema-release z fazy 6.

## Phase 6: Wczesny cutover i wdrożenia pośrednie

- Tryb `live` proxy’uje do `music-map-nginx:8080` z prawidłowymi `Host`, `X-Forwarded-*`, limitami i timeoutami.
- Błędy upstreamu `502/503/504` są mapowane na kontrolowane, puste `503` z `Retry-After`; brak kontenerów nie może ujawnić domyślnego błędu nginx.
- Przed przełączeniem zweryfikować DNS, SAN certyfikatu, odnowienie TLS oraz bezpieczną odpowiedź szkieletu pod `https://music.adamis.me`.
- Po przełączeniu wykonać zewnętrzne testy HTTPS, sesji/cookies i fallbacku `503`; niedostępne jeszcze funkcje nie mogą udawać gotowych.
- Dopiero wtedy włączyć timer reconciliatora. Jego warunkiem startowym jest istniejące, zdrowe wydanie, dzięki czemu pierwszy automatyczny deploy ma rollback target.
- Scalić bezpieczny PR canary, np. zmianę dokumentacji aplikacji. Oczekiwany wynik: publikacja nowego manifestu, automatyczny deploy w ciągu 10 minut i zachowanie pierwszego wydania jako `previous`.
- Przy nieudanym canary s-manager przywraca i weryfikuje poprzednie wydanie; timer zostaje wyłączony do czasu diagnozy.
- Przed pierwszą pośrednią zmianą schematu dodać jawną operację `s-manager schema-release music-map --release-file FILE --expected-current ID`. Operacja wymaga świeżego backupu PostgreSQL i pozytywnego isolated restore, uruchamia wyłącznie oczekujące migracje z dokładnego obrazu FPM, niezależnie weryfikuje ledger i aktualizuje fingerprint baseline dopiero po sukcesie.
- Reconciler nigdy sam nie uruchamia migracji. Kandydat ze zmienionym fingerprintem pozostaje zablokowany, dopóki operator nie zaakceptuje kompatybilności expand/contract i nie wykona schema-release; potem ten sam dokładny kandydat może zostać wdrożony przez s-manager bez ponownego wykonania migracji.
- Rollback aplikacji po schema-release jest dozwolony tylko po potwierdzeniu kompatybilności poprzedniego obrazu z rozszerzonym schematem. Operacja nie wykonuje automatycznych migracji `down`.

Bramka: szkielet jest dostępny publicznie; migracyjny canary przechodzi przez schema-release, a kolejne prawidłowe PR-y bez zmiany schematu wdrażają się bez ręcznej promocji. GitHub Deployment i lokalny receipt wskazują ten sam SHA, manifest i wynik.

## Phase 7: Przyrostowe MVP, odporność i przekazanie operacyjne

- Dostarczać funkcje MVP małymi PR-ami. Każdy zielony release bez zmiany migracji wdraża reconciler; każdy release zmieniający migracje przechodzi wcześniej ręczną bramkę schema-release.
- Na dedykowanych kontach i playlistach testowych sprawdzić finalne MVP: logowanie, pocztę, połączenie/odłączenie obu platform, callback OAuth, import publiczny i odmowę prywatnego, dopasowanie do 50 utworów, eksport użytkownika i konta technicznego, ponowienie bez duplikatu, synchronizację kolejki i schedulera, wygaśnięty token, rate limit oraz redakcję logów.
- Po teście usunąć utworzone artefakty zewnętrzne i potwierdzić działanie unieważnienia tokenów.
- Dodać runbook obejmujący status, logi, zatrzymany journal, terminalnie odrzuconego kandydata, wygasły artefakt/PAT, brak GHCR, awarię GitHub, zmianę migracji, rollback oraz powrót trasy do maintenance.
- Kandydat po błędzie deployu nie jest ponawiany automatycznie. Nowy merge albo jawny rerun workflow tworzy nowy `run_attempt` i nową tożsamość wydania.
- Błędy sieciowe przed mutacją mogą być sprawdzane ponownie w następnym cyklu; po rozpoczęciu deployu wynik jest terminalny dla danego kandydata.
- Pending journal lub nieudane recovery blokuje wszystkie kolejne wdrożenia. Operator korzysta z bieżącego `s-manager status` i jawnego `s-manager rollback --expected-current ...`; journalu nie wolno usuwać ręcznie.
- Zweryfikować alerty dla: braku świeżego backupu, nieudanego deployu/recovery, zablokowanej migracji, wygasających credentials, permanentnej niespójności GitHub audit oraz braku zdrowego upstreamu.
- Udokumentować awaryjne odtworzenie hosta: Manager, sekrety, release state i storage z Restic, baza z logicznego backupu, obrazy po digestach z GHCR, następnie route `maintenance → validation → live`.

Bramka: kompletne MVP działa na publicznym środowisku, a kontrolowane testy awarii dowodzą zachowania poprzedniego wydania lub `503`, braku wycieku sekretów i braku ślepych retry.

## Testy akceptacyjne

- `music-map`: `composer test`, `vendor/bin/pint --test`, `npm run build`, source contract oraz PR/main workflow contract.
- Manager: pełne testy CLI/release/reconciler/schema/route/backup/resource-policy, kontrola generowanej dokumentacji CLI i `systemd-analyze verify`.
- Docker: syntetyczne A → B, rollback B → A, niezdrowe B z automatycznym recovery, brak poprzednika i zmieniony fingerprint.
- GitHub: merged PR, direct push, anulowany/niedokończony workflow, nieudany rerun, wygasły artefakt, zmieniony head podczas pobierania i ponowne przetworzenie tego samego runu.
- Public edge: maintenance, validation z dozwolonym i niedozwolonym CIDR, live, niedostępny upstream, forwarded headers, TLS i OAuth callback.
- Produkcja: kompletna checklista obu integracji oraz potwierdzenie backup/restore przed aktywacją autodeploy.

## Założenia

- Implementacja funkcji obu platform streamingowych przebiega po uruchomieniu szkieletu; ten plan obejmuje ich przyrostowe wdrażanie oraz końcową akceptację produkcyjną.
- Po aktywacji reconciliatora każdy kwalifikujący się merge do `main` bez zmiany fingerprintu migracji jest wdrażany automatycznie; zmiana schematu zatrzymuje się na ręcznej bramce schema-release.
- Review jest procesem GitHub zakończonym decyzją o merge; reconciler nie analizuje approvals.
- Migracje po baseline są jawne i nadzorowane przez schema-release; nigdy nie są automatycznie wykonywane przez reconciler.
- GHCR pozostaje prywatny, a obrazy bieżącego i poprzedniego wydania nie są usuwane przez politykę retencji.
- Operacyjne sekrety, callback credentials, CIDR walidacyjny i enablement timerów nie trafiają do Git.
- Manager może korzystać ze wspólnych alertów, PostgreSQL, public-edge i s-manager, ale nie z żadnej wewnętrznej ścieżki zatwierdzania StorageApp.

## References

- [Decyzja infrastrukturalna Music Map](/srv/music-map/context/foundation/infrastructure.md)
- [Kontrakt wdrażania obrazów](/srv/manager/docs/music-map-image-deploy.md)
- [Kontrakt provisioningu](/srv/manager/docs/music-map-provision.md)
- [GitHub deployment statuses](https://docs.github.com/en/rest/deployments/statuses)
- [GitHub Actions artifacts](https://docs.github.com/en/rest/actions/artifacts?apiVersion=2026-03-10)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Kontrakt i governance zmian

#### Automated

- [x] 1.1 Zaktualizować kontrakt infrastruktury i naprawić source contract — 3ed35b0

#### Manual

- [x] 1.2 Włączyć i zweryfikować reguły PR-only dla main — 3ed35b0

### Phase 2: CI i publikacja kandydatów

#### Automated

- [x] 2.1 Zbudować wspólną bramkę CI dla PR i main — 28e62ec
- [x] 2.2 Publikować prywatne obrazy i ścisły manifest wydania — 28e62ec
- [x] 2.3 Przetestować integralność i odmowy kontraktu artefaktu — 28e62ec

### Phase 3: Niezależny control plane s-manager

#### Automated

- [x] 3.1 Dodać bezpieczną inicjalizację i recovery schematu — 2dcbb20
- [x] 3.2 Dodać reconciler GitHub i lokalny audit — 2dcbb20
- [x] 3.3 Dodać kontrolę trasy, zasobów i retencji obrazów — 2dcbb20
- [x] 3.4 Zweryfikować CLI, systemd i macierz awarii — 2dcbb20

### Phase 4: Gotowość hosta i kopii zapasowych

#### Automated

- [x] 4.1 Poprawić produkcyjny zakres backupu Music Map — 2dcbb20

#### Manual

- [x] 4.2 Zainstalować zatwierdzone wydanie Managera i naprawić helper — 2dcbb20
- [x] 4.3 Zainstalować dedykowane credentials i zweryfikować provisioning — 2dcbb20
- [x] 4.4 Wykonać backupy, isolated restore i test alertów — 2dcbb20

### Phase 5: Pierwszy baseline i wdrożenie szkieletu

#### Automated

- [ ] 5.1 Opublikować pierwszego kandydata ze szkieletem

#### Manual

- [ ] 5.2 Uzupełnić minimalną konfigurację runtime szkieletu
- [ ] 5.3 Utworzyć zweryfikowany baseline schematu
- [ ] 5.4 Wdrożyć dokładne obrazy i zaliczyć walidację szkieletu

### Phase 6: Wczesny cutover i wdrożenia pośrednie

#### Automated

- [ ] 6.1 Zapewnić proxy z bezpiecznym fallbackiem 503
- [ ] 6.2 Dodać kontrolowany schema-release dla migracji po baseline

#### Manual

- [ ] 6.3 Przełączyć validation na live dla szkieletu
- [ ] 6.4 Włączyć reconciler i zaliczyć automatyczny PR canary
- [ ] 6.5 Zaliczyć canary zmieniający schemat przez schema-release

### Phase 7: Przyrostowe MVP, odporność i przekazanie operacyjne

#### Automated

- [ ] 7.1 Dostarczyć kompletne MVP przez przyrostowe wydania
- [ ] 7.2 Ukończyć testy awarii i runbook operacyjny

#### Manual

- [ ] 7.3 Zaliczyć końcową walidację integracji i playlist
- [ ] 7.4 Przeprowadzić rollback, recovery i testy alertów
- [ ] 7.5 Zatwierdzić gotowość MVP i disaster recovery
