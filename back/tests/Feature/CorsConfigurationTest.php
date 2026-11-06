<?php
// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;

class CorsConfigurationTest extends \Tests\TestCase
{
    public function test_the_ingest_endpoint_answers_any_origin(): void
    {
        // The SDK posts from the browser of the consumer application, from
        // that application's own origin. A narrow list would lose those
        // reports silently.
        $response = $this->getJson('/api/glitchvy/errors', [
            'Origin' => 'https://consumer-app.example',
        ]);

        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_the_allowed_origins_are_configurable(): void
    {
        Config::set('cors.allowed_origins', ['https://admin.example']);

        $response = $this->getJson('/api/glitchvy/errors', [
            'Origin' => 'https://admin.example',
        ]);

        $this->assertSame('https://admin.example', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
