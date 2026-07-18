<?php

declare(strict_types=1);

namespace SthAI\Model;

/**
 * Reranking models served by the platform.
 */
final class RerankingModel
{
    public const QWEN_3_VL_8B = 'Qwen/Qwen3-VL-Reranker-8B';

    private function __construct()
    {
    }
}
