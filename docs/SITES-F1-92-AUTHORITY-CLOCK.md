# F1-92 — Relógio único para validação de envelope e autoridade Portal

## Problema identificado em revisão de código

`SyntheticReviewHandoffEpochEnvelope::verify` recebia o instante `$now` fornecido por um verificador confiável para examinar `issuedAt`, `expiresAt` e a janela de tolerância do envelope. Entretanto, ao validar `CurrentPortalReviewSnapshot`, a mesma função chamava `time()` diretamente. Isso criava **duas referências temporais no mesmo caminho de decisão**, produzindo inconsistências de validade em testes determinísticos e dificultando auditorias de expiração/revogação.

## Correção

O verificador passa o mesmo `$now` para `SnapshotReviewBindingPolicy::allows`. O formato assinado, o conteúdo do HMAC, a política de negação padrão, a checagem do epoch de autorização e a proteção contra replay permanecem intactos. O instante `$now` deve ser sempre definido por código confiável; não é um campo enviado pelo cliente ou pela IA.

O teste F1-50 agora inclui regressões explícitas com relógio fixo e comprova que: envelope e snapshot podem ser avaliados no mesmo instante; snapshot expirado falha embora o envelope ainda esteja vigente; `checkedAt` além da tolerância falha; epoch revogado falha; em todas as negativas, o nonce não é consumido. O teste anterior de revogação, reativação e tenant cruzado permanece.

## Limites

Esta é uma correção de consistência **em uma interface sintética de laboratório**, não a implantação de IAM do Portal. Um relógio confiável em produção exige validação de infraestrutura, políticas de skew, autenticação da fonte, revogação corrente e fence transacional; o consumidor não pode fornecer o instante arbitrariamente via HTTP. O código ainda não gera aceite oficial, publicação ou entrega. Todos os dez gates de `config/operational-readiness.json` permanecem `BLOCKED`.
