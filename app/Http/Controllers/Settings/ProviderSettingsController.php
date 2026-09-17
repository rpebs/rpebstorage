<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings\ProviderSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProviderSettingsController extends Controller
{
    public function __construct(
        private ProviderSettings $settings
    ) {}

    public function edit(): Response
    {
        return Inertia::render('settings/Providers', [
            'providers' => $this->settings->providersForUi(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'settings' => ['present', 'array'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['nullable', 'string', 'max:500'],
        ]);

        $values = [];

        foreach ($validated['settings'] as $field) {
            $values[(string) $field['key']] = (string) ($field['value'] ?? '');
        }

        $changed = $this->settings->set($values);

        return $this->toast([
            'type' => 'success',
            'message' => $changed === []
                ? 'Tidak ada kredensial yang berubah.'
                : count($changed).' kredensial provider disimpan.',
        ]);
    }
}
