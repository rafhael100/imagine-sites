# F1-234 — HTTP real no CI prova que rotas `/_lab/sites/v1/*` não são públicas

## Propósito

A F1-233 instalou um middleware global de negação por requisição, que não depende apenas do código de registro de `routes/web.php`. O teste F1-233 simula a reutilização do roteador no mesmo processo PHP CLI. A F1-234 acrescenta uma prova com **servidor HTTP real** (PHP SAPI `cli-server`) em `127.0.0.1:18091` no GitHub Actions, sem usar VPS, Nginx, Cloudflare ou rede pública.

## Execução exclusivamente descartável

O script Python `ci/f234_noncli_lab_http_smoke.py` exige GitHub Actions, `APP_ENV=testing`, flag específica `SITES_F234_LOCAL_HTTP_LAB=1` e vendor Laravel instalado. Ele:

1. Tenta compilar um cache Laravel **com os flags sintéticos ativados**. Se o Laravel suportar as closures, exige que o cache realmente contenha a rota de laboratório. Se recusar expressamente serializar closures, comprova apenas o cenário sem cache e registra essa limitação.
2. Inicia `php -S` com binding **apenas loopback**, mantendo todos os flags sintéticos ativos para que a barreira HTTP seja testada no cenário mais permissivo possível.
3. Exige `GET /up = 200` e `/_lab/sites/v1/create-project` (POST), review (GET), SEO review (GET), rota futura arbitrária (GET) e `/api/sites/publish` (POST) em **404**.
4. Desativa o processo, limpa o arquivo de route cache e falha se algum recurso temporário permanecer. As chamadas usam `urllib` sem proxy e apenas URLs locais fixas.
5. Não imprime HTML, corpo de resposta, informações de ambiente ou logs PHP. O resultado inclui apenas estados PASS/FAIL.

O workflow Laravel matriz PHP 8.3/8.4 executa a prova antes de gerar prévias privadas. **Não é** um teste FPM/Nginx, validação de proxy, identidade Portal, autorização de cliente ou certificação de homologação. `dev`, `main` e todos os gates de produção permanecem inalterados; nenhum deploy ou serviço externo é feito.
