export default function handler(req, res) {
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.status(200).send(`
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <title>ONPE Live Bridge - API Serverless</title>
      <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; background: #0f172a; color: #f8fafc; }
        .card { background: #1e293b; border-radius: 12px; padding: 24px; border: 1px solid #334155; }
        h1 { color: #38bdf8; margin-top: 0; font-size: 20px; }
        code { background: #0f172a; padding: 4px 8px; border-radius: 6px; color: #34d399; font-family: monospace; font-size: 14px; }
        .badge { background: #10b981; color: white; font-size: 11px; padding: 2px 8px; border-radius: 9999px; font-weight: bold; }
        p { color: #94a3b8; font-size: 14px; line-height: 1.6; }
      </style>
    </head>
    <body>
      <div class="card">
        <span class="badge">ACTIVO / ONLINE</span>
        <h1>ONPE Live Bridge Microservice</h1>
        <p>Servicio serverless de consulta en vivo a la plataforma oficial de la ONPE sin dependencias locales.</p>
        <p><strong>Uso:</strong></p>
        <p><code>GET /api/onpe/21883824</code></p>
      </div>
    </body>
    </html>
  `);
}
