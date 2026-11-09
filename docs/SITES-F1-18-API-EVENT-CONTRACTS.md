# SITES-F1-18 — Contratos candidatos OpenAPI/AsyncAPI para Portal ↔ Sites

**Estado:** artefatos documentais e testes estáticos. Não são endpoints operacionais, servidor, broker, filas ou produto pronto para clientes.

- `contracts/portal-sites/v1/openapi.candidate.json`: OpenAPI 3.1, catálogo de CreateProject, leitura de projetos por tenant, aceite expresso da release, pedido de publicação técnica, consulta de observação e revogação. Propriedade dos estados é separada entre Portal (cliente, contrato e ENTREGUE) e Sites (projeto, manifesto e publicação técnica).
- `contracts/portal-sites/v1/asyncapi.candidate.json`: AsyncAPI 3.0 para eventos de projeto, release, observação de publicação, elegibilidade de entrega e revogação. Evento contém somente referências opacas; não inclui briefing ou payload pessoal.
- `contracts/portal-sites/v1/candidate-envelope.schema.json`: esquema do envelope HMAC sintético CreateProject v1, ligado ao JSON Schema de origem. A validação de JSON Schema **não substitui assinatura criptográfica ou revisão de autoridade**.
- `tests/f18_contracts.py`: verificador estático sem bibliotecas de terceiros para campos/versões, restrição mTLS proposta, status 202 não final, erros de autorização/conflito, ausência de hosts e brokers reais.

**Pontos de decisão ainda em aberto:** arquitetura exata de identidade serviço-serviço, rotação/revogação de certificados/chaves, assinatura de aprovação cliente, licença e retenção de artefatos, limites de payload/eventos, materialização de webhooks, semântica de replay e quotas. mTLS consta como **candidato**, não implantação ou custo autorizado.

**Nenhuma rota de F1-18 existe em apps/sites**. A rota do F1-13 permanece apenas no PHP CLI de testes sintéticos. Não publicar documentos de especificação como se fossem contratos homologados.

**Gates:** P0-DOC01–14, P0-DOC03, RF-PEND-01/RF-PEND-04, RV-CT, AppSec/LGPD, custo e homologação abertos. Sem merge/deploy automático, dados reais ou novos fornecedores. AGI-11-RB01 rollback desabilitado.
