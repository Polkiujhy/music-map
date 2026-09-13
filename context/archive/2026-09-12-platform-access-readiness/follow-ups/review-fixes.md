# Follow-upy przeglądu implementacji

## Faza 3 — dowód braku fallbacku principal

- Rozszerzyć test punktu wyboru źródła principal tak, aby jednocześnie udostępniał poprawne źródło alternatywne i błędne źródło wybranego principal.
- Potwierdzić, że orkiestrator zwraca błąd wybranego źródła i nigdy nie przełącza się między `technical` i `tester`.
- Źródło: F3 z `reviews/impl-review-phase-1.md`.
