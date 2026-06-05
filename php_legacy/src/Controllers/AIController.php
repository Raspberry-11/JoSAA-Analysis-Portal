<?php
declare(strict_types=1);

/**
 * AIController
 *
 * Handles the natural-language query endpoints.
 * Thin — orchestrates NLQueryService, handles errors distinctly for UI feedback.
 */
final class AIController
{
    public function __construct(private NLQueryService $service) {}

    /**
     * Action: ai_ask
     * Payload: {question: string, conversation?: [{role, content}, ...]}
     */
    public function ask(array $payload): array
    {
        $question     = trim((string) ($payload['question'] ?? ''));
        $conversation = $payload['conversation'] ?? [];

        if ($question === '') {
            throw new InvalidArgumentException('Question cannot be empty');
        }

        if (mb_strlen($question) > 500) {
            throw new InvalidArgumentException('Question too long (max 500 chars)');
        }

        if (!is_array($conversation)) {
            $conversation = [];
        }

        // Cap conversation history to last 6 messages (3 turns) — keeps tokens low
        if (count($conversation) > 6) {
            $conversation = array_slice($conversation, -6);
        }

        return $this->service->ask($question, $conversation);
    }

    public function history(): array
    {
        return $this->service->recentHistory(20);
    }

    /**
     * Action: ai_rate
     * Payload: {cache_key: string, rating: 'good'|'bad'}
     */
    public function rate(array $payload): array
    {
        $cacheKey = trim((string) ($payload['cache_key'] ?? ''));
        $rating   = trim((string) ($payload['rating'] ?? ''));

        if ($cacheKey === '' || !in_array($rating, ['good', 'bad'], true)) {
            throw new InvalidArgumentException('Invalid rating payload');
        }

        if ($rating === 'bad') {
            $this->service->deleteCache($cacheKey);
            return ['status' => 'deleted', 'message' => 'Bad response removed from cache'];
        }

        return ['status' => 'kept', 'message' => 'Response confirmed as good'];
    }
}
