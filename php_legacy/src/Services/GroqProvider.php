<?php
declare(strict_types=1);

/**
 * GroqProvider
 *
 * Groq uses the OpenAI-compatible chat-completions API, so this implementation
 * would need only a base_url swap to work against OpenAI too.
 *
 * Default model: llama-3.3-70b-versatile
 *   - Strong SQL generation
 *   - Fast inference (Groq's LPU)
 *   - Free tier available
 *
 * Configure via env:
 *   GROQ_API_KEY   (required)
 *   GROQ_MODEL     (optional, default: llama-3.3-70b-versatile)
 */
final class GroqProvider implements LLMProvider
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
    private const DEFAULT_MODEL = 'llama-3.3-70b-versatile';
    private const TIMEOUT_SECONDS = 30;

    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = $apiKey ?? getenv('GROQ_API_KEY') ?: '';
        $this->model  = $model  ?? getenv('GROQ_MODEL') ?: self::DEFAULT_MODEL;

        if ($this->apiKey === '') {
            throw new RuntimeException(
                'GROQ_API_KEY not configured. Set it in .env or config/ai_config.php'
            );
        }
    }

    public function complete(array $messages, array $options = []): string
    {
        $body = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? 0.1,
            'max_tokens'  => $options['max_tokens']  ?? 1500,
        ];

        // Force JSON mode when caller asks for it (Groq supports OpenAI-compatible response_format)
        if (!empty($options['json_mode'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Groq API request failed: {$curlErr}");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $msg = "HTTP {$httpCode}";
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $msg = $decoded['error']['message'];
            } elseif (!empty($response)) {
                $msg .= ': ' . strip_tags(substr($response, 0, 100));
            }
            throw new RuntimeException("Groq API error: {$msg}");
        }

        if (!is_array($decoded) || !isset($decoded['choices'][0]['message']['content'])) {
            throw new RuntimeException('Malformed Groq API response');
        }

        return $decoded['choices'][0]['message']['content'];
    }

    public function getModelName(): string
    {
        return $this->model;
    }
}
