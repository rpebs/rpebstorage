<?php

namespace App\Http\Controllers;

use App\Enums\JobStatus;
use App\Models\FileJob;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $status = $request->input('status', 'all');
        $search = trim((string) $request->input('q', ''));

        $baseQuery = FileJob::where('user_id', $user->id);

        $counts = [
            'all' => (clone $baseQuery)->count(),
            'done' => (clone $baseQuery)->where('status', JobStatus::Done)->count(),
            'failed' => (clone $baseQuery)->where('status', JobStatus::Failed)->count(),
            'active' => (clone $baseQuery)->whereIn('status', [JobStatus::Pending, JobStatus::Processing])->count(),
        ];

        $query = FileJob::where('user_id', $user->id)
            ->with(['folder', 'account.provider'])
            ->latest();

        if ($status === 'done') {
            $query->where('status', JobStatus::Done);
        } elseif ($status === 'failed') {
            $query->where('status', JobStatus::Failed);
        } elseif ($status === 'active' || $status === 'processing') {
            $query->whereIn('status', [JobStatus::Pending, JobStatus::Processing]);
        }

        if ($search !== '') {
            $query->where('original_name', 'like', "%{$search}%");
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('logs/index', [
            'logs' => [
                'data' => collect($paginator->items())->map(fn (FileJob $job) => [
                    'id' => $job->id,
                    'name' => $job->original_name,
                    'type' => $job->type,
                    'size' => $job->size,
                    'status' => $job->status->value,
                    'progress' => $job->progress,
                    'error' => $job->error,
                    'folder_name' => $job->folder?->name ?? 'Root',
                    'account_label' => $job->account ? "{$job->account->alias} ({$job->account->provider->label()})" : null,
                    'created_at' => $job->created_at?->toISOString(),
                    'updated_at' => $job->updated_at?->toISOString(),
                ]),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
                'links' => $paginator->linkCollection()->toArray(),
            ],
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'counts' => $counts,
        ]);
    }

    public function destroy(Request $request, FileJob $job)
    {
        abort_unless($job->user_id === $request->user()->id, 404);

        $job->delete();

        return $this->toast([
            'type' => 'success',
            'message' => 'Log aktivitas dihapus.',
        ]);
    }

    public function clear(Request $request)
    {
        $deleted = FileJob::where('user_id', $request->user()->id)
            ->whereIn('status', [JobStatus::Done, JobStatus::Failed])
            ->delete();

        return $this->toast([
            'type' => 'success',
            'message' => "{$deleted} riwayat log dibersihkan.",
        ]);
    }
}
