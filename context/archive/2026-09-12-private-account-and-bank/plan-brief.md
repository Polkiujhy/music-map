# Prywatne konto i wejście do banku playlist — krótki plan

> Pełny plan: `context/changes/private-account-and-bank/plan.md`

## Co i dlaczego

Budujemy pierwszy użyteczny fundament `music-map`: rejestrację i logowanie e-mailem lub przez Google oraz wejście do prywatnego, początkowo pustego banku. Jedna osoba ma zawsze jedno konto według zweryfikowanego, znormalizowanego adresu e-mail, niezależnie od użytej metody logowania.

## Punkt wyjścia

Repo jest wdrożonym szkieletem Laravel 13 z sesyjnym guardem i produkcyjną tabelą `users`, ale bez auth UI, Google OAuth, Livewire/Flux i chronionych tras. Publiczne `/` nadal pokazuje ekran Laravela, a jedyne testowanie bazy w CI korzysta z SQLite.

## Pożądany stan końcowy

Gość widzi minimalny polski landing, może przejść pełny cykl konta e-mailowego albo użyć Google, a po weryfikacji trafia na `/bank`. Bank jasno komunikuje prywatność i przyszły import, nie udostępniając jeszcze modeli playlist ani martwych kontroli.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego |
| --- | --- | --- |
| Zakres auth | Rejestracja, login, logout, reset hasła | Konto e-mailowe ma być samodzielnie odzyskiwalne |
| Weryfikacja | Wymagana przed bankiem | Własność przyszłych danych opiera się na potwierdzonym adresie |
| Ten sam e-mail w Google | Automatyczne połączenie | Realizuje zasadę jednego konta bez tarcia |
| Konflikt Google | Atomowa, bezpieczna odmowa | Tożsamości nie wolno przepinać na podstawie zmiany e-maila |
| Trasa banku | Kanoniczne `/bank` / `bank.index` | Stabilny kontrakt domenowy dla S-02 i S-03 |
| Landing | Minimalny ekran produktu | Zastępuje onboarding Laravela bez budowy pełnej strony marketingowej |
| Pusty bank | Informacja bez aktywnego importu | S-01 nie udaje jeszcze funkcji S-02 |
| Sesja | Opcjonalne „Zapamiętaj mnie” | Użytkownik świadomie wybiera wygodę kosztem trwałego tokenu |
| UI/auth stack | Fortify + Livewire 4 + Flux | Spełnia trwałą decyzję stosu bez ponownego scaffoldowania |
| Google OAuth | Socialite bez zapisu tokenów | Do samego logowania tokeny nie są potrzebne po callbacku |
| Test bazy | SQLite plus izolowany PostgreSQL smoke | Pokrywa różnice silnika bez ryzyka dla produkcji |

## Zakres

**W zakresie:** e-mail auth, weryfikacja, reset, opcjonalna trwała sesja, Google OAuth, łączenie tożsamości, polski landing, chroniony pusty bank, dostępność, testy SQLite/PostgreSQL i bezpieczny rollout migracji.

**Poza zakresem:** modele i dane playlist, import, edycja, synchronizacja, streaming account linking, profil, zmiana e-maila, usuwanie konta, 2FA, passkeys, WorkOS i ręczne SQL.

## Architektura / Podejście

Fortify obsługuje cykl e-mailowy, Livewire/Flux dostarczają serwerowy UI, a Socialite wykonuje stanowy OAuth Google. `User` ma wiele `AuthIdentity`; tabela zapisuje wyłącznie provider i stabilny subject. Callback waliduje zweryfikowany e-mail przed krótką transakcją, która znajduje istniejący subject, dopina istniejący e-mail albo tworzy verified passwordless user.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Fundament auth i tożsamości | Zależności, konfigurację i addytywny schemat | Zgodność starego obrazu z nowym schematem |
| 2. E-mailowy auth | Pełny cykl konta i dostępne formularze | Weryfikacja poczty, sesje i rate limiting |
| 3. Google | Idempotentne tworzenie/łączenie kont | Przepięcie lub częściowy zapis przy konflikcie |
| 4. Landing i bank | Publiczne wejście oraz chronione `/bank` | Pętle redirectów i martwe UX |
| 5. Utwardzenie | PostgreSQL CI oraz kontrolowane wydanie | Rozjazd SQLite/PostgreSQL i migracja produkcji |

**Wymagania wstępne:** testowa skrzynka pocztowa i klient Google z dokładnym HTTPS callbackiem. Przed produkcyjnym `schema-release` helper provisioningu musi zostać ponownie zainstalowany jako zweryfikowany `root:root` 0444.

**Szacowany wysiłek:** około 4–6 sesji implementacyjnych w pięciu fazach plus ręczna bramka wydania.

## Otwarte ryzyka i założenia

- Produkcyjny baseline już zawiera `users`; żadna tabela nie będzie tworzona ręcznie.
- Normalizacja trim/lowercase jest obowiązkowa na wszystkich wejściach auth, a constraints tabeli identities rozstrzygają wyścigi callbacków.
- Prawdziwy Google callback i dostarczenie poczty wymagają zewnętrznej konfiguracji, której wartości nie trafiają do repo.
- Obecny owner helpera provisioningu jest błędny (`nobody:nogroup`); to znany prerequisite operacyjny, nie zadanie aplikacyjne.
- S-01 dowodzi ochrony pustego banku na poziomie trasy; izolacja rekordów playlist zostanie dodana wraz z modelem danych w S-02.

## Kryteria sukcesu (podsumowanie)

- Użytkownik kończy pełny, zweryfikowany cykl e-mailowy lub Google i zawsze trafia do jednego konta.
- Gość ani użytkownik bez weryfikacji nie może otworzyć `/bank`, a konflikty Google nie zmieniają danych.
- Pełne testy przechodzą na SQLite, krytyczna macierz na PostgreSQL, a migracje produkcyjne przechodzą wyłącznie przez nadzorowany `schema-release`.
