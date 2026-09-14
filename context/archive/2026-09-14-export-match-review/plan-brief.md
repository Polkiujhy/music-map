# Kontrola dopasowania przed eksportem — krótki plan

> Pełny plan: `context/changes/export-match-review/plan.md`

## Co i dlaczego

S-05 daje użytkownikowi kontrolę nad zawartością przed zapisaniem jej na innej platformie. System pokazuje pewne, podejrzane i niedostępne dopasowania, wymaga jawnej decyzji przy ryzykownych wynikach i zamraża dokładnie zatwierdzony manifest dla późniejszego eksportu.

## Punkt wyjścia

Konta i integracje są gotowe. Finalna gałąź S-02 dodaje prywatne playlisty i do 20 uporządkowanych pozycji, ale reimport odtwarza ich wiersze; S-05 bazuje więc na snapshotach i hashu zawartości, nie na trwałości ID pozycji.

## Pożądany stan końcowy

Użytkownik wybiera provider i właściciela celu, może bezpiecznie opuścić ekran, a następnie zatwierdza dokładny zestaw utworów. Zmiana playlisty unieważnia wynik, a S-06/S-07 konsumują manifest bez ponownego matchingu.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego |
| --- | --- | --- |
| Baseline | Finalna gałąź S-02; implementacja po merge | Używa konkretnych modeli bez zgadywania |
| Cel | Provider oraz linked albo managed przed matchingiem | Dostępność i manifest odpowiadają rzeczywistemu właścicielowi |
| Klasyfikacja | Konserwatywne dowody | Minimalizuje błędne covery, remiksy i wersje live |
| Podejrzane | Jawne `keep` albo `remove` | Ryzykowny kandydat nie przechodzi przez nieuwagę |
| Usuwanie | Atomowo przy potwierdzeniu | Anulowanie i odświeżenie nie tracą danych |
| Zmiana źródła | Unieważnienie i nowy matching | Eksport odpowiada dokładnie pokazanej zawartości |
| Ważność | 24 godziny, jednorazowe potwierdzenie | Ogranicza stare wyniki i upraszcza idempotencję |
| YouTube quota | Cache, deduplikacja i pełna rezerwacja | Próba nie kończy się częściowym wynikiem po wyczerpaniu limitu |
| Powiadomienie | Status w banku i jeden e-mail po 60 s | Użytkownik może odejść bez utraty wyniku |
| Awaria | Atomowa próba, typowany błąd i retry | Awaria providera nie udaje niedostępnego utworu |
| Testy providerów | Wyłącznie automatyczne fake'i | Chroni bardzo małą kwotę YouTube i stabilność CI |

## Zakres

**W zakresie:** cel i właściciel, trwały review, read-only matching obu platform,
trzy klasy wyniku, kolejka, cache i budżet, decyzje, atomowe potwierdzenie,
frozen manifest, polling i e-mail długiej operacji.

**Poza zakresem:** zapis u providera, retry częściowego eksportu, statusy FR-011,
ręczny wybór zamiennika, live API w testach, playlisty ponad 20 pozycji i
wnętrze platformy wdrożeniowej.

## Architektura / Podejście

`playlist + cel → właścicielski ExportReview → kolejka → atomowy wynik → decyzje
→ confirmed manifest`. Spotify używa Client Credentials i marketu właściciela,
YouTube API key i osobnego budżetu. Livewire odpytuje trwały stan.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Kontrakt i zawartość | Snapshot, model review, target identity i fingerprint | Dryf względem niescalonego S-02 |
| 2. Matching i kolejka | Klienci, klasyfikacja, cache, quota i powiadomienie | Mały budżet YouTube i różna jakość metadanych |
| 3. Przegląd i confirm | Dostępny UI oraz frozen manifest | Wyścig ze zmianą playlisty lub konta |
| 4. Odporność i wydanie | Pełna macierz fake, PostgreSQL i bramki repo | Bezpieczne wydanie addytywnych migracji |

**Wymagania wstępne:** merge `feat/playlist-link-import`; dostępny symboliczny
`YOUTUBE_API_KEY`. **Szacowany wysiłek:** około 8–12 sesji w czterech fazach.

## Otwarte ryzyka i założenia

- Spotify market istniejących linked accounts trzeba rozwiązać leniwie; migracja
  nie wykonuje requestów sieciowych.
- Limit `search.list` pozostaje ustawieniem runtime z domyślną wartością 100.
- Cache zmniejsza koszt, ale nie może mieszać marketów ani wyników po wygaśnięciu.
- S-06/S-07 muszą uznać confirmed `ExportReview` za źródło docelowych ID.
- Testy nie dowodzą dostępu produkcyjnych credentials; celowo nie zużywają małej
  kwoty YouTube.

## Kryteria sukcesu (podsumowanie)

- Użytkownik widzi trzy jednoznaczne klasy i musi jawnie rozstrzygnąć każde
  podejrzane dopasowanie przed potwierdzeniem.
- Potwierdzony manifest zachowuje dokładny cel, kolejność i docelowe ID, a zmiana
  playlisty, konta lub czasu ważności bezpiecznie go blokuje.
- Operacja do 20 pozycji jest wznawialna, nie publikuje partial state, chroni
  limit YouTube i po 60 sekundach wysyła najwyżej jeden e-mail.
