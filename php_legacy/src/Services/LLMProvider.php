<?php
declare(strict_types=1);

/**
 * LLMProvider
 *
 * Provider-agnostic interface for chat-completion LLMs.
 * Implementations: GroqProvider (and future Anthropic/OpenAI/Gemini).
 *
 * The `complete()` method returns the assistant's text response.
 * Messages follow the standard [{"role": "system|user|assistant", "content": "..."}] format.
 */
interface LLMProvider
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options  (temperature, max_tokens, etc.)
     * @return string  Raw assistant text (JSON parsing is caller's job)
     */
    public function complete(array $messages, array $options = []): string;

    public function getModelName(): string;
}
