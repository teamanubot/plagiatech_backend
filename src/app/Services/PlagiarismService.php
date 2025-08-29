<?php

namespace App\Services;

use App\Models\Document;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpWord\IOFactory;
use Spatie\PdfToText\Pdf;

class PlagiarismService
{
    // Warna untuk highlight (maks 8 sumber teratas)
    private array $palette = [
        '#ffd54f', // kuning
        '#ff8a65', // oranye
        '#4fc3f7', // biru muda
        '#a5d6a7', // hijau
        '#ce93d8', // ungu
        '#90a4ae', // abu
        '#f48fb1', // pink
        '#ffccbc', // peach
    ];

    public function checkPlagiarism(Document $document): array
    {
        $raw = $this->extractText($document);
        $textNew = $this->normalize($raw);
        if ($textNew === '') return [];

        // batasi panjang agar cepat (opsional)
        $textNew = mb_substr($textNew, 0, 80000);

        $tokensNew = $this->tokenize($textNew);
        $N = count($tokensNew);
        if ($N < 30) return []; // terlalu pendek

        // Cari kandidat dari arXiv
        $query = $document->title ?: mb_substr($textNew, 0, 80);
        $candidates = $this->searchArxiv($query, 16);

        // Siapkan struktur untuk coverage & per-sumber
        $n = 3; // n-gram
        $docShinglePos = $this->shinglePositions($tokensNew, $n); // hash => [pos...]
        $coverageOwner = array_fill(0, $N, -1); // per token: index sumber yang “menang”, -1 jika none

        $sources = [];
        foreach ($candidates as $idx => $paper) {
            $abstract = $this->normalize($paper['summary'] ?? '');
            if ($abstract === '') continue;

            $tokensAbs = $this->tokenize($abstract);
            if (count($tokensAbs) < $n) continue;

            // cari irisan shingle dan tandai rentang token yang match
            $matches = $this->matchRanges($docShinglePos, $tokensAbs, $n);

            if (empty($matches)) continue;

            // hitung jaccard/cosine sebagai info pelengkap (bukan penentu final)
            $jaccard = $this->jaccard(
                $this->makeShingles($tokensNew, $n),
                $this->makeShingles($tokensAbs, $n)
            );
            $cosine  = $this->tfidfCosine($textNew, $abstract);

            // simpan sumber
            $sources[] = [
                'idx'       => count($sources), // index internal sumber
                'title'     => $paper['title'],
                'url'       => $paper['url'],
                'pdf_url'   => $paper['pdf_url'],
                'jaccard'   => is_float($jaccard) ? round($jaccard * 100, 2) : 0.0,
                'cosine'    => round($cosine * 100, 2),
                'ranges'    => $matches, // array [start,end] dalam indeks token dokumen
            ];
        }

        if (empty($sources)) {
            return []; // tidak ada yang cocok
        }

        // Urutkan sumber berdasar “luas cakupan” (banyaknya token yang dia cover)
        foreach ($sources as &$s) {
            $s['covered_tokens'] = $this->countTokensCovered($s['ranges']);
        }
        unset($s);

        usort($sources, fn($a,$b) => $b['covered_tokens'] <=> $a['covered_tokens']);

        // Ambil top K sumber untuk highlight (mis. 8), sisanya diabaikan agar PDF bersih
        $topK = array_slice($sources, 0, min(8, count($sources)));
        // Tandai warna per sumber
        foreach ($topK as $i => &$s) {
            $s['color'] = $this->palette[$i] ?? '#fff59d';
        } unset($s);

        // Bangun coverage owner: jika overlap antar sumber, pilih sumber “terkuat” (urutan sudah terbesar dulu)
        foreach ($topK as $i => $s) {
            foreach ($s['ranges'] as [$a, $b]) {
                for ($p = $a; $p <= $b && $p < $N; $p++) {
                    if ($coverageOwner[$p] === -1) {
                        $coverageOwner[$p] = $i; // milik sumber $i
                    }
                }
            }
        }

        // Hitung overall similarity = (token yang ter-cover oleh salah satu sumber) / total token
        $coveredTotal = 0;
        foreach ($coverageOwner as $own) if ($own !== -1) $coveredTotal++;
        $overall = $N > 0 ? round(100.0 * $coveredTotal / $N, 2) : 0.0;

        // Hitung kontribusi per-sumber (berapa token dia menang)
        foreach ($topK as $i => &$s) {
            $win = 0;
            foreach ($coverageOwner as $own) if ($own === $i) $win++;
            $s['contrib_pct'] = $N > 0 ? round(100.0 * $win / $N, 2) : 0.0;
            $s['win_tokens']  = $win;
            // buat cuplikan ringkas (maks 2) untuk ditampilkan
            $s['snippets'] = $this->makeSnippets($tokensNew, $s['ranges'], 18, 2);
        } unset($s);

        // Buat HTML bertanda warna (untuk dimasukkan ke PDF)
        $coloredHtml = $this->renderColoredHtml($tokensNew, $coverageOwner, $topK);

        // Kembalikan paket lengkap
        return [
            'overall'       => $overall,
            'sources'       => $topK,
            'colored_html'  => $coloredHtml,
            'token_count'   => $N,
            'covered_count' => $coveredTotal,
        ];
    }

    /* ================= Matching & highlight helpers ================= */

    // hash->positions (di dokumen user)
    private function shinglePositions(array $tokens, int $n): array
    {
        $N = count($tokens);
        $map = [];
        for ($i=0; $i <= $N-$n; $i++) {
            $h = (string) crc32(implode(' ', array_slice($tokens, $i, $n)));
            $map[$h][] = $i; // posisi awal shingle
        }
        return $map;
    }

    // Buat shingles set (untuk jaccard lama)
    private function makeShingles(array $tokens, int $n=3): array
    {
        $N = count($tokens);
        $set = [];
        for ($i=0; $i <= $N-$n; $i++) {
            $set[(string) crc32(implode(' ', array_slice($tokens, $i, $n)))] = true;
        }
        return $set;
    }

    // Temukan rentang-rentang token di dokumen user yang match dengan sumber (berdasar shingle)
    // Sederhana: semua posisi yang share shingle → tandai n token; lalu gabungkan kontigu
    private function matchRanges(array $docShinglePos, array $srcTokens, int $n): array
    {
        $M = count($srcTokens);
        $srcShingles = [];
        for ($j=0; $j <= $M-$n; $j++) {
            $h = (string) crc32(implode(' ', array_slice($srcTokens, $j, $n)));
            $srcShingles[$h] = true;
        }

        $marks = []; // [start,end] sebelum merge
        foreach ($srcShingles as $h => $_) {
            if (!isset($docShinglePos[$h])) continue;
            foreach ($docShinglePos[$h] as $pos) {
                $marks[] = [$pos, $pos + $n - 1];
            }
        }

        if (empty($marks)) return [];

        // merge intervals
        usort($marks, fn($a,$b) => $a[0] <=> $b[0]);
        $merged = [];
        [$curA, $curB] = $marks[0];
        for ($i=1; $i<count($marks); $i++) {
            [$a,$b] = $marks[$i];
            if ($a <= $curB+1) {
                $curB = max($curB, $b);
            } else {
                $merged[] = [$curA, $curB];
                [$curA,$curB] = [$a,$b];
            }
        }
        $merged[] = [$curA, $curB];
        return $merged;
    }

    private function countTokensCovered(array $ranges): int
    {
        $sum = 0;
        foreach ($ranges as [$a,$b]) $sum += ($b - $a + 1);
        return $sum;
    }

    // Buat 1-2 snippet per sumber (… kata sebelum/sesudah …)
    private function makeSnippets(array $tokens, array $ranges, int $context=15, int $max=2): array
    {
        $snips = [];
        foreach ($ranges as [$a,$b]) {
            $start = max(0, $a - $context);
            $end   = min(count($tokens)-1, $b + $context);
            $slice = array_slice($tokens, $start, $end - $start + 1);
            $snips[] = '… ' . htmlspecialchars(implode(' ', $slice)) . ' …';
            if (count($snips) >= $max) break;
        }
        return $snips;
    }

    // Render dokumen dengan span berwarna per-sumber
    private function renderColoredHtml(array $tokens, array $owner, array $sources): string
    {
        $out = '';
        $N = count($tokens);
        for ($i=0; $i<$N; $i++) {
            $tok = htmlspecialchars($tokens[$i]);
            $own = $owner[$i];
            if ($own === -1) {
                $out .= $tok . ' ';
            } else {
                $color = $sources[$own]['color'] ?? '#fff59d';
                $out .= '<span style="background:'.$color.'">'.$tok.'</span> ';
            }
        }
        return '<div style="line-height:1.7;font-size:12px;">'.$out.'</div>';
    }

    /* ===================== Ekstraksi teks ===================== */

    private function extractText(Document $document): string
    {
        $path = storage_path("app/public/{$document->filename}");
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        try {
            if ($ext === 'pdf') {
                $bin = env('PDF_TO_TEXT_BINARY'); // opsional
                $pdf = $bin ? new Pdf($bin) : new Pdf();
                return $pdf->setPdf($path)->text();
            }

            if (in_array($ext, ['doc', 'docx'])) {
                $phpWord = IOFactory::load($path);
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $el) {
                        // Handle Text elements
                        if ($el instanceof \PhpOffice\PhpWord\Element\Text) {
                            $text .= $el->getText() . "\n";
                        }
                        // Handle TextRun elements
                        elseif ($el instanceof \PhpOffice\PhpWord\Element\TextRun) {
                            foreach ($el->getElements() as $subEl) {
                                if ($subEl instanceof \PhpOffice\PhpWord\Element\Text) {
                                    $text .= $subEl->getText() . "\n";
                                }
                            }
                        }
                        // Handle Table elements
                        elseif ($el instanceof \PhpOffice\PhpWord\Element\Table) {
                            foreach ($el->getRows() as $row) {
                                foreach ($row->getCells() as $cell) {
                                    foreach ($cell->getElements() as $cellEl) {
                                        if ($cellEl instanceof \PhpOffice\PhpWord\Element\Text) {
                                            $text .= $cellEl->getText() . "\n";
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                return $text;
            }

            if ($ext === 'txt') {
                return file_get_contents($path) ?: '';
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return file_get_contents($path) ?: ''; // fallback kasar
    }

    private function normalize(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        $text = mb_strtolower($text);
        // ganti non-letter/digit jadi spasi
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        // rapikan spasi
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /* ===================== arXiv API ===================== */

    /**
     * Cari paper arXiv dalam lingkup Computer Science (cat:cs.*)
     * @return array<int, array{title:string, summary:string, url:string, pdf_url:?string}>
     */
    private function searchArxiv(string $query, int $maxResults = 10): array
    {
        $q = trim($query);
        if ($q === '') return [];

        // Cache 15 menit per query
        $cacheKey = 'arxiv:' . md5($q) . ":{$maxResults}";
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $client = new Client([
            'base_uri' => 'http://export.arxiv.org/api/',
            'timeout'  => 20,
            'headers'  => [
                // arXiv meminta User-Agent yang mengidentifikasi aplikasi & kontak
                'User-Agent' => 'PlagiarismChecker/1.0 (+mailto:you@example.com)',
                'Accept'     => 'application/atom+xml',
            ],
        ]);

        // Batasi ke CS: cat:cs*
        // Gabungkan dengan keyword judul (pakai all:)
        // Contoh: cat:cs* AND all:"neural networks"
        $searchQuery = sprintf('cat:cs* AND all:"%s"', $q);

        $resp = $client->get('query', [
            'query' => [
                'search_query' => $searchQuery,
                'start'        => 0,
                'max_results'  => $maxResults,
                'sortBy'       => 'relevance',
                'sortOrder'    => 'descending',
            ],
        ]);

        $xmlStr = (string) $resp->getBody();
        $xml = @simplexml_load_string($xmlStr);
        if ($xml === false) return [];

        // Namespace Atom
        $xml->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');

        $out = [];
        foreach ($xml->entry as $entry) {
            $title   = trim((string) $entry->title);
            $summary = trim((string) $entry->summary);
            $url     = $this->forceHttps(trim((string) $entry->id));
            $pdfUrl  = null;

            foreach ($entry->link as $link) {
                $href = (string) $link['href'];
                $type = (string) $link['type'];
                $titleAttr = (string) $link['title'];

                if ($titleAttr === 'pdf' || $type === 'application/pdf' || str_contains($href, '/pdf/')) {
                    $pdfUrl = $this->forceHttps($href);
                    break;
                }
            }

            $out[] = [
                'title'    => $title,
                'summary'  => $summary,
                'url'      => $this->forceHttps($url ?: ($pdfUrl ?? '')),
                'pdf_url'  => $pdfUrl ? $this->forceHttps($pdfUrl) : null,
            ];
        }

        Cache::put($cacheKey, $out, now()->addMinutes(15));
        return $out;
    }

    /* ===================== Similarity ===================== */

    private function tokenize(string $text): array
    {
        if ($text === '') return [];
        // sudah dinormalisasi; split by space
        $tokens = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $tokens ?: [];
    }

    /**
     * Buat set shingles (n-gram kata) -> gunakan hash untuk hemat memori
     * @return array<int,bool>  (pakai associative array sebagai "set")
     */

    private function jaccard(array $setA, array $setB): float
    {
        if (!$setA || !$setB) return 0.0;

        $intersect = 0;
        // iterasi set yang lebih kecil
        $small = (count($setA) < count($setB)) ? $setA : $setB;
        $large = ($small === $setA) ? $setB : $setA;

        foreach ($small as $k => $_) {
            if (isset($large[$k])) $intersect++;
        }

        $union = count($setA) + count($setB) - $intersect;
        return $union > 0 ? $intersect / $union : 0.0;
    }

    /**
     * Cosine similarity sederhana berbasis TF-IDF untuk 2 dokumen saja.
     * Karena hanya 2 dokumen, IDF tidak terlalu berarti; kita pakai log(TF)+L2 norm.
     */
    private function tfidfCosine(string $docA, string $docB): float
    {
        $ta = $this->tokenize($docA);
        $tb = $this->tokenize($docB);
        if (!$ta || !$tb) return 0.0;

        $fa = array_count_values($ta);
        $fb = array_count_values($tb);

        // union vocab
        $vocab = array_unique(array_merge(array_keys($fa), array_keys($fb)));

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($vocab as $term) {
            $wa = isset($fa[$term]) ? log(1 + $fa[$term]) : 0.0;
            $wb = isset($fb[$term]) ? log(1 + $fb[$term]) : 0.0;
            $dot += $wa * $wb;
            $na  += $wa * $wa;
            $nb  += $wb * $wb;
        }
        if ($na == 0.0 || $nb == 0.0) return 0.0;
        return $dot / (sqrt($na) * sqrt($nb));
    }

    private function forceHttps(string $url): string
    {
        // arXiv/doi dll → paksa https
        if (str_starts_with($url, 'http://')) {
            $url = 'https://' . substr($url, 7);
        }
        // beberapa feed kasih link 'http://export.arxiv.org/...'
        // ganti ke domain utama saat perlu
        $url = str_replace('export.arxiv.org', 'arxiv.org', $url);
        return $url;
    }
}
