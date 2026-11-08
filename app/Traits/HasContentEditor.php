<?php

declare(strict_types=1);

namespace App\Traits;

use App\Actions\CreateCategoryAction;
use App\Enums\ContentStatus;
use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Models\Record;
use App\Services\ContentRules;
use App\Services\MediaItem;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}
     */
    protected function contentValidation(Page|Record $content, string $basePath, string $scheduleMessage): array
    {
        $rules = [
            ...ContentRules::publication($this->status, array_keys($this->activeLocales)),
            ...ContentRules::blocks(),
            ...ContentRules::categories(),
            ...ContentRules::layout(['nullable', 'array']),
            'noindex' => ['boolean'],
            'members_only' => ['boolean'],
        ];

        $attributes = [
            'publishedLocales.*' => (string) __('language'),
            'published_at' => (string) __('scheduled date'),
            'layout.customCss' => (string) __('custom CSS'),
        ];

        foreach (array_keys($this->activeLocales) as $locale) {
            $isLive = in_array($locale, $this->publishedLocales, true);

            $slugUnique = Rule::unique('slugs', 'slug')
                ->where('locale', $locale)
                ->where('base_path', $basePath)
                ->where(fn (Builder $query): Builder => $query->whereNot(fn (Builder $own): Builder => $own
                    ->where('sluggable_id', $content->getKey())
                    ->where('sluggable_type', $content->getMorphClass())));

            $rules["title.$locale"] = $isLive ? ['required', ...ContentRules::TITLE] : ['nullable', 'string', 'max:255'];
            $rules["description.$locale"] = ContentRules::DESCRIPTION;
            $rules["slugs.$locale"] = $isLive
                ? ['required', ...ContentRules::SLUG, $slugUnique]
                : ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', $slugUnique];

            $attributes["title.$locale"] = (string) __('title');
            $attributes["description.$locale"] = (string) __('description');
            $attributes["slugs.$locale"] = (string) __('web address');
        }

        $messages = [
            'publishedLocales.*.in' => (string) __('Choose a language that is enabled in your site settings.'),
            'published_at.required' => $scheduleMessage,
            'published_at.after' => (string) __('The scheduled date must be in the future.'),
            'slugs.*.regex' => (string) __('Web addresses can only use lowercase letters, numbers and hyphens.'),
        ];

        return [$rules, $messages, $attributes];
    }
}
