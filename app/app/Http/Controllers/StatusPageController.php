<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StatusPageService;
use Illuminate\Http\Response;

/**
 * Public, read-only status page at /status/{token} (no login). The token is the secret;
 * regenerating it in Settings → Status page invalidates old links.
 */
class StatusPageController extends Controller
{
    public function show(string $token, StatusPageService $statusPage): Response
    {
        $user = strlen($token) >= 32
            ? User::query()->where('status_page_token', $token)->first()
            : null;

        abort_if($user === null, 404);

        $jobs = $statusPage->jobs($user);

        return response()
            ->view('status-page', [
                'title' => $user->status_page_title ?: __('Backup status'),
                'jobs' => $jobs,
                'overall' => $statusPage->overall($jobs),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
