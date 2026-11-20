# Sites — bloquear reativação de aprovação Landing revogada pelo Portal

Entrega de segurança encadeada: Sites #541/#542 → Sites #558 (base imutável `e1eb991a12256e04d708bfe374837ec30c7d0981`) → Portal #1017 (HEAD `30f10691c6710e7b5effb220d0f4788c7f91914e`).

## Lacuna adversarial fechada em laboratório

A fonte PostgreSQL 18 do Portal #1015 considerava eventos **posteriores** ao evento de aprovação, mas admitia um registro indevido `revoked → approved` ou `superseded → approved` na mesma revisão, sem mudança de epoch. O Portal #1017 passou a considerar tais eventos **terminais em qualquer posição da sequência**. Somente nova versão/epoch, sujeita a autorização independente, pode ter nova aprovação.

Este teste cross-repo usa **classes reais candidatas** de reader/encoder Portal e decoder/readback Sites. Ele cria dados e eventos sintéticos em PostgreSQL 18 descartável, confirma aprovação inicial íntegra e isolada por tenant, rejeita reativação de versão revogada e supersedida, impede voltar à versão anterior e permite somente revisão nova. A projeção de admissão é deliberadamente fictícia, sem autoridade real. O CI fixa a SHA exata do Portal, verifica a HEAD após checkout, roda testes PHP 8.3/8.4 e mantém regressões da #558 e #557.

## NO-GO operacional

A fonte, os eventos e o ator de aprovação não pertencem ao ledger oficial do Portal. A role privilegiada está restrita ao CI efêmero; RLS operacional, aprovação autenticada, MFA, auditoria oficial, custody unificada, mTLS, pagamento e autorização de publicação continuam ausentes. O teste não resolve revogações que ocorrem após a última verificação nem custódia em transação única.

Não há geração de artefato Landing Page homologada, revisão formal, publicação, rollback operacional ou aceite. Dez gates continuam BLOCKED; branch e PR draft isoladas. Sem merge, deploy, alteração em main, VPS, credenciais, DNS/Cloudflare, Asaas, produção ou gastos.
