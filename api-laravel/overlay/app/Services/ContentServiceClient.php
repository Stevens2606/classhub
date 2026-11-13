<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Client for the Go content microservice. Only this API talks to it, using a shared internal key.
 */
class ContentServiceClient
{
    /** @throws \Illuminate\Http\Client\ConnectionException|\Illuminate\Http\Client\RequestException */
    public function materials(int $courseId): array
    {
        return $this->get('/materials', $courseId);
    }

    /** @throws \Illuminate\Http\Client\ConnectionException|\Illuminate\Http\Client\RequestException */
    public function tryouts(int $courseId): array
    {
        return $this->get('/tryouts', $courseId);
    }

    private function get(string $path, int $courseId): array
    {
        return Http::withHeaders(['X-Internal-Key' => (string) config('classhub.content_service.key')])
            ->acceptJson()
            ->timeout(config('classhub.content_service.timeout'))
            ->get(rtrim(config('classhub.content_service.url'), '/').$path, ['course_id' => $courseId])
            ->throw()
            ->json('data', []);
    }
}
