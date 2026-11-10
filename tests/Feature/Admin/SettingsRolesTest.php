<?php

declare(strict_types=1);

use App\Enums\PermissionAction;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionRegistry;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('is reachable only with the roles view ability', function (): void {
    $this->actingAsAdmin()
        ->get(route('admin.settings-roles'))
        ->assertOk()
        ->assertSeeLivewire('pages::admin.settings-roles');
});

it('forbids roles without the roles ability', function (): void {
    $editor = User::factory()->editor()->create(['active' => true]);

    $this->actingAs($editor)
        ->get(route('admin.settings-roles'))
        ->assertForbidden();
});

it('creates a custom role with the selected permissions', function (): void {
    $this->actingAsAdmin();

    $component = Livewire::test('pages::admin.settings-roles')->call('addRole');

    $newIndex = count($component->get('roles')) - 1;

    $component
        ->set("roles.{$newIndex}.name", 'Support')
        ->set("roles.{$newIndex}.grants.0", ['view', 'edit'])
        ->call('save')
        ->assertHasNoErrors();

    $role = Role::query()->where('key', 'support')->first();

    expect($role)->not->toBeNull()
        ->and($role->abilities)->toBe(['pages.view', 'pages.edit'])
        ->and($role->is_protected)->toBeFalse();
});

it('requires a name for every role', function (): void {
    $this->actingAsAdmin();

    $component = Livewire::test('pages::admin.settings-roles')->call('addRole');
    $newIndex = count($component->get('roles')) - 1;

    $component->call('save')->assertHasErrors("roles.{$newIndex}.name");
});

it('updates an editable role but leaves protected roles untouched', function (): void {
    $this->actingAsAdmin();

    $component = Livewire::test('pages::admin.settings-roles');
    $roles = collect($component->get('roles'));

    $editorIndex = $roles->search(fn (array $role): bool => $role['key'] === 'editor');

    $component
        ->set("roles.{$editorIndex}.grants.3", ['view'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Role::query()->where('key', 'editor')->value('abilities'))->toContain('users.view')
        ->and(Role::query()->where('key', 'owner')->first()->bypass)->toBeTrue()
        ->and(Role::query()->where('key', 'member')->value('abilities'))->toBe([]);
});

it('blocks deleting a role that still has members', function (): void {
    $this->actingAsAdmin();

    $role = Role::query()->create(['key' => 'temp', 'name' => 'Temp', 'abilities' => ['pages.view'], 'bypass' => false, 'is_protected' => false]);
    User::factory()->for($role)->create();

    $component = Livewire::test('pages::admin.settings-roles');
    $tempKey = collect($component->get('roles'))->firstWhere('key', 'temp')['_key'];

    $component->call('confirmRemove', $tempKey)
        ->assertSet('removeUserCount', 1)
        ->call('removeRole');

    expect(Role::query()->where('key', 'temp')->exists())->toBeTrue();
});

it('removes an unused role on save', function (): void {
    $this->actingAsAdmin();

    Role::query()->create(['key' => 'temp', 'name' => 'Temp', 'abilities' => ['pages.view'], 'bypass' => false, 'is_protected' => false]);

    $component = Livewire::test('pages::admin.settings-roles');
    $tempKey = collect($component->get('roles'))->firstWhere('key', 'temp')['_key'];

    $component->call('confirmRemove', $tempKey)
        ->call('removeRole')
        ->call('save')
        ->assertHasNoErrors();

    expect(Role::query()->where('key', 'temp')->exists())->toBeFalse();
});

it('will not delete a protected role', function (): void {
    $this->actingAsAdmin();

    $component = Livewire::test('pages::admin.settings-roles');
    $ownerKey = collect($component->get('roles'))->firstWhere('key', 'owner')['_key'];

    $component->call('confirmRemove', $ownerKey);

    expect($component->get('removeKey'))->toBeNull();
});

it('labels crud and non-crud (assistant "use") actions in the roles builder', function (): void {
    $this->actingAsAdmin();

    $component = Livewire::test('pages::admin.settings-roles');

    expect($component->instance()->actionLabel('view'))->toBe(PermissionAction::View->label())
        ->and($component->instance()->actionLabel('use'))->toBe(__('Use'));
});

it('refuses to grant access the editor of the roles does not hold, even on their own role', function (): void {
    $ownRole = Role::factory()->create(['key' => 'role-manager', 'name' => 'Role manager', 'abilities' => ['roles.view', 'roles.edit'], 'bypass' => false, 'is_protected' => false]);
    $this->actingAs(User::factory()->role($ownRole)->create(['active' => true]));

    $component = Livewire::test('pages::admin.settings-roles');
    $index = collect($component->get('roles'))->search(fn (array $role): bool => $role['id'] === $ownRole->id);

    $component->set("roles.{$index}.grants.4", ['view', 'edit'])
        ->call('save')
        ->assertHasErrors(['roles' => 'You can’t change access you don’t have yourself.']);

    expect($ownRole->fresh()->abilities)->toBe(['roles.view', 'roles.edit']);
});

function rolesManager(): Role
{
    $manager = Role::factory()->create(['key' => 'role-manager', 'name' => 'Role manager', 'abilities' => ['roles.view', 'roles.edit', 'roles.delete', 'pages.view'], 'bypass' => false, 'is_protected' => false]);
    test()->actingAs(User::factory()->role($manager)->create(['active' => true]));

    return $manager;
}

it('refuses to take away access the editor of the roles does not hold', function (): void {
    rolesManager();
    $publisher = Role::factory()->create(['key' => 'publisher', 'name' => 'Publisher', 'abilities' => ['pages.view', 'settings.edit'], 'bypass' => false, 'is_protected' => false]);

    $component = Livewire::test('pages::admin.settings-roles');
    $index = collect($component->get('roles'))->search(fn (array $role): bool => $role['id'] === $publisher->id);
    $settings = collect(PermissionRegistry::resources())->search(fn (array $resource): bool => $resource['key'] === 'settings');

    $component->set("roles.{$index}.grants.{$settings}", [])
        ->call('save')
        ->assertHasErrors(['roles' => 'You can’t change access you don’t have yourself.']);

    expect($publisher->fresh()->abilities)->toBe(['pages.view', 'settings.edit']);
});

it('lets the editor of the roles take away access they hold, and keeps access that no longer exists out of the way', function (): void {
    rolesManager();
    $publisher = Role::factory()->create(['key' => 'publisher', 'name' => 'Publisher', 'abilities' => ['pages.view', 'records.retired.view'], 'bypass' => false, 'is_protected' => false]);

    $component = Livewire::test('pages::admin.settings-roles');
    $index = collect($component->get('roles'))->search(fn (array $role): bool => $role['id'] === $publisher->id);
    $pages = collect(PermissionRegistry::resources())->search(fn (array $resource): bool => $resource['key'] === 'pages');

    $component->set("roles.{$index}.grants.{$pages}", [])
        ->call('save')
        ->assertHasNoErrors();

    expect($publisher->fresh()->abilities)->toBeEmpty();
});

it('refuses to delete a role with access the editor of the roles does not hold', function (): void {
    rolesManager();
    $unused = Role::factory()->create(['key' => 'publisher', 'name' => 'Publisher', 'abilities' => ['settings.edit'], 'bypass' => false, 'is_protected' => false]);
    $temp = Role::factory()->create(['key' => 'temp', 'name' => 'Temp', 'abilities' => ['pages.view'], 'bypass' => false, 'is_protected' => false]);

    $component = Livewire::test('pages::admin.settings-roles');

    foreach ([$temp, $unused] as $role) {
        $component->call('confirmRemove', collect($component->get('roles'))->firstWhere('id', $role->id)['_key'])->call('removeRole');
    }

    $component->call('save')->assertHasErrors(['roles' => 'You can’t change access you don’t have yourself.']);

    expect(Role::query()->whereKey([$unused->id, $temp->id])->count())->toBe(2);
});
