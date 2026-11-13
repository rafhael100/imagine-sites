# Imagine Sites — candidato integrado F1-286 (12/11/2026)

**PR draft para revisão. Sem merge/deploy, sem clientes reais e sem despesas.**

## Base e origem

- Base `dev`: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`; `main` segue `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`.
- Origem F1-286: 1.243 commits à frente de `dev` e zero atrás antes desta documentação.
- `dev/main` não tinham proteção administrativa ativa (issue #281); dez gates continuam `BLOCKED`.

## Entregas deste ciclo

- F1-276–281 (#325–#331): bootstrap Chromium F1-59, retry T0/432h, leitura conservadora do CRM 202/409/429, contexto idempotente e revalidação de vínculo.
- F1-282–284 (#332–#334): link “Pular para o conteúdo”, revisão com novo SHA e prova de Tab/Enter no Chromium.
- F1-285 (#336): comparador privado Laravel de skip link com 24 variantes, pacote privado de quatro arquivos e controle rigoroso de rota.
- F1-286 (#337): startup Chromium da galeria de CTA F1-255 com `--disable-dev-shm-usage` e nova tentativa única **somente** se o primeiro erro for porta CDP indisponível e nenhum PNG tiver sido criado.

## Evidência anterior e bloqueio corrigido

- Candidato F1-280 #330 concluiu **110/110 workflows PASS**, F1-240 721 arquivos, 204 caminhos sensíveis e seis pacotes F1-241.
- Candidato F1-284 #335 executou matriz extensa, porém uma suíte Chrome F1-255 falhou antes da primeira captura (`browser debug port unavailable`). Portanto, #335 **não** está validado integralmente.
- F1-285 passou nos testes Laravel dedicados; F1-286 precisa concluir a execução Chrome no próprio SHA.

## Gate deste novo candidato

1. Rodar todo o CI PHP 8.3/8.4, Laravel, PostgreSQL 18, Chrome 36 PNGs, Worker, Docker e segurança no SHA exato desta branch.
2. Recalcular inventário F1-240 de **todo** o diff Git contra `dev` e gerar seis pacotes F1-241 com hashes.
3. Encaminhar caminhos sensíveis para revisão humana independente AppSec, DBA, Portal/CRM, frontend/acessibilidade e infra.
4. Exigir proteção real de `dev/main` (issue #281), revisões e dez gates de operação antes de qualquer merge ou uso real.

## Fronteira técnica

Sem captura de dados de clientes, envio para CRM, autorizações S2S reais, segredos, domínio, DNS, Cloudflare Worker deploy, VPS, custo, aceites ou alteração do evento ENTREGUE. Testes sintéticos não concedem autoridade.