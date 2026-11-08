<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('signs out a staff member whose account was disabled while they were signed in', function (): void {
    $editor = User::factory()->editor()->create(['active' => true]);

    $this->actingAs($editor)->get(route('admin.dashboard'))->assertOk();

    $editor->update(['active' => false]);

    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

it('signs out a disabled member on the public site', function (): void {
    $member = User::factory()->member()->create(['active' => false]);

    $this->actingAs($member)->get(route('account'))->assertRedirect(route('login'));

    $this->assertGuest();
});

it('leaves active users signed in', function (): void {
    $editor = User::factory()->editor()->create(['active' => true]);

    $this->actingAs($editor)->get(route('admin.dashboard'))->assertOk();

    $this->assertAuthenticatedAs($editor);
});
