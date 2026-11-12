# F1-269 — Bloqueio de repetição de nonce em laboratório

Para a prévia assinada Ed25519 do Imagine Sites, foi criado um registro **efêmero e local ao processo** que recusa a segunda utilização do mesmo nonce dentro do mesmo escopo de empresa/site, release e HTML.

- Somente chaves derivadas de hashes dos identificadores e de nonce UUIDv4 chegam ao registro, sem dados pessoais ou HTML.
- Assinatura Ed25519 canônica, audiência `.invalid`, tempo e SHA do HTML são conferidos antes da reserva.
- Uma mesma chave não pode ser reservada duas vezes; 12 solicitações concorrentes no mesmo runtime precisam produzir somente uma reserva.
- Até 256 entradas por instância; ao esgotar a capacidade, o registro nega novas reservas em vez de descartar uma entrada ainda válida.
- Expirados podem ser removidos; a assinatura também falha ao atingir `expiresAt`. Sem fonte de nonce configurada, a prévia assinada com o novo helper retorna 503.
- O `fetch()` público exportado do Worker permanece sempre 503, sem conexão Cloudflare ou meio de ativar publicação.

**Limite fundamental:** este registro não é durável, consistente entre instâncias, autenticado ou apropriado para produção. O futuro módulo operacional exigirá reserva atômica persistente sob autenticação S2S, TTL e política de retenção, revogação atual, chave protegida e aceite Portal. Nenhum teste em memória autoriza publicação. Os dez gates permanecem BLOCKED.