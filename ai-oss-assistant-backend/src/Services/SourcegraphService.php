<?php

namespace AiOssAssistant\Services;

use AiOssAssistant\Config;
use RuntimeException;

class SourcegraphService
{
    private string $endpoint;
    private string $token;

    public function __construct(?string $endpoint = null, ?string $token = null)
    {
        $this->endpoint = rtrim($endpoint ?? Config::get('SOURCEGRAPH_ENDPOINT', 'https://sourcegraph.com'), '/');
        $this->token    = $token ?? Config::get('SOURCEGRAPH_TOKEN', '');
    }

    /**
     * Executes a GraphQL query against the Sourcegraph API using cURL.
     */
    public function query(string $query, array $variables = []): array
    {
        $url = $this->endpoint . '/.api/graphql';

        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if (!empty($this->token)) {
            $headers[] = 'Authorization: token ' . $this->token;
        }

        $payload = json_encode([
            'query'     => $query,
            'variables' => $variables,
        ]);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("Sourcegraph cURL Error: " . $error);
        }

        $decoded = json_decode($response, true);
        if ($status >= 400 || isset($decoded['errors'])) {
            $msg = $decoded['errors'][0]['message'] ?? "HTTP Status {$status}";
            throw new RuntimeException("Sourcegraph API Error: " . $msg);
        }

        return $decoded['data'] ?? [];
    }

    /**
     * Perform code search over a specific repository
     */
    public function searchCode(string $repoName, string $searchTerm): array
    {
        $query = '
            query SearchRepo($query: String!) {
                search(query: $query, version: V2) {
                    results {
                        results {
                            ... on FileMatch {
                                file { path }
                                lineMatches {
                                    lineNumber
                                    offsetAndLengths
                                    preview
                                }
                            }
                        }
                    }
                }
            }
        ';

        $searchQuery = "repo:^github.com/{$repoName}$ {$searchTerm}";
        
        try {
            return $this->query($query, ['query' => $searchQuery]);
        } catch (RuntimeException $e) {
            // Fallback for offline/unauthenticated mock mode in tests/dev
            return [
                'fallback' => true,
                'message'  => $e->getMessage(),
                'results'  => [],
            ];
        }
    }
}
