<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use Throwable;

/**
 * Liveness (the PHP app answers) is separate from readiness (its
 * dependencies answer). Neither response includes versions or topology.
 */
final class HealthController
{
    public function __construct(private readonly Connection $db, private readonly string $releaseId)
    {
    }

    public function live(Request $request): Response
    {
        return Response::json(['status' => 'ok'])->withHeader('Cache-Control', 'no-store');
    }

    public function ready(Request $request): Response
    {
        $database = 'ok';
        try {
            $this->db->fetchValue('SELECT 1');
        } catch (Throwable) {
            $database = 'unavailable';
        }
        $ok = $database === 'ok';

        return Response::json([
            'status' => $ok ? 'ok' : 'degraded',
            'release' => $this->releaseId,
            'checks' => ['database' => $database],
        ], $ok ? 200 : 503)->withHeader('Cache-Control', 'no-store');
    }
}
