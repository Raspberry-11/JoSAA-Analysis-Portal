"""
Single API dispatcher — mirrors api.php's ?action= routing.
All POST endpoints are @csrf_exempt since this is a stateless JSON API.
"""
import json
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_http_methods

from analytics.views import ok, error, parse_body
from analytics.services.allotment_queries import AllotmentQueries
from analytics.services.export_service import ExportService
from analytics.services.groq_provider import GroqProvider
from analytics.services.rag_service import RagService
from analytics.services.nl_query_service import NLQueryService
from analytics.services.predictor_service import PredictorService


@csrf_exempt
def dispatch(request):
    """
    Main API dispatcher.  Accepts ?action=<name> for all endpoints.
    GET and POST both routed here — individual handlers check the method.
    """
    action = request.GET.get('action', '')

    try:
        # ── Export routes (return files/HTML directly)
        if action == 'export_csv':
            f = json.loads(request.GET.get('f', '{}') or '{}')
            rows = AllotmentQueries.get_filtered_rows(f, 20000)
            return ExportService.to_csv(rows)

        if action == 'export_pdf':
            f = json.loads(request.GET.get('f', '{}') or '{}')
            rows = AllotmentQueries.get_filtered_rows(f, 20000)
            return ExportService.to_printable_html(rows)

        if action == 'ai_export_pdf':
            payload = parse_body(request)
            return ExportService.ai_report_html(payload)

        # ── AI routes
        if action.startswith('ai_'):
            llm = GroqProvider()
            rag = RagService()
            nlq = NLQueryService(llm, rag)

            if action == 'ai_ask':
                payload = parse_body(request)
                question = payload.get('question', '').strip()
                if not question:
                    return error('Question is required', 400)
                if len(question) > 500:
                    return error('Question too long (max 500 chars)', 400)
                conversation = payload.get('conversation', [])
                if len(conversation) > 20:
                    conversation = conversation[-20:]
                data = nlq.ask(question, conversation)
                return ok(data)

            if action == 'ai_history':
                return ok(nlq.recent_history())

            if action == 'ai_rate':
                payload = parse_body(request)
                cache_key = payload.get('cache_key', '')
                rating = payload.get('rating', '')
                if not cache_key:
                    return error('cache_key is required', 400)
                if rating == 'bad':
                    nlq.delete_cache(cache_key)
                    return ok({'message': 'Response removed from cache.'})
                return ok({'message': 'Rating recorded.'})

            return error(f'Unknown AI action: {action}', 400)

        # ── Predictor routes
        if action == 'predictor_options' or action.startswith('predict_'):
            predictor = PredictorService()

            if action == 'predictor_options':
                return ok(predictor.get_dropdown_options())

            payload = parse_body(request)

            if action == 'predict_by_rank':
                rank = int(payload.get('rank', 0))
                if not (1 <= rank <= 1_500_000):
                    return error('rank must be between 1 and 1,500,000', 400)
                seat_type = payload.get('seat_type', 'OPEN') or 'OPEN'
                gender = payload.get('gender', 'Gender-Neutral') or 'Gender-Neutral'
                min_year = payload.get('min_year')
                data = predictor.predict_by_rank(
                    rank, seat_type, gender,
                    int(min_year) if min_year else None
                )
                return ok(data)

            if action == 'predict_for_preference':
                rank = int(payload.get('rank', 0))
                iit_id = int(payload.get('iit_id', 0))
                branch_id = int(payload.get('branch_id', 0))
                if not rank or not iit_id or not branch_id:
                    return error('rank, iit_id, and branch_id are required', 400)
                seat_type = payload.get('seat_type', 'OPEN') or 'OPEN'
                gender = payload.get('gender', 'Gender-Neutral') or 'Gender-Neutral'
                data = predictor.predict_for_preference(rank, iit_id, branch_id, seat_type, gender)
                return ok(data)

            return error(f'Unknown predictor action: {action}', 400)

        # ── Analytics routes
        analytics_map = {
            'filters': lambda p: AllotmentQueries.get_filter_options(),
            'rows':    lambda p: AllotmentQueries.get_filtered_rows(p),
            'q1_cse_trend':  lambda p: AllotmentQueries.cse_trend_top_iits(),
            'q2_toughest':   lambda p: AllotmentQueries.toughest_branches(),
            'q3_gender':     lambda p: AllotmentQueries.gender_supernumerary_impact(),
            'q4_newage':     lambda p: AllotmentQueries.new_age_vs_core(),
            'q5_hierarchy':  lambda p: AllotmentQueries.iit_preference_ranking(),
            'q6_round_drop': lambda p: AllotmentQueries.round_wise_drop(),
            'q7_tradeoff':   lambda p: AllotmentQueries.branch_vs_iit_tradeoff(),
            'q8_category':   lambda p: AllotmentQueries.category_cutoff_gaps(),
            'q9_volatility': lambda p: AllotmentQueries.highest_volatility(),
            'q10_top100':    lambda p: AllotmentQueries.top100_monopoly(),
        }

        if action in analytics_map:
            payload = parse_body(request) if request.method == 'POST' else {}
            data = analytics_map[action](payload)
            return ok(data)

        return error(f'Unknown action: {action}', 400)

    except ValueError as e:
        return error(str(e), 400)
    except Exception as e:
        return error(f'Server error: {e}', 500)
