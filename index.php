<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Customs-Bridge — UNE Node 03 (TARIC & Customs Duties)</title>
  <style>
    body { background: #090d13; color: #c9d1d9; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; padding: 40px 20px; display: flex; justify-content: center; }
    .box { background: #0d1117; border: 1px solid #21262d; padding: 28px; border-radius: 8px; max-width: 680px; width: 100%; box-shadow: 0 4px 20px rgba(0,0,0,0.5); }
    h1 { color: #58a6ff; font-size: 20px; margin-bottom: 12px; }
    p { font-size: 13px; line-height: 1.6; margin-bottom: 16px; color: #8b949e; }
    ul { list-style: none; padding: 0; margin-bottom: 20px; font-size: 13px; }
    li { padding: 6px 0; border-top: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: space-between; }
    strong { color: #f0f6fc; }
    a { color: #38bdf8; text-decoration: none; }
    a:hover { text-decoration: underline; }
    .badge { background: rgba(35, 134, 54, 0.2); color: #3fb950; border: 1px solid #238636; padding: 2px 8px; border-radius: 12px; font-size: 11px; }
    .endpoint-box { background: #040608; border: 1px solid #30363d; border-radius: 6px; padding: 10px 14px; font-size: 12px; color: #79c0ff; margin-bottom: 16px; overflow-x: auto; }
  </style>
</head>
<body>
  <div class="box">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 12px;">
      <h1>UNE Node 03: Customs-Bridge</h1>
      <span class="badge">Operational</span>
    </div>
    <p>Deterministic EU Combined Nomenclature (TARIC) resolution engine & Common Customs Tariff (TDC) duty calculator with French import VAT liquidation.</p>

    <div class="endpoint-box">
      Streamable MCP: https://customs.une.education/mcp.php
    </div>

    <ul>
      <li><strong>Tools Available:</strong> <span><code>search_customs_nomenclature</code>, <code>resolve_hs_code</code>, <code>calculate_customs_duties</code></span></li>
      <li><strong>Tariff Framework:</strong> <span>EU Common Customs Tariff (MFN) + French CGI Art. 292</span></li>
      <li><strong>Cryptographic Seal:</strong> <span style="color:#10b981; font-weight:600;">SHA-256 Opposable State Proof (_meta)</span></li>
      <li><strong>Corpus Version:</strong> <span>TARIC-EU-2026.Q4 (DGDDI / EU TAXUD)</span></li>
      <li><strong>Carbon Efficiency:</strong> <span style="color:#10b981;">Rating A+ (Ultra-frugal, &lt;1ms)</span></li>
      <li><strong>Machine Specs:</strong> <span><a href="/openapi.json">openapi.json</a> &bull; <a href="/llms.txt">llms.txt</a></span></li>
      <li><strong>Official MCP Registry:</strong> <span>io.github.Une-education/customs-bridge</span></li>
      <li><strong>Smithery:</strong> <span><a href="https://smithery.ai/servers/ops-1k8b/customs-bridge" target="_blank">@ops-1k8b/customs-bridge</a></span></li>
      <li><strong>Admin Console:</strong> <span><a href="/admin.php">/admin.php</a></span></li>
    </ul>

    <p style="margin-top:24px; margin-bottom:0;"><a href="https://une.education">&larr; Return to UNE main portal</a></p>
  </div>
</body>
</html>