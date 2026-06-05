from django.urls import path
from analytics.views import pages
from analytics.views.api import dispatch

urlpatterns = [
    # ── Page views
    path('',            pages.index,      name='index'),
    path('dashboard/',  pages.dashboard,  name='dashboard'),
    path('ai/',         pages.ai_chat,    name='ai_chat'),
    path('predictor/',  pages.predictor,  name='predictor'),
    path('preference/', pages.preference, name='preference'),

    # ── API dispatcher (handles all ?action= routes, with or without trailing slash)
    path('api/', dispatch, name='api'),
    path('api',  dispatch, name='api_noslash'),
]
