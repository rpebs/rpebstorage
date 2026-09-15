<?php

namespace App\Http\Controllers;

use App\Models\Label;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabelController extends Controller
{
    public const DEFAULT_LABELS = [
        ['name' => 'Pribadi', 'color' => 'teal'],
        ['name' => 'Pajak', 'color' => 'amber'],
        ['name' => 'Kerja', 'color' => 'blue'],
        ['name' => 'Backup', 'color' => 'purple'],
    ];

    public function index(Request $request)
    {
        $labels = Label::where('user_id', $request->user()->id)
            ->withCount(['files', 'folders'])
            ->orderBy('name')
            ->get();

        return response()->json(['labels' => $labels]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('labels', 'name')->where('user_id', $user->id),
            ],
            'color' => ['nullable', 'string', 'max:30'],
        ]);

        $label = Label::create([
            'user_id' => $user->id,
            'name' => trim($validated['name']),
            'color' => $validated['color'] ?: 'teal',
        ]);

        if ($request->wantsJson()) {
            return response()->json(['label' => $label], 201);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Label \"{$label->name}\" berhasil dibuat.",
        ]);
    }

    public function update(Request $request, Label $label)
    {
        $user = $request->user();
        abort_unless($label->user_id === $user->id, 404);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('labels', 'name')
                    ->where('user_id', $user->id)
                    ->ignore($label->id),
            ],
            'color' => ['nullable', 'string', 'max:30'],
        ]);

        $label->update([
            'name' => trim($validated['name']),
            'color' => $validated['color'] ?: 'teal',
        ]);

        if ($request->wantsJson()) {
            return response()->json(['label' => $label]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Label \"{$label->name}\" berhasil diperbarui.",
        ]);
    }

    public function destroy(Request $request, Label $label)
    {
        abort_unless($label->user_id === $request->user()->id, 404);

        $name = $label->name;
        $label->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Label berhasil dihapus.']);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Label \"{$name}\" berhasil dihapus.",
        ]);
    }

    public function seedDefaults(Request $request)
    {
        $user = $request->user();

        foreach (self::DEFAULT_LABELS as $item) {
            Label::firstOrCreate(
                ['user_id' => $user->id, 'name' => $item['name']],
                ['color' => $item['color']]
            );
        }

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Label bawaan berhasil ditambahkan.']);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Label bawaan (Pribadi, Pajak, Kerja, Backup) berhasil ditambahkan.',
        ]);
    }
}
