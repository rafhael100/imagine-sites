## F1-259 — Não expor identificadores legíveis na evidência de CTA

- O registro de comparação `SyntheticSecondaryCtaChangeEvidenceV1` passa ao esquema v2, com `scopeCorrelationSha256` em vez de tenant/site legíveis.
- Os identificadores de release anterior e posterior deixam de aparecer em claro; a auditoria conserva somente seus SHA-256 e os hashes dos HTMLs.
- O comparador de revisão permanece consistente: a evidência é reconstituída dos drafts privados originais, não confiando em hash fornecido pelo usuário.
- Os testes F1-256 passam a exigir esquema v2, hash de escopo determinístico e ausência dos identificadores originais no registro serializado.

**Nota:** hash simples é pseudonimização e não anonimização, assinatura ou autorização. O dado de origem continua privado e não deve ser exportado para logs ou GitHub. Sem cliente real, gasto, deploy, alteração de `dev/main` ou liberação dos dez gates BLOCKED.