<?php

declare(strict_types=1);

use App\Models\Role;

function bookingAbilitiesBackfill(): object
{
    return require database_path('migrations/'.basename((string) collect(glob(database_path('migrations/*_grant_booking_abilities_to_administrators.php')))->first()));
}

it('lets existing administrators view and manage bookings', function (): void {
    $admin = Role::query()->where('key', 'admin')->firstOrFail();
    $admin->update(['abilities' => ['pages.view']]);

    bookingAbilitiesBackfill()->up();

    expect($admin->refresh()->abilities)->toBe(['pages.view', 'bookings.view', 'bookings.edit', 'bookings.delete']);
});

it('leaves an administrator role whose booking access was already chosen', function (): void {
    $admin = Role::query()->where('key', 'admin')->firstOrFail();
    $admin->update(['abilities' => ['bookings.view']]);

    bookingAbilitiesBackfill()->up();

    expect($admin->refresh()->abilities)->toBe(['bookings.view']);
});

it('does nothing on a site without an administrator role', function (): void {
    Role::query()->where('key', 'admin')->delete();

    bookingAbilitiesBackfill()->up();

    expect(Role::query()->where('key', 'admin')->exists())->toBeFalse();
});
