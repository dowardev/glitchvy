<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateApiKeyCommand extends Command
{
    protected $signature = 'glitchvy:key
        {--rotate : Show only the new key (do not update .env)}
        {--length=32 : Byte length for random_bytes (resulting string is 2x length in hex)}';

    protected $description = 'Generate a fresh glitchvy API key and write it to .env (GLITCHVY_API_KEY).';

    public function handle(): int
    {
        $length = (int) $this->option('length');
        if ($length < 16) {
            $this->error('Length must be at least 16 bytes.');
            return self::INVALID;
        }

        $key = bin2hex(random_bytes($length));

        if ($this->option('rotate')) {
            $this->line($key);
            return self::SUCCESS;
        }

        $envPath = base_path('.env');
        if (! File::exists($envPath)) {
            $this->warn('No .env file at '.base_path('.').'. Printing key instead:');
            $this->line($key);
            return self::SUCCESS;
        }

        $contents = File::get($envPath);
        $line = "GLITCHVY_API_KEY={$key}";

        if (preg_match('/^GLITCHVY_API_KEY=.*$/m', $contents) === 1) {
            $contents = (string) preg_replace(
                '/^GLITCHVY_API_KEY=.*$/m',
                $line,
                $contents,
            );
            File::put($envPath, $contents);
            $this->info('Rotated GLITCHVY_API_KEY in .env.');
        } else {
            File::append($envPath, PHP_EOL.$line.PHP_EOL);
            $this->info('Appended GLITCHVY_API_KEY to .env.');
        }

        $this->warn('Remember to redeploy / restart your application to pick up the new key.');
        return self::SUCCESS;
    }
}
