import os
import httpx


class GroqProvider:
    ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions'
    DEFAULT_MODEL = 'llama-3.3-70b-versatile'
    TIMEOUT = 30.0

    def __init__(self, api_key: str = None, model: str = None):
        self.api_key = api_key or os.environ.get('GROQ_API_KEY', '')
        self.model = model or os.environ.get('GROQ_MODEL', self.DEFAULT_MODEL)
        if not self.api_key:
            raise RuntimeError('GROQ_API_KEY not configured')

    def complete(self, messages: list, options: dict = None) -> str:
        options = options or {}
        body = {
            'model': self.model,
            'messages': messages,
            'temperature': options.get('temperature', 0.1),
            'max_tokens': options.get('max_tokens', 1500),
        }
        if options.get('json_mode'):
            body['response_format'] = {'type': 'json_object'}

        headers = {
            'Content-Type': 'application/json',
            'Authorization': f'Bearer {self.api_key}',
        }

        with httpx.Client(timeout=self.TIMEOUT) as client:
            resp = client.post(self.ENDPOINT, json=body, headers=headers)

        if resp.status_code >= 400:
            try:
                err_msg = resp.json()['error']['message']
            except Exception:
                err_msg = f'HTTP {resp.status_code}'
            raise RuntimeError(f'Groq API error: {err_msg}')

        data = resp.json()
        try:
            return data['choices'][0]['message']['content']
        except (KeyError, IndexError):
            raise RuntimeError('Malformed Groq API response')

    def get_model_name(self) -> str:
        return self.model
