# STATUS-ATUAL — Imagine Sites Engine

**Referência:** 2026-11-09. F1-16 — simulador de publicação e reconciliação da versão observada.

F1-14 PR #15: contrato HMAC consumindo nonce com PDO/PostgreSQL descartável, falha fechada, dois PHPs e CI aprovado após ajuste de permissão sem ampliar grants.
F1-15 PR #16: transação de comando+outbox em PostgreSQL/RLS descartável, revogação e rollback atômico. CI aprovado após reconhecimento da política RLS correta.
F1-16: mock de ACK do fornecedor versus origem fictícia com HTML de versão exata; testes PHP 8.3/8.4 a conferir. Nenhum publish real.

O motor mantém cliente/contrato/aceite/ENTREGUE no Portal; Sites executa tarefas técnicas somente quando homologadas. Sem integração real Portal↔Sites, CRM, AI Agents/n8n, Cloudflare, domínio, backup, dados reais, merges ou deploy. P0/RF-PEND/RV-CT/AppSec permanecem abertos, rollback AGI-11-RB01 DESABILITADO.
