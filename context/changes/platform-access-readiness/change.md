---
change_id: platform-access-readiness
title: Gotowość dostępu do Spotify i YouTube
status: implementing
created: 2026-09-12
updated: 2026-09-12
---

## Notes

- Spotify w MVP importuje wyłącznie playlisty należące do autoryzowanego użytkownika lub z nim współdzielone; arbitralny publiczny link Spotify nie gwarantuje dostępu do elementów playlisty.
- Konta techniczne Spotify i Google/YouTube już istnieją. Rzeczywiste próby kont użytkowników będą wykonywane oddzielnie na świeżych kontach testowych.
- Manager jest zewnętrznym PaaS i odpowiada za przechowywanie, dostarczanie oraz rotację poświadczeń kont technicznych. Ta zmiana nie opisuje ani nie testuje jego mechaniki.
- Bramka tej zmiany dotyczy gotowości deweloperskiej: Spotify Development Mode oraz Google External/Testing. Nie jest to potwierdzenie publicznej gotowości produkcyjnej.
