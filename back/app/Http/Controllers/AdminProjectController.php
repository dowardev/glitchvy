<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminProjectController extends Controller
{
    /** The slug column is varchar(255). */
    private const MAX_SLUG_LENGTH = 255;

    /** Length of the random suffix appended on a collision: '-' + 8 chars. */
    private const SLUG_SUFFIX_LENGTH = 9;

    /**
     * Display a listing of projects.
     *
     * The admin SPA is small (tens of projects max), so we return a plain
     * array sorted by most-recent-first instead of a paginator. This keeps
     * the front simple: typescript type is Project[] and v-for in the list
     * view works directly. If the dataset ever grows past what feels
     * reasonable to send in one response, switch to a paginator and
     * update front/src/types.ts + the projects store.
     */
    public function index(Request $request)
    {
        $projects = Project::withCount('apiKeys')
            ->latest()
            ->get();

        return response()->json($projects);
    }

    /**
     * Store a newly created project.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100|unique:projects,slug',
        ]);

        // Auto-generate slug from name if not provided. The base is the
        // name slugged; on collision we append a short random suffix.
        //
        // The base is truncated so the suffix always fits the column: the
        // slug column is varchar(255) and MySQL rejects a longer value
        // outright, which SQLite happily accepts — so a name long enough
        // to fill the column would only fail on the collision that adds
        // a suffix, and only on MySQL.
        $base = Str::limit(Str::slug($validated['name']), self::MAX_SLUG_LENGTH - self::SLUG_SUFFIX_LENGTH, '');
        $slug = $validated['slug'] ?? $base;
        // The slug we started from. Retries derive from it so that an
        // explicitly requested slug stays recognisable instead of
        // silently turning into a slug of the name.
        $original = $slug;

        // The existence check and the insert are not atomic, so two
        // concurrent requests for the same name can pick the same slug.
        //
        // Two different things can go wrong here, and they deserve
        // separate budgets:
        //
        //   - the slug is already taken. That is the normal case for a
        //     name that collides with an existing project. It is not a
        //     failure, so it must not consume a race retry — it only
        //     means "pick a different suffix and carry on".
        //   - the insert loses a race against a concurrent request that
        //     took the slug between the check and the insert. That is
        //     the failure worth retrying, and only that.
        $raceAttempts = 0;
        $maxRaceAttempts = 3;

        // Backstop so the loop can never spin forever, whatever the
        // random suffixes do.
        $iterations = 0;
        $maxIterations = 10;

        while ($iterations++ < $maxIterations) {
            if (! Project::where('slug', $slug)->exists()) {
                try {
                    $project = Project::create([
                        'name' => $validated['name'],
                        'slug' => $slug,
                    ]);

                    return response()->json($project, 201);
                } catch (UniqueConstraintViolationException) {
                    // Lost the race against a concurrent insert that
                    // took this slug. This one counts as an attempt.
                    $raceAttempts++;

                    if ($raceAttempts > $maxRaceAttempts) {
                        return response()->json([
                            'message' => 'Could not allocate a unique slug for this project name. Please try again.',
                        ], 409);
                    }
                }
            }

            $slug = $original.'-'.Str::lower(Str::random(8));
        }

        return response()->json([
            'message' => 'Could not allocate a unique slug for this project name. Please try again.',
        ], 409);
    }
    /**
     * Display a listing of the API keys for a project.
     *
     * The plaintext key is never returned here — it is shown exactly
     * once, in the createApiKey response. Only the prefix, the name and
     * the last-used timestamp are exposed.
     */
    public function apiKeys(Request $request, int $id)
    {
        $project = Project::findOrFail($id);

        $keys = $project->apiKeys()
            ->latest()
            ->get()
            ->map(fn (ApiKey $key) => [
                'id' => $key->id,
                'project_id' => $key->project_id,
                'name' => $key->name,
                'prefix' => $key->key_prefix,
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'created_at' => $key->created_at?->toIso8601String(),
            ]);

        return response()->json($keys);
    }

    /**
     * Create a new API key for a project.
     */
    public function createApiKey(Request $request, int $id)
    {
        $project = Project::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $plainKey = 'gv_'.Str::random(40);
        $prefix = substr($plainKey, 0, 8);
        $hash = Hash::make($plainKey);

        $apiKey = ApiKey::create([
            'project_id' => $project->id,
            'name' => $validated['name'],
            'key_hash' => $hash,
            'key_prefix' => $prefix,
        ]);

        return response()->json([
            'api_key' => [
                'id' => $apiKey->id,
                'project_id' => $apiKey->project_id,
                'name' => $apiKey->name,
                'prefix' => $apiKey->key_prefix,
                'last_used_at' => $apiKey->last_used_at?->toIso8601String(),
                'created_at' => $apiKey->created_at?->toIso8601String(),
            ],
            'plaintext' => $plainKey,
        ], 201);
    }
};