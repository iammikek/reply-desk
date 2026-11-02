<?php

namespace App\Desk\Notes;

use App\Desk\DeskException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class HubSpotClient
{
    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function send(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $pending = Http::withToken($this->token())
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'reply-desk'])
            ->connectTimeout(5)
            ->timeout(15)
            ->baseUrl($this->base());

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $response = match ($method) {
                'GET' => $pending->get($path, $query),
                'POST' => $pending->post($path, $body ?? []),
                'PUT' => $pending->put($path, $body ?? []),
                'PATCH' => $pending->patch($path, $body ?? []),
                default => throw new DeskException('Unsupported HubSpot request.'),
            };

            if ($response->status() === 429 && $attempt < 3) {
                $this->pause($response);

                continue;
            }

            if ($response->status() === 204) {
                return [];
            }

            if (! $response->successful()) {
                throw new DeskException($this->failure($response));
            }

            return $response->json() ?? [];
        }

        throw new DeskException('HubSpot is rate limited.');
    }

    public function upload(NoteFile $file): string
    {
        if (! is_file($file->path)) {
            throw new DeskException('That attachment could not be read.');
        }

        $handle = fopen($file->path, 'r');

        if ($handle === false) {
            throw new DeskException('That attachment could not be read.');
        }

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'reply-desk'])
            ->connectTimeout(5)
            ->timeout(30)
            ->attach('file', $handle, $file->name)
            ->post($this->base().'/files/v3/files', [
                'folderPath' => '/reply-desk',
                'options' => json_encode(['access' => 'PRIVATE']),
            ]);

        if (! $response->successful()) {
            throw new DeskException($this->failure($response));
        }

        $id = (string) ($response->json('id') ?? '');

        if ($id === '') {
            throw new DeskException('HubSpot did not return a file id.');
        }

        return $id;
    }

    private function pause(Response $response): void
    {
        $header = $response->header('Retry-After');
        $wait = is_numeric($header) ? (int) $header : 1;

        if ($wait > 0) {
            sleep(min($wait, 10));
        }
    }

    private function failure(Response $response): string
    {
        $body = str_replace($this->token(), '[redacted]', $response->body());

        return 'HubSpot '.$response->status().': '.mb_substr($body, 0, 500);
    }

    private function token(): string
    {
        return (string) config('reply-desk.token');
    }

    private function base(): string
    {
        return rtrim((string) config('reply-desk.api_base'), '/');
    }
}
