# F1-98 — Lock compartilhado para leitura da revisão privada vigente

A F1-95 serializa operações de escrita por empresa/site com `pg_advisory_xact_lock(hashtextextended(key, 0))`. A F1-96 consultava a revisão vigente sem adquirir o mesmo lock. A F1-98 introduz um **lock transacional compartilhado** via `pg_advisory_xact_lock_shared`, após o contexto de empresa ter sido definido e antes das consultas de admissão/revisão. O lock é mantido até COMMIT ou ROLLBACK.

A chave é a mesma do gravador: comprimento da referência de empresa, empresa e site derivado do pedido/contrato. Leitores cooperantes podem executar simultaneamente; gravações F1-95 do mesmo site aguardam até o fim da leitura. As gravações de outros sites continuam independentes.

A suíte integrada `tests/f95-pg-verified-draft-revisions.php` agora comprova compatibilidade compartilhada/exclusiva usando duas conexões PostgreSQL 18 reais, além da releitura da revisão atual e das negativas já existentes. Os cenários são executados pelo workflow `sites-f1-94-project-admission.yml` com PHP 8.3 e 8.4.

**Limitações:** locks advisory dependem de ambos os serviços respeitarem a mesma chave; conexões SQL arbitrárias podem ignorá-los ou forjar a GUC do tenant. O filesystem não participa de transações do banco. Portanto, não há atomicidade banco/disco, garantia de bytes imutáveis depois da leitura, Portal IAM real, aceite humano ou publicação. Continua somente CLI/testing com acesso restrito e `10/10` gates operacionais bloqueados.
