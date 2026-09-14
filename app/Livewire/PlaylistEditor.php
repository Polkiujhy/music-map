<?php

namespace App\Livewire;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Actions\Playlists\PlaylistEditConflict;
use App\Actions\Playlists\UpdateBankPlaylistItems;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PlaylistEditor extends Component
{
    #[Locked]
    public string $playlistId;

    /** @var list<int> */
    #[Locked]
    public array $orderedItemIds = [];

    /** @var array<int, array{id: int, title: ?string, creators: list<string>, album: ?string, catalog_id: ?string, is_available: bool}> */
    #[Locked]
    public array $itemDetails = [];

    #[Locked]
    public string $initialFingerprint;

    #[Locked]
    public bool $emptyRemovalPending = false;

    #[Locked]
    public bool $emptyResultConfirmed = false;

    public ?string $statusMessage = null;

    public ?string $conflictMessage = null;

    public function mount(string $playlistId, FingerprintPlaylistContent $fingerprint): void
    {
        $this->playlistId = $playlistId;
        $this->loadDraft($this->ownedPlaylist(), $fingerprint);
    }

    public function moveUp(int $index): void
    {
        if ($index <= 0 || $index >= count($this->orderedItemIds)) {
            return;
        }

        [$this->orderedItemIds[$index - 1], $this->orderedItemIds[$index]] = [
            $this->orderedItemIds[$index],
            $this->orderedItemIds[$index - 1],
        ];
        $this->draftChanged();
    }

    public function moveDown(int $index): void
    {
        if ($index < 0 || $index >= count($this->orderedItemIds) - 1) {
            return;
        }

        [$this->orderedItemIds[$index], $this->orderedItemIds[$index + 1]] = [
            $this->orderedItemIds[$index + 1],
            $this->orderedItemIds[$index],
        ];
        $this->draftChanged();
    }

    public function removeItem(int $index): void
    {
        if (! array_key_exists($index, $this->orderedItemIds)) {
            return;
        }

        if (count($this->orderedItemIds) === 1) {
            $this->emptyRemovalPending = true;
            $this->statusMessage = null;

            return;
        }

        array_splice($this->orderedItemIds, $index, 1);
        $this->emptyResultConfirmed = false;
        $this->draftChanged();
    }

    public function cancelEmptyRemoval(): void
    {
        $this->emptyRemovalPending = false;
    }

    public function confirmEmptyRemoval(): void
    {
        if (! $this->emptyRemovalPending || count($this->orderedItemIds) !== 1) {
            return;
        }

        $this->orderedItemIds = [];
        $this->emptyRemovalPending = false;
        $this->emptyResultConfirmed = true;
        $this->draftChanged();
    }

    public function save(
        UpdateBankPlaylistItems $update,
        FingerprintPlaylistContent $fingerprint,
    ): void {
        $playlist = $this->ownedPlaylist();
        $this->statusMessage = null;
        $this->conflictMessage = null;

        try {
            $playlist = $update->handle(
                $this->owner(),
                (int) $playlist->getKey(),
                $this->initialFingerprint,
                $this->orderedItemIds,
                $this->emptyResultConfirmed,
            );
        } catch (PlaylistEditConflict) {
            $this->conflictMessage = 'Playlista zmieniła się od czasu otwarcia edytora. Załaduj aktualną wersję i spróbuj ponownie.';

            return;
        }

        $this->loadDraft($playlist, $fingerprint);
        $this->statusMessage = 'Zmiany w playliście zostały zapisane.';
    }

    public function render(): View
    {
        $catalogCounts = collect($this->orderedItemIds)
            ->map(fn (int $itemId): ?string => $this->itemDetails[$itemId]['catalog_id'] ?? null)
            ->filter()
            ->countBy();

        return view('livewire.playlist-editor', ['catalogCounts' => $catalogCounts]);
    }

    private function ownedPlaylist(): Playlist
    {
        if (! ctype_digit($this->playlistId) || (int) $this->playlistId <= 0) {
            abort(404);
        }

        return $this->owner()->playlists()
            ->whereKey($this->playlistId)
            ->with('items')
            ->firstOrFail();
    }

    private function owner(): User
    {
        $owner = auth()->user();

        abort_unless($owner instanceof User, 404);

        return $owner;
    }

    private function loadDraft(Playlist $playlist, FingerprintPlaylistContent $fingerprint): void
    {
        if (! $playlist->relationLoaded('items')) {
            $playlist->load('items');
        }

        $this->orderedItemIds = $playlist->items->modelKeys();
        $this->itemDetails = $playlist->items
            ->mapWithKeys(static fn ($item): array => [
                (int) $item->getKey() => [
                    'id' => (int) $item->getKey(),
                    'title' => $item->title,
                    'creators' => array_values($item->creators),
                    'album' => $item->album,
                    'catalog_id' => $item->catalog_id,
                    'is_available' => $item->is_available,
                ],
            ])
            ->all();
        $this->initialFingerprint = $fingerprint->handle($playlist);
        $this->emptyRemovalPending = false;
        $this->emptyResultConfirmed = $playlist->items->isEmpty();
        $this->conflictMessage = null;
    }

    private function draftChanged(): void
    {
        $this->statusMessage = null;
        $this->conflictMessage = null;
    }
}
