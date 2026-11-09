<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 DowarDev

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

class ProjectOwnershipTest extends \Tests\TestCase
{
    public function test_index_only_returns_the_projects_of_the_authenticated_admin(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        Project::factory()->create(['user_id' => $admin->id, 'name' => 'Mine']);
        Project::factory()->create(['user_id' => $other->id, 'name' => 'Theirs']);

        $response = $this->getJson('/api/projects');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $this->assertSame('Mine', $response->json('0.name'));
    }

    public function test_index_returns_the_ownerless_projects_to_every_admin(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        Project::factory()->create(['user_id' => null, 'name' => 'Unowned']);
        Project::factory()->create(['user_id' => $other->id, 'name' => 'Theirs']);

        $response = $this->getJson('/api/projects');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $this->assertSame('Unowned', $response->json('0.name'));
    }

    public function test_store_assigns_the_authenticated_admin_as_the_owner(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/projects', [
            'name' => 'Fresh Project',
        ]);

        $response->assertStatus(201);
        $this->assertSame($admin->id, $response->json('user_id'));
        $this->assertDatabaseHas('projects', [
            'name' => 'Fresh Project',
            'user_id' => $admin->id,
        ]);
    }

    public function test_api_keys_of_a_project_of_another_admin_are_not_found(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        $project = Project::factory()->create(['user_id' => $other->id]);
        ApiKey::factory()->create(['project_id' => $project->id]);

        $this->getJson("/api/projects/{$project->id}/api-keys")
            ->assertStatus(404);
    }

    public function test_api_keys_of_an_owned_project_are_listed(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $project = Project::factory()->create(['user_id' => $admin->id]);
        ApiKey::factory()->create(['project_id' => $project->id, 'name' => 'Key one']);

        $response = $this->getJson("/api/projects/{$project->id}/api-keys");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $this->assertSame('Key one', $response->json('0.name'));
    }

    public function test_create_api_key_of_a_project_of_another_admin_is_refused(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        $project = Project::factory()->create(['user_id' => $other->id]);

        $response = $this->postJson("/api/projects/{$project->id}/api-keys", [
            'name' => 'Sneaky key',
        ]);

        $response->assertStatus(404);
        $this->assertSame(0, ApiKey::count());
    }

    public function test_create_api_key_of_an_owned_project_is_created(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $project = Project::factory()->create(['user_id' => $admin->id]);

        $response = $this->postJson("/api/projects/{$project->id}/api-keys", [
            'name' => 'My key',
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, ApiKey::count());
        $this->assertSame('My key', ApiKey::first()->name);
    }

    public function test_the_migration_assigns_the_existing_projects_to_the_operator(): void
    {
        $operator = User::factory()->create();

        // Roll the ownership column back, create a project without an
        // owner (the state of an installation that predates ownership)
        // and migrate again: the project belongs to the operator.
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        $project = Project::factory()->create(['name' => 'Legacy Project']);

        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame($operator->id, $project->fresh()->user_id);
    }

    public function test_the_operator_is_the_first_admin(): void
    {
        $first = User::factory()->create();
        User::factory()->create();

        $this->assertSame($first->id, User::operator()?->id);
    }

    public function test_the_create_project_command_owns_the_project_with_the_first_admin(): void
    {
        $operator = User::factory()->create();
        User::factory()->create();

        $exitCode = Artisan::call('glitchvy:create-project', [
            '--name' => 'Command Project',
            '--slug' => 'command-project',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('projects', [
            'slug' => 'command-project',
            'user_id' => $operator->id,
        ]);
    }

    public function test_the_create_project_command_accepts_an_explicit_owner(): void
    {
        User::factory()->create();
        $owner = User::factory()->create(['email' => 'owner@glitchvy.app']);

        $exitCode = Artisan::call('glitchvy:create-project', [
            '--name' => 'Owned Project',
            '--slug' => 'owned-project',
            '--user' => 'owner@glitchvy.app',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('projects', [
            'slug' => 'owned-project',
            'user_id' => $owner->id,
        ]);
    }

    public function test_the_create_project_command_refuses_an_unknown_owner(): void
    {
        User::factory()->create();

        $exitCode = Artisan::call('glitchvy:create-project', [
            '--name' => 'Owned Project',
            '--slug' => 'owned-project',
            '--user' => 'nobody@glitchvy.app',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('User not found', Artisan::output());
        $this->assertDatabaseMissing('projects', ['slug' => 'owned-project']);
    }

    public function test_the_create_api_key_command_refuses_a_project_of_another_admin(): void
    {
        $operator = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $other->id, 'slug' => 'theirs']);

        $exitCode = Artisan::call('glitchvy:create-api-key', [
            '--project' => 'theirs',
            '--name' => 'Key',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Project not found', Artisan::output());
        $this->assertSame(0, ApiKey::count());
    }

    public function test_the_create_api_key_command_reaches_a_project_of_the_given_admin(): void
    {
        $operator = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $other->id, 'slug' => 'theirs']);

        $exitCode = Artisan::call('glitchvy:create-api-key', [
            '--project' => 'theirs',
            '--name' => 'Key',
            '--user' => $other->email,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('api_keys', [
            'project_id' => $project->id,
            'name' => 'Key',
        ]);
    }
}
