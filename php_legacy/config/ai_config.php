<?php
declare(strict_types=1);

/**
 * AI / LLM Configuration
 * ----------------------
 * Set your GROQ_API_KEY here, OR in a .env file, OR as an environment variable.
 * Precedence:  explicit setenv() here  >  .env  >  system environment
 *
 * Get a free Groq API key at: https://console.groq.com/keys
 */

// ─── CHOOSE ONE ─────────────────────────────────────────────────────────────

// Option A: hardcode (easiest for local XAMPP dev — DO NOT commit this file)
// putenv('GROQ_API_KEY=gsk_your_key_here');
// Option B: load from .env (recommended)
$envFile = __DIR__ . '/../.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
        if (getenv($k) === false) putenv("{$k}={$v}");
    }
}

// ─── Model selection ────────────────────────────────────────────────────────
// Groq's best models for SQL generation (as of 2025):
//   - llama-3.3-70b-versatile       (recommended, strong + free tier)
//   - llama-3.1-70b-versatile       (older but stable)
//   - qwen-2.5-32b                  (faster, smaller context)
//
// Override with:  putenv('GROQ_MODEL=...')
