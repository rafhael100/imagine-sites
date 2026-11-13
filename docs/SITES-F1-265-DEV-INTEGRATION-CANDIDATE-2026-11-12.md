# Imagine Sites — candidato de integração F1-265 (12/11/2026)

**DRAFT exclusivo para revisão; não mesclar em `dev` ou `main`, nem publicar em Cloudflare/VPS.**

## Escopo de integração

- Base `dev`: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`; `main`: `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`.
- Origem #308 (F1-265), descendente de #306 (F1-263). Comparação inicial com `dev`: **1.092 commits ahead, 0 behind** antes desta documentação.
- F1-264 / #307: Worker compatível Cloudflare com handler público permanentemente 503; preview somente `.invalid`, HTML real gerado por PHP e SHA-256, noindex/CSP, sem API externa. **5/5 workflows PASS**.
- F1-265 / #308: assinatura Ed25519 canônica de prévia, audiência local, validade máxima de 5 minutos, campos estritos e rejeição de alterações. Assinatura válida não concede autoridade. **6/6 workflows PASS**.
- Candidato anterior F1-263 / #306: **97/97 workflows PASS**; inventário F1-240 com 658 arquivos e 184 caminhos sensíveis em seis pacotes F1-241.

## Verificações obrigatórias no HEAD deste novo candidato

1. Executar **novamente** a auditoria F1-240 do diff Git completo, evitando o limite de 300 arquivos da API compare, e os seis pacotes de revisão F1-241 com hashes próprios.
2. Executar as matrizes PHP 8.3/8.4, Laravel, PostgreSQL 18, Docker, Chromium, contatos/CRM e Edge Node 22 nos bytes exatos desta nova branch.
3. Revisão independente AppSec sobre rotas, Worker/CSP, assinaturas, relógios, nonce, chaves, pacote/copy, banco/RLS, S2S Portal/CRM e CI; revisão DBA e de integração.
4. Configuração por administrador autorizado de regras reais em `dev` e `main` (ambos com `protected:false` na API; issue #281). Sem isso não promover código.
5. Manter os dez gates de `config/operational-readiness.json` `false` e `status=BLOCKED`; nenhuma evidência sintética ou de CI é aprovação de cliente ou de produção.

## Fronteiras explícitas

Nada de cliente real, formulário público, CRM ativo, Cloudflare Workers deploy, DNS/TLS, VPS, APIs pagas, tokens, assinatura operacional, logs com PII, retorno de lead ou publicação. O handler externo do Worker mantém resposta 503 mesmo que sejam fornecidas flags de ambiente. Para produção será necessário módulo separado com autenticação e autorização reais, ledger de nonce durável, backup, aceite Portal e observação independente do release.