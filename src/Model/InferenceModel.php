<?php

declare(strict_types=1);

namespace SthAI\Model;

/**
 * Inference (chat) models served by the platform. Class constants rather
 * than a native enum to keep the PHP 7.4 floor.
 */
final class InferenceModel
{
    public const QWEN_3_6_27B = 'Qwen/Qwen3.6-27B';

    private function __construct()
    {
    }
}
