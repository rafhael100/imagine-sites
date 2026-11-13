# P0 — ZIP de prévias sintéticas e recuperação local, isolados para revisão

Este PR reduz uma parte do desenvolvimento do Imagine Sites a **oito scripts Python e um workflow**, diretamente sobre `dev`. Não depende de Laravel, PostgreSQL, Portal, CRM ou Cloudflare. É um subconjunto da implementação proposta em #410/#416, para revisão técnica mais rápida.

## Fluxo examinado

1. **F1-307/308/309/310:** ler arquivos privados por descritores seguros, limites de bytes, evitar symlink/hardlink/FIFO e verificar QA estrutural fictício.
2. **F1-329/332–335:** gerar e inspecionar um ZIP canônico de apenas `index.html` e `release-manifest.json`, confere dois SHA-256 de entrada e identificadores de tenant/site/release fornecidos independentemente; diretório ZIP deve ser 0700, arquivo ZIP 0600, sem sobrescrita e com releitura exata pós-gravação.
3. **F1-330/331:** recuperar somente em novo diretório de laboratório com flags de teste, utilizando staging privado e `renameat2(RENAME_NOREPLACE)`, fsync e readback, nunca para uma instalação existente.

Testes adversariais simulam manifesto com autorização falsa, hashes recalculados de outro tenant, ZIP slip, entradas duplicadas, links, corrida de destino, escrita zero, corrupção silenciosa e pasta compartilhada. Tudo usa dados fictícios em diretórios temporários.

A validação do PR é o workflow offline `.github/workflows/sites-p0-private-archive-offline.yml`, executado em Ubuntu 24.04, sem segredos, acesso à rede do projeto ou banco. O teste Laravel F1-24, presente no candidato grande #416, continua necessário para comprovar o roundtrip integrado com o motor Onepage/Landing.

**Importante:** esta extração **não é** um backup real de clientes, não cifra arquivos, não autentica os pins de identidade ou digest, não recupera PostgreSQL/outbox, não restaura dados de empresas nem autoriza publicação. Os métodos de laboratório indicam expressamente escopo não autenticado e restauração não operacional. O usuário precisa de revisão AppSec independente; as proteções administrativas #281 e os dez critérios operacionais BLOCKED permanecem. Nenhum merge, deploy ou serviço real foi modificado.

Este recorte permite uma revisão pequena de integridade e retenção em memória, sem aprovar simultaneamente os mais de mil commits acumulados no motor de Sites.

## Sincronização F1-336/337

O PR isolado acompanha também a verificação estrita da serialização canônica do manifesto JSON (rejeita JSON compacto, chave Unicode escapada e falta de newline, mesmo com hashes recomputados) e a validação do UID efetivo Unix e modo 0600 de arquivos ZIP/fonte sob pastas 0700. A suíte offline inclui casos adversariais correspondentes. O objetivo é manter este recorte de revisão pequeno **sem divergir do candidato integrado**. Ainda não oferece criptografia, assinaturas, autenticação de tenant ou backup de clientes reais.

## F1-338 — ZIP fechado antes de receber o nome final

O recorte independente também recebeu F1-338: o backup ZIP é escrito em staging privado com nome aleatório e modo 0600; a exportação reabre o staging, compara integralmente os bytes gravados e só então executa `renameat2(RENAME_NOREPLACE)` para criar o nome definitivo sem possibilidade de sobrescrever um destino concorrente. Testes novos em `ci/f338_atomic_zip_selftest.py` simulam falha de I/O, criação simultânea do nome de destino, leitura após promoção adulterada e nomes indevidos. A matriz offline do PR cobre F1-338 sem alterar produção. Não é armazenamento remoto nem backup de clientes.
