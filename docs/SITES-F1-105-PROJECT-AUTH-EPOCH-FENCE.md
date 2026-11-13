# F1-105 — Barreira de revisão atual da autorização Portal → Sites

## Objetivo

O snapshot candidato F1-102 já tinha validade máxima de 30 segundos, status e vínculo a pedido, empresa, contrato, operação, produto e chave idempotente. Entretanto, um snapshot `eligible` ainda não expirado poderia ser reutilizado após o Portal mudar a revisão da autorização ou registrar revogação, caso a fonte do snapshot devolvesse uma cópia anterior.

## Mudança

`SnapshotBoundPortalProjectAuthorityGateway` exige agora **duas evidências**:

1. Snapshot candidato `eligible`, ainda válido e vinculado ao `SiteProjectCommand` exato.
2. Revisão corrente da autorização (`CurrentPortalProjectAuthorizationEpochSource::currentEpochFor`) para a mesma empresa/pedido, obrigatoriamente um inteiro positivo **igual** a `snapshot.authorizationRevision`.

Se a segunda fonte retornar `null`, erro, zero, revisão maior ou menor, a decisão nega. A implementação padrão da segunda fonte (`DenyingCurrentPortalProjectAuthorizationEpochSource`) sempre retorna `null`, preservando a postura default DENY. Não existe cache de autorização no gateway.

A igualdade de revisão não permite aprovar um snapshot `revoked` ou `suspended`, mesmo que os números coincidam.

## Testes

- `tests/f102-portal-project-snapshot.php`: fonte ausente, revisão futura de revogação, revisão regressiva, falha/ausência, zero, snapshot novo coerente e revogação explícita.
- `tests/f94-pg-authorized-project-admission.php`: vincula a fonte de revisão fictícia à admissão transacional de projetos em PostgreSQL 18 efêmero. Uma mudança de revisão bloqueia o replay de um snapshot antigo `eligible`, mesmo antes do fim de seu TTL; uma indisponibilidade da fonte também nega sem mutar registros.

Os testes são executados nas matrizes PHP 8.3 e 8.4 já existentes. Sem qualquer alteração de migração ou endpoint.

## Limitações críticas

As duas fontes usadas aqui são **fictícias e em memória**. Se ambas devolverem informações antigas, a comparação não detecta uma revogação mais recente. Apenas uma origem confiável e autenticada, com revisão monotônica e leitura atual, pode sustentar decisões operacionais. A segunda consulta também não elimina revogação posterior, corrida de tempo, SQL arbitrário ou a falta de snapshot atômico dos arquivos.

Esta etapa permanece estritamente sintética/CLI/testing. Sem autenticação real do Portal, cliente, assinatura, aceite, publicação, CRM, VPS, Cloudflare, merge ou novos custos. Os 10 gates operacionais continuam `BLOCKED`.
