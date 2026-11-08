<?php

declare(strict_types=1);

namespace App\Traits;

use App\Actions\CreateCategoryAction;
use App\Enums\ContentStatus;
use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Services\MediaItem;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

trait HasContentEditor
{
    public ContentStatus $status;

    /**
     * @var array<int, string>
     */
    public array $publishedLocales = [];

    public ?CarbonImmutable $published_at = null;

    #[Url(except: 'en')]
    public string $locale;

    /**
     * @var array<string, mixed>
     */
    public array $activeLocales = [];

    /**
     * @return Collection<int, Media>
     */
    abstract protected function editorMedia(): Collection;

    #[On('change-locale')]
    public function createCategory(CreateCategoryAction $action): void
    {
        $this->authorize('categories.create');

        $name = mb_trim($this->categorySearch);

        if ($name === '') {
            return;
        }

        $category = $action->handle(['name' => [$this->locale => $name]]);

        $this->categories[] = (string) $category->id;
        $this->categorySearch = '';

        unset($this->categoryOptions);
    }

    /**
     * @return EloquentCollection<int, Category>
     */
    #[Computed]
    public function categoryOptions(): EloquentCollection
    {
        return Category::query()
            ->with('translations')
            ->get()
            ->sortBy(fn (Category $category): string => $category->name)
            ->values();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function pageLinkOptions(): array
    {
        $pages = Page::query()
            ->with('translations')
            ->get()
            ->sortBy(fn (Page $page): string => $page->title)
            ->mapWithKeys(fn (Page $page): array => [$page->id => $page->title])
            ->all();

        return $pages + SettingsService::current()->authPageOptions();
    }

    public function changeLocale(): void
    {
        $codes = array_keys($this->activeLocales);
        $index = array_search($this->locale, $codes, true);

        $this->locale = $codes[($index + 1) % count($codes)] ?? $this->locale;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function mediaForRole(string $role): array
    {
        return $this->editorMedia()
            ->filter(fn (Media $media): bool => $media->pivot->role === $role)
            ->groupBy(fn (Media $media): string => $media->pivot->locale)
            ->map(fn (Collection $items): array => $items
                ->sortBy(fn (Media $media): int => $media->pivot->position)
                ->map(fn (Media $media): array => $this->mediaToItem($media))
                ->values()
                ->all()
            )
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mediaToItem(Media $media): array
    {
        return [
            ...MediaItem::fromMedia($media),
            'crop' => $media->pivot->crop ?? [],
            'metadata' => $media->pivot->metadata ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $media
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function normalizeMediaInput(array $media): array
    {
        $result = [];

        foreach ($media as $locale => $value) {
            if (is_array($value) && array_is_list($value)) {
                $result[$locale] = $value;
            } elseif (is_array($value)) {
                $result[$locale] = [$value];
            } else {
                $result[$locale] = [];
            }
        }

        return $result;
    }

    protected function revealErrors(ValidationException $e): void
    {
        $codes = array_keys($this->activeLocales);

        foreach (array_keys($e->errors()) as $key) {
            $locale = str($key)->afterLast('.')->value();

            if (in_array($locale, $codes, true)) {
                $this->locale = $locale;

                return;
            }
        }
    }
}
