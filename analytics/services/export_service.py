import csv
import json
from io import StringIO
from datetime import datetime

from django.http import HttpResponse
from django.template.loader import render_to_string


class ExportService:

    @staticmethod
    def to_csv(rows: list, filename: str = None) -> HttpResponse:
        if not filename:
            filename = f'josaa_export_{datetime.now().strftime("%Y%m%d_%H%M%S")}.csv'

        response = HttpResponse(content_type='text/csv; charset=utf-8')
        response['Content-Disposition'] = f'attachment; filename="{filename}"'

        if rows:
            writer = csv.DictWriter(response, fieldnames=rows[0].keys())
            writer.writeheader()
            writer.writerows(rows)

        return response

    @staticmethod
    def to_printable_html(rows: list, title: str = 'JOSAA Filtered Report') -> HttpResponse:
        html = render_to_string('export_print.html', {'rows': rows, 'title': title})
        return HttpResponse(html, content_type='text/html; charset=utf-8')

    @staticmethod
    def ai_report_html(payload: dict) -> HttpResponse:
        html = render_to_string('export_ai_report.html', payload)
        return HttpResponse(html, content_type='text/html; charset=utf-8')
