<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesProjectOwner;
use App\Models\ApiKey;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateApiKeyCommand extends Command
{
    use ResolvesProjectOwner;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'glitchvy:create-api-key {--project=} {--name=} {--user=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new API key for a project (--project, --user defaults to the first admin)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $projectIdentifier = $this->option('project');
        $name = $this->option('name');

        $owner = $this->resolveOwner();

        if ($owner === false) {
            return Command::FAILURE;
        }

        // The projects the operator can manage are the ones they own
        // (plus the ones nobody owns). With no admin at all, nothing is
        // scoped: the CLI reaches the whole database.
        $projects = $owner === null
            ? Project::query()
            : Project::visibleTo($owner);

        if (! $projectIdentifier) {
            $choices = $projects->pluck('slug', 'id')->toArray();

            if (empty($choices)) {
                $this->error('No projects found. Create one first with glitchvy:create-project.');

                return Command::FAILURE;
            }

            $projectIdentifier = $this->choice('Select a project', $choices);
        }

        $project = is_numeric($projectIdentifier)
            ? $projects->find($projectIdentifier)
            : $projects->where('slug', $projectIdentifier)->first();

        if (! $project) {
            $this->error("Project not found: {$projectIdentifier}");

            return Command::FAILURE;
        }

        if (! $name) {
            $name = $this->ask('API key name');
        }

        $plainKey = 'gv_'.Str::random(40);
        $prefix = substr($plainKey, 0, 8);
        $hash = Hash::make($plainKey);

        $apiKey = ApiKey::create([
            'project_id' => $project->id,
            'name' => $name,
            'key_hash' => $hash,
            'key_prefix' => $prefix,
        ]);

        $this->info("API key created for project: {$project->name}");
        $this->warn('This is the only time the key will be displayed. Save it now.');
        $this->line('');
        $this->line("Key: {$plainKey}");
        $this->line('');

        return Command::SUCCESS;
    }
};