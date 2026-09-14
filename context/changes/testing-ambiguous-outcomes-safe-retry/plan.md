# Plan implementacji: niejednoznaczne wyniki i bezpieczne ponowienia

## Przegląd

Faza 1 wdrożenia planu testów ma dostarczyć najtańsze wiarygodne dowody dla ryzyk #1, #2, #5 i #7 bez tworzenia testów nieistniejącego wykonania eksportu. Dodajemy jawny kontrakt pustej odpowiedzi YouTube, integracyjny test utrzymania poprzedniego snapshotu po awarii transportu oraz uruchamiamy istniejący pakiet PostgreSQL dla dopuszczania zapisów. Kryteria przyszłego eksportu pozostają w tym planie i w końcowych wzorcach §6; roadmapa S-06/S-07 nie jest zmieniana.

## Analiza bieżącego stanu

Produkt kończy dziś przepływ eksportu na zamrożonym manifeście gotowym do przekazania; nie ma writera, trwałej playlisty docelowej ani stanu częściowego eksportu. Ryzyka #1 i #2 są zatem kryteriami akceptacji przyszłych S-06/S-07, a nie zachowaniem możliwym obecnie do przetestowania (`context/changes/testing-ambiguous-outcomes-safe-retry/research.md`, „Risk #1” i „Risk #2”).

Import YouTube odczytuje i waliduje metadane oraz elementy przed wejściem do transakcji zapisu (`app/Integrations/PlaylistImport/Providers/YouTubePlaylistReader.php:39`, `app/Actions/Playlists/ImportPlaylist.php:94`). Atomowa zamiana parenta, świeżości i elementów następuje później w jednej transakcji (`app/Actions/Playlists/ReplaceImportedPlaylist.php:20`). Istniejący test reimportu chroni stan po błędnym payloadzie, ale nie symuluje utraty transportu po poprawnych metadanych (`tests/Feature/Playlists/PlaylistImportTest.php:170`).

Prymityw dopuszczania zapisów YouTube ma już testy SQLite oraz pełny zestaw celowanych przypadków PostgreSQL: wyścigi różnych i tych samych kluczy, przekroczenie północy, timeout blokady, ponowienia SQLSTATE oraz utratę potwierdzenia commitu (`tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php:58`). Żaden produkcyjny writer nie konsumuje jeszcze portu dopuszczania, więc brak obejścia i stabilna tożsamość konsumenta pozostają kryteriami dla pierwszego writera.

## Pożądany stan końcowy

- Kontrakt readera jednoznacznie odróżnia wadliwy HTTP 200 od poprawnej playlisty o zerowej liczbie elementów.
- Reimport, w którym metadane są poprawne, a pobranie elementów kończy się awarią transportu, zachowuje dokładnie poprzednią playlistę, uporządkowane elementy i `provider_metadata_refreshed_at`.
- Istniejący pakiet PostgreSQL przechodzi na jednorazowej bazie i pozostaje jedynym testem wyścigów prymitywu dopuszczania.
- §6 planu testów opisuje dostarczone wzorce, w tym niezależność stanu providera od odpowiedzi oraz kryteria przyszłych S-06/S-07.

### Kluczowe odkrycia

- Snapshot powstaje dopiero po kompletnym, spójnym odczycie obu odpowiedzi (`app/Integrations/PlaylistImport/Providers/YouTubePlaylistReader.php:68`).
- Jawne `items: []` metadanych oznacza brak playlisty, natomiast prawidłowo pusta playlista wymaga zgodnych zer w metadanych, stronie elementów i samej liście (`app/Integrations/PlaylistImport/Providers/YouTubePlaylistReader.php:76`, `app/Integrations/PlaylistImport/Providers/YouTubePlaylistReader.php:101`).
- SQLite nie dowodzi `FOR UPDATE`, blokad między procesami ani zachowania SQLSTATE; repozytorium ma osobną ścieżkę PostgreSQL (`README.md:200`, `.github/workflows/ci.yml:84`).
- Brak dokładnego Change ID `testing-ambiguous-outcomes-safe-retry` w roadmapie, więc jej status pozostaje bez zmian.

## Czego NIE robimy

- Nie implementujemy ani nie testujemy wykonania eksportu S-06/S-07, którego jeszcze nie ma.
- Nie traktujemy probe'ów `PlatformAccess`, potwierdzenia review ani ponownego użycia rezerwacji jako dowodu bezpieczeństwa eksportu.
- Nie dodajemy stateful fake'a do probe'ów ani testu live-provider w tej fazie.
- Nie duplikujemy istniejących wyścigów prymitywu dopuszczania i nie uznajemy SQLite za dowód atomowości produkcyjnej.
- Nie dodajemy jeszcze testu konsumenta dopuszczania; powstanie dopiero wraz z pierwszym writerem.
- Nie mockujemy `PlaylistSourceReader`, `ImportPlaylist` ani `ReplaceImportedPlaylist` i nie promujemy deterministycznej ścieżki HTTP do E2E.
- Nie zmieniamy roadmapy ani sekcji §1–§5 planu testów poza wymaganym statusem Fazy 1.

## Podejście do implementacji

Kolejność wynika z kosztu × sygnału. Najpierw doprecyzowujemy tani kontrakt readera, następnie przechodzimy przez rzeczywistą granicę HTTP i bazę dla brakującego przypadku #7. Potem uruchamiamy droższy, już istniejący pakiet PostgreSQL dla #5 bez rozbudowywania go. Na końcu zapisujemy wyłącznie wzorce faktycznie dostarczone oraz przyszłe granice #1/#2 w §6.

## Krytyczne szczegóły implementacji

### Sekwencjonowanie stanu

Test integracyjny musi najpierw zapisać prawidłowy snapshot z co najmniej dwiema uporządkowanymi pozycjami, a dopiero potem zwrócić poprawne nowe metadane i zerwać transport przy pobieraniu elementów. Stan „przed” jest niezależną wyrocznią: obejmuje parenta, `provider_metadata_refreshed_at` i uporządkowane wystąpienia, nie tylko liczbę wierszy lub komunikat błędu.

### Debugowanie i obserwowalność

Lokalny test PostgreSQL wymaga `pdo_pgsql` i jawnie wskazanej jednorazowej bazy. Jeżeli środowisko lokalne nie spełnia tych warunków, wynik CI jest wymaganym dowodem; nie wolno raportować lokalnego sukcesu na podstawie samej konfiguracji joba.

## Faza 1: Kontrakt wadliwej i prawidłowo pustej odpowiedzi

### Przegląd

Uczynić granicę kontraktu YouTube czytelną bez powielania całej ścieżki persystencji.

### Wymagane zmiany

#### 1. Kontrakt readera YouTube

**Plik**: `tests/Unit/Integrations/PlaylistImport/YouTubePlaylistReaderTest.php`

**Cel**: Dodać lub przeorganizować najtańszy kontrakt, który jawnie przeciwstawia wadliwy HTTP 200 prawidłowej odpowiedzi pustej playlisty.

**Kontrakt**: Wadliwa odpowiedź 200 bez wymaganej listy lub z nieprawidłowym jej kształtem zwraca `ImportFailureCode::InvalidResponse`. Kompletne metadane z `itemCount = 0` oraz strona elementów z `totalResults = 0` i `items = []` zwracają `PlaylistSnapshot` bez elementów.

- **Aserowane zachowanie**: te same kody HTTP nie zacierają semantycznej różnicy między błędem kontraktu a prawdziwą pustą playlistą.
- **Wykrywana regresja**: fallback zamieniający brakujące/wadliwe dane na pustą kolekcję albo odrzucający prawidłową playlistę zeroelementową.
- **Źródło badawcze**: `research.md`, „Risk #7 — Real failure path” i „Existing tests and cheapest layer”; reader `YouTubePlaylistReader.php:76-127`.
- **Przypadek brzegowy**: HTTP 200 z wadliwym kształtem kontra trzy zgodne sygnały zera.
- **Unikany antywzorzec**: nie używać statusu 200 ani samego `items = []` jako wyroczni; nie kopiować warunków produkcyjnych do obliczania oczekiwanego wyniku.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Celowany kontrakt readera przechodzi: `php artisan test tests/Unit/Integrations/PlaylistImport/YouTubePlaylistReaderTest.php`.
- Test ma dwie niezależnie nazwane intencje: wadliwy HTTP 200 jest błędem, a spójna pusta playlista jest snapshotem.

---

## Faza 2: Atomowość reimportu po awarii transportu elementów

### Przegląd

Dodać brakujący dowód integracyjny na prawdziwej granicy HTTP aplikacji dla ryzyka #7.

### Wymagane zmiany

#### 1. Test nieudanego reimportu YouTube

**Plik**: `tests/Feature/Playlists/PlaylistImportTest.php`

**Cel**: Rozszerzyć zestaw reimportu o awarię transportu występującą dopiero po prawidłowym pobraniu metadanych, gdy poprzedni snapshot już istnieje.

**Kontrakt**: Pierwszy import zapisuje playlistę z co najmniej dwiema pozycjami w znanej kolejności. Drugi odczyt zwraca poprawne, zmienione metadane, po czym `Http::fakeSequence()->pushFailedConnection()` zrywa pobieranie `/playlistItems`. Odpowiedź aplikacji sygnalizuje błąd providera, reimport wykonuje dokładnie dwie próby HTTP, a pełny zapis playlisty z elementami i świeżością jest identyczny ze stanem sprzed reimportu.

- **Aserowane zachowanie**: częściowy odczyt zewnętrzny nie publikuje częściowego ani fałszywie świeżego snapshotu.
- **Wykrywana regresja**: zapis metadanych przed zakończeniem odczytu, potraktowanie transport failure jako pustej listy, częściowa wymiana dzieci lub przesunięcie świeżości mimo porażki.
- **Źródło badawcze**: `research.md`, „Risk #7”; wcześniejszy wzorzec `context/archive/2026-09-13-playlist-link-import/plan.md` dotyczący pełnego odczytu i atomowej zamiany.
- **Przypadek błędu**: poprawne metadane, następnie wyjątek transportowy przy pobieraniu elementów.
- **Unikany antywzorzec**: nie mockować wewnętrznych collaboratorów; nie asertować wyłącznie flash message, liczby requestów ani liczby playlist.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Celowany test integracyjny przechodzi: `php artisan test tests/Feature/Playlists/PlaylistImportTest.php`.
- Test potwierdza niezmienność pól parenta, `provider_metadata_refreshed_at` oraz uporządkowanych `occurrence_id` i identyfikatorów katalogowych.
- Reimport dochodzi do rzeczywistej granicy HTTP Laravel i nie zastępuje collaboratorów aplikacji mockami.

---

## Faza 3: Zachowanie istniejącej bramy PostgreSQL

### Przegląd

Zweryfikować ryzyko #5 na właściwej bazie produkcyjnej, zachowując istniejące testy prymitywu bez tworzenia kolejnego odpowiednika wyścigu.

### Wymagane zmiany

#### 1. Istniejące kontrakty i testy dopuszczania

**Pliki**:

- `tests/Unit/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionContractTest.php`
- `tests/Feature/Integrations/YouTubeWriteAdmission/ReserveYouTubeWriteTest.php`
- `tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php`

**Cel**: Zachować testy bez funkcjonalnego duplikowania i uruchomić je warstwowo: kontrakt, deterministyczna funkcjonalność, a następnie semantyka PostgreSQL.

**Kontrakt**: Jedna logiczna tożsamość ma jedną rezerwację przez retry i niejednoznaczny commit; limit i awaria infrastruktury odmawiają bez częściowego stanu; reset używa czasu Pacific; wyścigi nie przekraczają globalnego limitu. Przyszły konsument musi wcześniej utrwalić i ponownie użyć stabilnego ID, zakończyć się fail-closed przed access handoff lub mutacją oraz dodać tylko jeden wyścig konsumencki, gdy pierwszy writer faktycznie powstanie.

- **Aserowane zachowanie**: istniejący ledger pozostaje atomowy w rzeczywistych warunkach blokad i procesów PostgreSQL.
- **Wykrywana regresja**: podwójna rezerwacja, nadmiarowy slot, błędny dzień po oczekiwaniu na lock, częściowy commit lub retry niewłaściwego SQLSTATE.
- **Źródło badawcze**: `research.md`, „Risk #5”; przypadki w `YouTubeWriteAdmissionPostgresTest.php:58-360`.
- **Przypadki błędów i granic**: ostatni slot, ten sam klucz w wielu procesach, północ Pacific, `55P03`, `40001`, `40P01` i utracone potwierdzenie commitu.
- **Unikany antywzorzec**: nie uznawać SQLite ani bindingu DI za dowód produkcyjnej atomowości; nie dodawać drugiego wyścigu prymitywu zamiast przyszłego testu konsumenta.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Kontrakty przechodzą: `php artisan test tests/Unit/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionContractTest.php`.
- Deterministyczny zestaw funkcjonalny przechodzi: `php artisan test tests/Feature/Integrations/YouTubeWriteAdmission/ReserveYouTubeWriteTest.php`.
- Na jawnie zweryfikowanej jednorazowej bazie PostgreSQL migracje i celowany zestaw przechodzą: `php artisan migrate:fresh --force --no-interaction` oraz `php artisan test tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php`.
- Gdy lokalnie brakuje `pdo_pgsql` lub jednorazowej bazy, odpowiadający job CI musi być zielony; sama obecność konfiguracji nie jest wynikiem.
- W tej fazie nie powstaje nowy test wyścigu prymitywu.

---

## Faza 4: Podręcznik dostarczonych wzorców

### Przegląd

Zastąpić placeholdery §6 wyłącznie wzorcami potwierdzonymi przez tę fazę i zachować uczciwą granicę dla przyszłych writerów.

### Wymagane zmiany

#### 1. Cookbook planu testów

**Plik**: `context/foundation/test-plan.md`

**Cel**: Uzupełnić §6.1, §6.2, §6.4 i §6.6 po przejściu wcześniejszych faz. Nie zmieniać strategii, mapy ryzyk, stacku ani quality gates w §1–§5, z wyjątkiem mechanicznego statusu rollout Fazy 1 obsługiwanego przez workflow.

**Kontrakt**:

- §6.1 opisuje niezależny stan providera i strumień odpowiedzi, stabilną tożsamość przed mutacją, bezpieczną rekonsyliację albo jawny manual-recovery oraz exact ordered read-back jako jedyną wyrocznię finalnego sukcesu. Do czasu writerów S-06/S-07 są to kryteria akceptacji, nie dowód z probe'a.
- §6.2 rozdziela zastosowanie SQLite i jednorazowego PostgreSQL, wskazuje istniejący pakiet admission zamiast nowego wyścigu oraz wymaga jednego wyścigu konsumenta dopiero przy pierwszym writerze. Zachowuje też wzorzec „complete read before atomic replacement” dla importu.
- §6.4 nakazuje fake'ować wyłącznie zewnętrzną granicę HTTP, odróżniać wadliwy HTTP 200 od spójnego zera i używać stanu zapisanego jako wyroczni. Wyjątek live-provider pozostaje do Fazy 3 rollout.
- §6.6 nazywa dostarczony test Feature, jawny kontrakt readera, zachowany pakiet PostgreSQL oraz odroczenie wykonywalnych testów #1/#2 do writerów S-06/S-07.

- **Aserowane zachowanie**: przyszłe testy używają właściwej warstwy i niezależnej wyroczni dla awarii częściowych.
- **Wykrywana regresja**: powrót do happy-path-only, request-count oracle, mockowania wnętrza lub fałszywego testu eksportu.
- **Źródło badawcze**: `research.md`, „Architecture Insights” i ustalenia dla ryzyk #1, #2, #5 i #7.
- **Przypadek graniczny**: zastosowana mutacja bez zwróconego ID nie może automatycznie przejść do ponownego create; niepełny read-back nie jest sukcesem.
- **Unikany antywzorzec**: cookbook nie może twierdzić, że testy nieistniejącego writera zostały dostarczone ani kopiować szczegółów Managera.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Placeholdery §6.1 i §6.2 są zastąpione, §6.4 jest częściowo uzupełnione bez usuwania przyszłego live-smoke, a §6.6 zawiera notatkę Fazy 1.
- W §6 znajdują się kryteria #1/#2: provider-supported idempotency, deterministyczne discovery albo jawny manual-recovery; ten sam cel dla wznowienia; finalny sukces dopiero po dokładnym obserwowanym ordered read-back.
- Sekcje §1–§5 pozostają merytorycznie niezmienione poza statusem rollout.
- Pełne bramy repozytorium przechodzą: `composer test`, `vendor/bin/pint --test` i `npm run build`.

## Strategia testowania

### Testy jednostkowe i kontraktowe

- Reader YouTube: wadliwy HTTP 200 zwraca `InvalidResponse`; spójne zero zwraca pusty `PlaylistSnapshot`.
- Istniejące kontrakty admission pozostają szybkim dowodem zamkniętego zestawu typów, statusów i wyników.

### Testy integracyjne

- Feature importu przechodzi przez trasę, realny reader, mapowanie błędu i bazę; fake zatrzymuje się na zewnętrznym HTTP.
- PostgreSQL pozostaje obowiązkową warstwą dla blokad, wyścigów procesowych, SQLSTATE i niejednoznacznego commitu.

### Kroki testowania ręcznego

Brak. Wszystkie zachowania tej fazy mają deterministyczne automatyczne wyrocznie; ręczny test UI lub live-provider nie zwiększa sygnału w tym zakresie.

## Zagadnienia wydajnościowe

Nowe testy kontraktowe i SQLite są wąskie. Celowany pakiet PostgreSQL jest droższy, ale konieczny tylko dla semantyki, której SQLite nie modeluje; nie dokładamy równoległego, redundantnego wyścigu.

## Uwagi dotyczące migracji

Brak zmian schematu. `migrate:fresh` może być uruchamiane wyłącznie na jawnie zweryfikowanej jednorazowej bazie testowej; nigdy na bazie aplikacyjnej ani produkcyjnej.

## Odnośniki

- Plan testów: `context/foundation/test-plan.md`
- Badania: `context/changes/testing-ambiguous-outcomes-safe-retry/research.md`
- Poprzedni wzorzec importu: `context/archive/2026-09-13-playlist-link-import/plan.md`
- Poprzedni wzorzec admission: `context/archive/2026-09-14-youtube-write-admission/plan.md`
- Granica zakresu review: `context/archive/2026-09-14-export-match-review/plan.md`
- Lokalne PostgreSQL: `README.md:200`
- PostgreSQL w CI: `.github/workflows/ci.yml:84`

## Postęp

> Konwencja: `- [ ]` oczekujące, `- [x]` wykonane. Dodaj ` — <commit sha>` po wylądowaniu kroku. Nie zmieniaj nazw tytułów kroków.

### Faza 1: Kontrakt wadliwej i prawidłowo pustej odpowiedzi

#### Automatyczne

- [x] 1.1 Celowany kontrakt readera przechodzi — 20f2c0f
- [x] 1.2 Intencje wadliwego HTTP 200 i spójnego zera są niezależnie nazwane — 20f2c0f

### Faza 2: Atomowość reimportu po awarii transportu elementów

#### Automatyczne

- [x] 2.1 Celowany test integracyjny importu przechodzi — 8e7bd20
- [x] 2.2 Pełny poprzedni snapshot i świeżość pozostają niezmienione — 8e7bd20
- [x] 2.3 Test używa zewnętrznej granicy HTTP bez mocków collaboratorów — 8e7bd20

### Faza 3: Zachowanie istniejącej bramy PostgreSQL

#### Automatyczne

- [x] 3.1 Kontrakty admission przechodzą — 1af3d08
- [x] 3.2 Deterministyczny zestaw admission przechodzi — 1af3d08
- [x] 3.3 Celowany zestaw PostgreSQL lub odpowiadający job CI przechodzi — 1af3d08
- [x] 3.4 Nie dodano zduplikowanego wyścigu prymitywu — 1af3d08

### Faza 4: Podręcznik dostarczonych wzorców

#### Automatyczne

- [x] 4.1 Sekcje cookbook §6.1, §6.2, §6.4 i §6.6 opisują dostarczone wzorce
- [x] 4.2 Kryteria przyszłych S-06/S-07 zachowują niejednoznaczny create, ten sam cel i exact ordered read-back
- [x] 4.3 Strategia §1–§5 pozostaje zamrożona poza statusem rollout
- [x] 4.4 Pełne bramy repozytorium przechodzą
