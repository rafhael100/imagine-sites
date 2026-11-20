# Landing Page — diário privado de observações de revisão (candidato PG18)

Continuação **isolada** de Sites [#541](https://github.com/rafhael100/imagine-sites/pull/541) / [#542](https://github.com/rafhael100/imagine-sites/pull/542) → [#562](https://github.com/rafhael100/imagine-sites/pull/562), HEAD imutável `9381228723e024cc62874a4825d92d46c0339687`. Fonte Portal [#1019](https://github.com/rafhael100/imagine-portal/pull/1019) fixada em `4a5e1e06376188f85c90bee3d10056ab3ce2302a`.

## Implementado, somente laboratório

- Extensão SQL PostgreSQL 18 de **observações privadas**, vinculada ao artefato já custodiado, ao SHA-256 do ponteiro editorial, à versão/epoch, à empresa e ao site. Registro *append-only* e idempotente, sem acesso SQL direto aos papéis de aplicação.
- Revisor identificado por `session_user` PostgreSQL e cadastrado independentemente; não pode ser o mesmo login do autor da custódia. A API não aceita `actorId` do solicitante como evidência. Testes exercitam duas empresas com logins distintos e usuário não cadastrado.
- Recusa versão obsoleta, colisão da mesma operação, troca de decisão, tenant cruzado, role sem admissão, revisor desabilitado, site revogado, leitura privada e mutação do histórico.
- O adapter PHP revalida a fonte candidata do Portal antes e depois do INSERT transacional; revogação observada aciona rollback. A revogação após commit continua possível, exigindo nova verificação nos gates posteriores.
- Duas decisões **não autorizadoras**: `observations_recorded` e `changes_requested`. Nenhuma significa aprovação formal, liberação, publicação, aceite ou cobrança.

## Limites e gates

O cadastro de usuários no teste, as permissões, o briefing, a aprovação e a ligação Portal são **sintéticos**. O SQL não cria logins nem concede direitos de execução para produção. Não há migração Laravel homologada, revisor humano autenticado pelo Portal, autorização operacional, mTLS real, aceite do cliente, etapa de revisão formal aprovada, publicação ou evento ENTREGUE. O diário serve como prova técnica de isolamento e imutabilidade, **não é entrega operacional**. Dez gates continuam **BLOCKED**. Sem merge/deploy, main, VPS, credenciais, DNS/Cloudflare, Asaas ou custos.

CI específico: `.github/workflows/sites-landing-pg18-private-review-journal.yml`, PHP 8.3/8.4, PostgreSQL 18 descartável; registrar resultados e contadores somente depois de consultar a execução real na HEAD final.
