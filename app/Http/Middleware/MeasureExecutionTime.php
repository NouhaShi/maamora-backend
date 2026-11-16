<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeasureExecutionTime
{
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        // Enable query log to count queries and measure total DB time
        DB::enableQueryLog();

        $response = $next($request);

        $totalTime = round((microtime(true) - $start) * 1000, 2);
        $queries = DB::getQueryLog();
        $dbTime = round(collect($queries)->sum('time'), 2);
        $queryCount = count($queries);

        // Attach performance metrics to response headers
        $response->headers->set('X-Total-Time', $totalTime . ' ms');
        $response->headers->set('X-DB-Time', $dbTime . ' ms');
        $response->headers->set('X-DB-Queries', $queryCount);

        return $response;
    }
}