# F1-47 — Replay durável de propostas de revisão, com PostgreSQL descartável

Completa o ensaio F1-45/46 com **consumo de nonce entre duas conexões PDO independentes**. O laboratório usa PostgreSQL 18 efêmero do GitHub Actions; não utiliza dados, contas nem chaves reais.

O `LabReviewHandoffReplayStore` exige PHP CLI, `SITES_F47_PG_LAB=1`, banco de dados `sites_auth_replay_lab`, driver PostgreSQL e role restrita `sites_auth_runtime`. Seu único acesso de escrita é `INSERT ... ON CONFLICT DO NOTHING` na tabela `auth_replay.review_nonce_consumptions`. Políticas RLS, restrição de issuer `imagine-sites:review-handoff.v1`, relógio do banco e TTL máximo 120s impedem nonce inválido, expirado e duplo consumo.

A tabela é **separada** da já existente F1-12 (Portal→Sites). Não alarga as permissões do comando CreateProject anterior.

Os testes verificam sucesso com autoridade **fictícia** e HMAC válido, replay negado na mesma conexão e numa segunda conexão, revogação antes de consumir o nonce, revisão obsoleta, assinatura adulterada, issuer indevido, TTL, role privilegiada e ambiente sem flag. Verificam o número exato de linhas consumidas no banco, sem mudanças no fence da direção contrária.

**Limitações:** isto só prova replay durável no banco descartável e injeção de um gateway fictício no processo; não há identidade de serviço, transação distribuída, revogação autoritativa real, transporte HTTP, contrato elegível, SLA ou reconciliação entre Portal e Sites. Não libera o uso com dados reais ou gasto de fornecedor. Um snapshot antigo que permaneça acessível em laboratório **não pode** representar autorização: o futuro gateway deve buscar sempre a revisão mais recente, sem aceitar um objeto fornecido pelo remetente.

`main`, VPS Leaseweb, Cloudflare, DNS e clientes intactos. OP01/OP03/OP05, P0-DOC/RF-PEND/RV-CT, AppSec/LGPD, readiness BLOCKED e rollback AGI-11-RB01 desabilitado.
