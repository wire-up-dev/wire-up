<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

trait HasSeo
{
    public function isNoindex(): bool
    {
        return (bool) ($this->metadata['noindex'] ?? false);
    }

    public function isMembersOnly(): bool
    {
        return (bool) ($this->metadata['members_only'] ?? false);
    }

    public function textExcerpt(int $chars = 160, ?string $locale = null): string
    {
        return str($this->plainText($locale))->limit($chars, '')->trim()->value();
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function visibleToVisitor(Builder $query): void
    {
        $query->when(auth()->guest(), fn (Builder $guests): Builder => $guests->where(
            fn (Builder $inner): Builder => $inner->whereNull('metadata->members_only')->orWhere('metadata->members_only', false),
        ));
    }
}
