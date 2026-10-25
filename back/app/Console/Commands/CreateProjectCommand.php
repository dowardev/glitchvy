<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateProjectCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'glitchvy:create-project {--name=} {--slug=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new project';

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

        $project = Project::create([
            'name' => $name,
            'slug' => $slug,
        ]);

        $this->info("Project created: {$project->name} ({$project->slug})");

        return Command::SUCCESS;
    }
};