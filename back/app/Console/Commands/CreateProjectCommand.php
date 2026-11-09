<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesProjectOwner;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateProjectCommand extends Command
{
    use ResolvesProjectOwner;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'glitchvy:create-project {--name=} {--slug=} {--user=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new project (owned by --user, the first admin by default)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Project name');
        $slug = $this->option('slug') ?? Str::slug($name);

        if (Project::where('slug', $slug)->exists()) {
            $this->error("Project with slug '{$slug}' already exists.");

            return Command::FAILURE;
        }

        $owner = $this->resolveOwner();

        if ($owner === false) {
            return Command::FAILURE;
        }

        $project = Project::create([
            'name' => $name,
            'slug' => $slug,
            'user_id' => $owner?->id,
        ]);

        $this->info("Project created: {$project->name} ({$project->slug})");

        if ($owner === null) {
            // Without an admin to own it, the project stays visible to
            // every admin until somebody claims it.
            $this->warn('No admin user exists: the project was created without an owner.');

            return Command::SUCCESS;
        }

        $this->info("Owner: {$owner->email}");

        return Command::SUCCESS;
    }
};