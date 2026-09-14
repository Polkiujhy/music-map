# Eksport na powiązane i zarządzane konto — krótki plan

> Pełny plan: `context/changes/linked-account-export/plan.md`

## Co i dlaczego

Budujemy wspólny silnik S-07 i S-06, który po świadomym potwierdzeniu tworzy lub aktualizuje playlistę na powiązanym koncie użytkownika albo koncie technicznym `music-map`. Wynik wskazuje status, właściciela i stały link, a retry nie tworzy świadomie kolejnej kopii.

## Punkt wyjścia

S-04 zapewnia bezpieczny dostęp OAuth, S-05 zamrożony manifest, a F-02 globalne dopuszczenie zapisów YouTube. Przepływ kończy się dziś na `confirmed`: brakuje operacji wykonawczej, klientów zapisu, trwałego celu i UI wyniku.

## Pożądany stan końcowy

Potwierdzenie uruchamia trwałą operację. Pierwszy eksport zapisuje osobną docelową `Playlist` i relację ze źródłem; kolejne aktualizują ten sam providerowy zasób wyłącznie po ID. Częściowe awarie można ponawiać, a bank pokazuje kopie pod źródłem.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego |
| --- | --- | --- |
| Zakres | Wspólny silnik S-06 + S-07 | Unikamy dwóch lifecycle eksportu. |
| Model celu | Osobna `Playlist` + relacja | Zgodność z BR-003 i gotowość dla S-09. |
| Update | Wyłącznie zapisane providerowe ID | Nazwa ani marker nie wskazują normalnego celu. |
| Recovery create | Marker w opisie + pełny scan | Odzyskuje ID po utracie odpowiedzi. |
| Brak jednego markera | Fail closed, bez `create` | API nie daje twardego klucza idempotencji. |
| Usunięty cel | Nowy review | Nie odtwarzamy go bez zgody. |
| Współbieżność | Jedna aktywna operacja na relację | Manifesty nie ścigają się o cel. |
| Zmiany celu | Exact replacement | Wynik odpowiada zatwierdzonemu manifestowi. |
| Duplikaty YouTube | Fail closed bez wsparcia | Brak cichej deduplikacji. |
| Relink | Tylko ta sama tożsamość | Cel nie przełącza się na inne konto. |
| Retry | 3 próby z backoff, potem ręcznie | Brak retry bez końca. |
| Powiadomienie | Po czasie ≥60 s | Realizuje NFR-002 bez zbędnych wiadomości. |

## Zakres

**W zakresie:** linked/managed lifecycle, operacja i relacja, adaptery obu providerów, recovery, exact replacement, admission, status/link/właściciel, retry, bank UI, powiadomienia, PostgreSQL concurrency i cztery live smoke.

**Poza zakresem:** S-08–S-10, merge zmian celu, automatyczne odtwarzanie usuniętego celu, cicha deduplikacja i playlisty powyżej 20 pozycji.

## Architektura / Podejście

`ExportReview` pozostaje frozen inputem. `ExportOperation` przechowuje stabilny klucz, snapshot, checkpointy i status, a `PlaylistExportLink` łączy źródło z docelową `Playlist`. Worker uzyskuje efemeryczny dostęp linked/managed, wykonuje admission YouTube przed mutacją i utrwala pozbawiony sekretów wynik.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Model i start | Operacja, relacja, after-commit dispatch | Wyścig potwierdzeń. |
| 2. Adaptery | Create, scan i exact replace | Niejednoznaczny create. |
| 3. Orkiestracja | Dostęp, admission, checkpointy, retry | Częściowa mutacja. |
| 4. UX | Statusy, link, bank, powiadomienie | Niespójny retry. |
| 5. Akceptacja | PostgreSQL i cztery live smoke | Różnice realnych API. |

**Wymagania wstępne:** ukończone F-02, S-04 i S-05; konta techniczne i testowe obu providerów; disposable PostgreSQL. Phase 3 wymaga dodatkowo opublikowanej, wersjonowanej capability PaaS dla technicznych zapisów i rotacji replacement tokenu w zwykłym queue workerze.

**Szacowany wysiłek:** około 5–7 sesji w pięciu fazach plus nadzorowane testy rzeczywistych integracji.

## Otwarte ryzyka i założenia

- Marker działa fail-closed, lecz nie jest providerową gwarancją exactly-once.
- Spotify `public: false` oznacza brak profilu/search, nie pełną kontrolę dostępu.
- Minimalny diff ogranicza koszt YouTube, ale quota może przerwać operację.
- Duplikaty YouTube wymagają smoke; brak wsparcia daje odmowę przed mutacją.
- Managed access użyje nadchodzącego publicznego kontraktu dopiero po wpisaniu
  jego dokładnej nazwy i wersji do pełnego planu; typy probe nie są reużywane,
  a Manager pozostaje zewnętrznym PaaS.

## Kryteria sukcesu (podsumowanie)

- Cztery ścieżki provider × ownership tworzą cel, a świeży review aktualizuje to
  samo ID z właściwą widocznością i linkiem.
- Retry i admission nie tworzą drugiej operacji ani świadomego duplikatu;
  odmowa przed zapisem wykonuje zero mutacji.
- Użytkownik widzi status i właściciela oraz wraca do wyniku z banku lub maila,
  a obce konto nie uzyskuje dostępu.
