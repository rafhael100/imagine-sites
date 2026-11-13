# F1-154 — Retirar outbox técnico expirado sem resetar T0/idempotência

No laboratório PG18, cada alegação técnica de submissão possui um identificador único de organização + submissão e um instante original **T0** gerado na primeira persistência. O outbox da F1-145 é apenas uma representação temporária dessa intenção. Um operador que apague ambos ao fim da janela de 432 horas permitiria um replay ser recebido como primeira ocorrência e **reiniciar artificialmente** o T0.

A F1-154 implementa uma função de manutenção **exclusiva do administrador da base descartável** que remove, em lotes limitados de 1 a 1000 linhas e sob `FOR UPDATE SKIP LOCKED`, apenas outboxes cujo **T0 da identidade original** (`synthetic_claims.created_at`) expirou. A linha do claim original não é removida, preservando a deduplicação e a impossibilidade de recriar a mesma entrega como nova. As roles de aplicação não conseguem executar essa função nem editar as tabelas.

O teste real PostgreSQL18 PHP8.3/8.4 utiliza duas empresas com a mesma submissão, duas entradas expiradas e uma recente. Prova batches de tamanho 1, recusa da chamada por login de aplicação, rejeição de batch inválido, preservação do claim imutável e do T0 após replay, e possibilidade de registrar uma submissão **realmente diferente**.

**Limites legais e operacionais:** não há PII no laboratório, portanto a função não demonstra eliminação LGPD, exclusão em backups, retenção definitiva de identificadores ou política legítima de tombstones por empresa. O prazo operacional de retenção dos identificadores e a eliminação de dados pessoais precisam ser aprovados independentemente. Esta rotina NÃO deve ser executada em ambientes reais. OP04 #36 e os 10 gates seguem bloqueados; nenhum merge, deploy, VPS, dado real ou despesa.
