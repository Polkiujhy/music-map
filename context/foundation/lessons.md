# Lessons Learned

> Rejestr tylko do dodawania powtarzających się reguł i wzorców. Odczytywany ponownie na początku przez /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## Nie używaj `git push` bez wyraźnej akceptacji

- **Context**: To repozytorium.
- **Problem**: Nieautoryzowany `git push` może zmienić zdalną historię repozytorium bez świadomej decyzji użytkownika.
- **Rule**: Nigdy nie używaj `git push` bez wyraźnej akceptacji użytkownika.
- **Applies to**: all

## Weryfikuj ustalenia przed wpisaniem ich do planu lub recenzji

- **Context**: Pisanie planów i ich przeglądów.
- **Problem**: Nadmiernie defensywne założenia mogą wprowadzić niedoświadczonego programistę w błąd.
- **Rule**: Weryfikuj twierdzenia przed zapisaniem ich w planie lub recenzji; nie przedstawiaj hipotetycznych problemów jako ustaleń bez wystarczających dowodów.
- **Applies to**: plan, plan-review
