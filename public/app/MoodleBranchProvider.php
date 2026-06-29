<?php
// Provides the Software Moodle™ branches that can be used by the installer.
namespace app;

/**
 * Class MoodleBranchProvider
 */
class MoodleBranchProvider {
    private const GITHUB_BRANCHES_URL = 'https://api.github.com/repos/moodle/moodle/branches';

    /**
     * Function getInstallBranches
     *
     * @param int $minimumversion
     * @param int $limit
     * @return array
     */
    public static function getInstallBranches(int $minimumversion = 502, int $limit = 4): array {
        $branches = [];

        foreach (self::fetchBranchNames() as $branchname) {
            if (!preg_match('/^MOODLE_(\d+)_STABLE$/', $branchname, $matches)) {
                continue;
            }

            $version = $matches[1];
            if ($version < $minimumversion) {
                continue;
            }

            $branches[$branchname] = [
                'name' => $branchname,
                'label' => $branchname,
                'version' => $version,
            ];
        }

        uasort($branches, static function (array $branch1, array $branch2): int {
            return $branch2["version"] <=> $branch1["version"];
        });

        return array_slice(array_values($branches), 0, $limit);
    }

    /**
     * @return string[]
     */
    private static function fetchBranchNames(): array {
        $branchnames = [];

        for ($page = 1; $page <= 10; $page++) {
            $payload = self::fetchUrl(self::GITHUB_BRANCHES_URL . '?per_page=100&page=' . $page);
            if ($payload == null) {
                break;
            }

            $items = json_decode($payload, true);
            if (!is_array($items)) {
                break;
            }

            foreach ($items as $item) {
                if (!empty($item["name"]) && is_string($item["name"])) {
                    $branchnames[] = $item["name"];
                }
            }

            if (count($items) < 100) {
                break;
            }
        }

        return array_values(array_unique($branchnames));
    }

    /**
     * Function fetchUrl
     *
     * @param string $url
     * @return string|null
     */
    private static function fetchUrl(string $url): ?string {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github+json',
                    'User-Agent: Moodle-Admin',
                ],
            ]);

            $response = curl_exec($curl);
            $statuscode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if (is_string($response) && $response != '' && $statuscode >= 200 && $statuscode < 300) {
                return $response;
            }

            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => implode("\r\n", [
                    'Accept: application/vnd.github+json',
                    'User-Agent: Moodle-Admin',
                ]),
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        return is_string($response) && $response != '' ? $response : null;
    }
}
