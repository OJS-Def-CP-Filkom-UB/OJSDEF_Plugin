<?php

class ContentInjectionDetector
{
    /** @var object */
    private $plugin;

    /** @var array<string, string> Regex patterns injeksi konten ilegal */
    private $patterns = [
        'gambling_keyword' =>
            '/\b(bet365|sbobet|togel|slot[\s_-]?gacor|judi[\s_-]?online|poker[\s_-]?online|casino|pragmatic|maxwin|scatter[\s_-]?hitam|bandar[\s_-]?bola|agen[\s_-]?slot|bonus[\s_-]?new[\s_-]?member)\b/i',

        'hidden_iframe' =>
            '/<iframe[^>]*(display\s*:\s*none|visibility\s*:\s*hidden|width\s*=\s*["\']?\s*0\s*["\']?)[^>]*>/i',

        'js_redirect' =>
            '/window\s*\.\s*location(\s*\.\s*href)?\s*=|document\s*\.\s*location\s*=\s*["\'][^"\']*["\']\s*;/i',

        'base64_eval' =>
            '/\beval\s*\(\s*(base64_decode|unescape|atob)\s*\(/i',

        'phishing_tld' =>
            '/https?:\/\/[^\s"\'<>\)]+\.(xyz|top|click|loan|gq|ml|cf|ga)(\/[^\s"\'<>\)]*)?[\s"\'<>\)]/i',
    ];

    /** @var string[] Fields artikel yang dicek */
    private $fieldsToCheck = ['abstract', 'title', 'coverage'];

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Scan seluruh artikel OJS untuk injeksi konten ilegal.
     *
     * @return array {
     *   total_scanned: int,
     *   affected_count: int,
     *   detections: array
     * }
     */
    public function scan(): array
    {
        $detections   = [];
        $totalScanned = 0;

        if (!class_exists('DAORegistry')) {
            return ['total_scanned' => 0, 'affected_count' => 0, 'detections' => []];
        }

        try {
            // OJS 3.4.x+: SubmissionDAO; OJS 3.3.x: ArticleDAO
            $daoName = class_exists('SubmissionDAO') ? 'SubmissionDAO' : 'ArticleDAO';
            $dao     = \DAORegistry::getDAO($daoName);
            if (!$dao) {
                return ['total_scanned' => 0, 'affected_count' => 0,
                        'detections' => [], 'error' => $daoName . ' not available'];
            }

            $submissions = $dao->getAll(true);
            while ($submission = $submissions->next()) {
                $totalScanned++;
                $detections = array_merge($detections, $this->_scanSubmission($submission));
            }
        } catch (\Throwable $e) {
            return ['total_scanned' => $totalScanned, 'affected_count' => 0,
                    'detections' => [], 'error' => $e->getMessage()];
        }

        $affectedIds = array_unique(array_column($detections, 'submission_id'));

        return [
            'total_scanned'  => $totalScanned,
            'affected_count' => count($affectedIds),
            'detections'     => $detections,
        ];
    }

    /**
     * Digunakan HANYA di unit test — deteksi pattern pada string bebas.
     * @return string[] List pattern name yang cocok
     */
    public function testDetect(string $text): array
    {
        $matched = [];
        foreach ($this->patterns as $name => $regex) {
            if (preg_match($regex, $text)) {
                $matched[] = $name;
            }
        }
        return $matched;
    }

    private function _scanSubmission($submission): array
    {
        $detections = [];
        $subId      = $submission->getId();

        foreach ($this->fieldsToCheck as $field) {
            $content = $this->_getField($submission, $field);
            if (empty($content)) continue;

            foreach ($this->patterns as $patternName => $regex) {
                if (preg_match($regex, $content, $matches)) {
                    $detections[] = [
                        'submission_id' => $subId,
                        'field'         => $field,
                        'pattern'       => $patternName,
                        'excerpt'       => substr($matches[0], 0, 100),
                    ];
                }
            }
        }
        return $detections;
    }

    private function _getField($submission, string $field): string
    {
        $methodMap = [
            'abstract' => 'getAbstract',
            'title'    => 'getTitle',
            'coverage' => 'getCoverage',
        ];
        $method = $methodMap[$field] ?? null;
        if (!$method || !method_exists($submission, $method)) return '';
        $value = $submission->$method();
        if (is_array($value)) return implode(' ', array_values($value));
        return (string) $value;
    }
}
