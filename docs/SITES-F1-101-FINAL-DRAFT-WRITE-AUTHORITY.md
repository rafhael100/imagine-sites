# F1-101 — Segunda autorização antes do commit de revisões privadas

## Comportamento

A gravação `LabVerifiedDraftRevisionStore::append()` na F1-95 consulta o gateway ficcional antes do início de sua transação. A F1-101 acrescenta `assertFinalAuthority()` dentro da transação **imediatamente antes do commit**, em dois caminhos:

1. Inserção de nova revisão sequencial em `sites_drafts`, após comprovar a admissão, os hashes e a releitura dos quatro arquivos.
2. Replay idempotente da última revisão, após comprovar igualdade das referências e dos arquivos privados reabertos.

Se a segunda decisão negar ou lançar uma exceção, o código falha fechado com `lab_draft_revision_final_authority_denied` e executa rollback. A sequência de revisões permanece íntegra, sem aprovação de cliente.

## Testes

O teste integrado `tests/f95-pg-verified-draft-revisions.php` usa o gateway falso para revogar exatamente na **segunda** chamada de autorização. Com PostgreSQL 18 real descartável, demonstra rollback da revisão 2, não alteração da revisão já registrada, negação do replay sob revogação e sucesso posterior quando a autorização fictícia retorna. O workflow F1-94 executa nos PHP 8.3 e 8.4.

## Restrições

Este mecanismo é somente laboratório CLI/testing, não faz integração real com o Portal. Mesmo duas autorizações não eliminam revogação posterior ou não sincronizada. O `SET LOCAL` do tenant não autentica empresa, o armazenamento de arquivos não compartilha a transação PostgreSQL e locks advisory não impedem SQL arbitrário. Sem IAM operacional, aceite imutável, endpoint público, deploy, dados reais ou custos. **Dez gates de produção: BLOCKED.**
