import json
from django.http import JsonResponse


def ok(data) -> JsonResponse:
    return JsonResponse({'status': 'ok', 'data': data}, json_dumps_params={'default': str})


def error(message: str, status: int = 400) -> JsonResponse:
    return JsonResponse({'status': 'error', 'message': message}, status=status)


def parse_body(request) -> dict:
    ct = request.content_type or ''
    if 'application/json' in ct:
        try:
            return json.loads(request.body)
        except (json.JSONDecodeError, ValueError):
            return {}
    return {}
