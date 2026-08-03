<?php

namespace RadioChatBox\Controllers;

use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use Pramnos\Routing\OpenApiGenerator;

/**
 * Live API documentation: an OpenAPI 3 document generated from the attribute
 * routes, plus a tiny self-contained viewer. Always reflects the current routes
 * (no static regeneration step).
 */
final class ApiDocsController
{
    /** GET /api/openapi.json — the generated OpenAPI 3 document. */
    #[Route('/api/openapi.json', methods: 'GET', name: 'api.openapi')]
    public function spec(): Response
    {
        try {
            $doc = (new OpenApiGenerator(['title' => 'RadioChatBox API', 'version' => '1.0.0']))
                ->fromDirectory(dirname(__DIR__) . '/Controllers', 'RadioChatBox\\Controllers');
            return Response::json($doc);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ApiDocsController::spec failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to generate API docs'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** GET /api/docs — a minimal, dependency-free viewer for the OpenAPI document. */
    #[Route('/api/docs', methods: 'GET', name: 'api.docs')]
    public function viewer(): Response
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>RadioChatBox API</title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; margin: 0; background: #f9fafb; color: #111827; }
  header { background: #111827; color: #fff; padding: 16px 24px; }
  header h1 { margin: 0; font-size: 18px; }
  header small { color: #9ca3af; }
  main { max-width: 900px; margin: 0 auto; padding: 24px; }
  .op { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 14px; margin-bottom: 8px; display: flex; gap: 12px; align-items: baseline; }
  .m { font-weight: 700; font-size: 12px; padding: 2px 8px; border-radius: 4px; color: #fff; min-width: 52px; text-align: center; }
  .m.get { background: #2563eb; } .m.post { background: #059669; } .m.put { background: #d97706; }
  .m.delete { background: #dc2626; } .m.patch { background: #7c3aed; }
  .path { font-family: ui-monospace, Menlo, monospace; font-size: 13px; }
  .sum { color: #6b7280; font-size: 13px; margin-left: auto; }
  .grp { margin: 22px 0 8px; font-size: 13px; color: #374151; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
  #err { color: #dc2626; }
</style></head>
<body>
<header><h1>RadioChatBox API</h1> <small id="meta"></small></header>
<main><div id="out">Loading…</div></main>
<script>
(async () => {
  const out = document.getElementById('out');
  try {
    const res = await fetch('/api/openapi.json');
    const doc = await res.json();
    document.getElementById('meta').textContent = (doc.info ? doc.info.title + ' v' + doc.info.version : '');
    const ops = [];
    for (const [path, methods] of Object.entries(doc.paths || {})) {
      for (const [method, op] of Object.entries(methods)) {
        ops.push({ path, method: method.toUpperCase(), summary: (op && op.summary) || '' });
      }
    }
    ops.sort((a, b) => a.path.localeCompare(b.path) || a.method.localeCompare(b.method));
    const admin = ops.filter(o => o.path.includes('/admin/'));
    const pub = ops.filter(o => !o.path.includes('/admin/'));
    const esc = s => s.replace(/[&<>"]/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));
    const render = list => list.map(o =>
      `<div class="op"><span class="m ${o.method.toLowerCase()}">${o.method}</span>` +
      `<span class="path">${esc(o.path)}</span><span class="sum">${esc(o.summary)}</span></div>`).join('');
    out.innerHTML =
      `<div class="grp">Public (${pub.length})</div>` + render(pub) +
      `<div class="grp">Admin (${admin.length})</div>` + render(admin);
  } catch (e) {
    out.innerHTML = '<p id="err">Failed to load the API document.</p>';
  }
})();
</script>
</body></html>
HTML;

        return Response::make($html)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
