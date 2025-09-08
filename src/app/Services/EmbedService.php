<?php

namespace App\Services;

class EmbedService
{
    public function embed(string $text): array
    {
        // Dummy embedding logic for demonstration
        return [0.1, 0.2, 0.3];
    }

    public function cosine(array $vecA, array $vecB): float
    {
        // Dummy cosine similarity logic for demonstration
        $dot = 0.0; $normA = 0.0; $normB = 0.0;
        $len = min(count($vecA), count($vecB));
        for ($i = 0; $i < $len; $i++) {
            $dot += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }
        if ($normA == 0.0 || $normB == 0.0) return 0.0;
        return $dot / (sqrt($normA) * sqrt($normB));
    }
}