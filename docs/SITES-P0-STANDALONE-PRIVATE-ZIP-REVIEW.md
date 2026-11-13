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
