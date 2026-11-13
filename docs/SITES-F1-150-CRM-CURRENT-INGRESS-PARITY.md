# F1-150 — Compatibilidade do Sites com código real do CRM F3-48/F3-49

Em vez de conferir apenas o antigo contrato CRM F3-11/F3-12 (SHA `a2f29c8e...`), o teste F1-150 baixa e executa os arquivos PHP do CRM fixados no SHA **`0ee32ec1268047a52b5429622fb5f34716f818ae`** (candidato PR CRM #232, que inclui a composição F3-46/47/48) em GitHub Actions PHP 8.3 e 8.4.

O teste `tests/f150-crm-current-technical-ingress-parity.php` usa o próprio `SyntheticCrmLeadEnvelopeV1Draft` do Sites para construir os oito campos de metadados e chama as classes **reais** `PortalTechnicalEnvelopeDecoder` e `PortalIngressPreparationBoundary` do CRM. Prova compatibilidade do JSON estrito, da integridade HMAC sintética de *deliver/lookup* e verifica falhas diante de campo extra semelhante a PII, JSON aninhado, chaves duplicadas, JSON truncado, destino de vínculo divergente, MAC adulterado, janela vencida, operação desconhecida e corpo acima de 2.048 bytes.

**Proibição de falsas permissões:** até um HMAC válido com binding preparado no laboratório só produz `LAB_TECHNICAL_METADATA_MATCH_UNTRUSTED`. Todas as capacidades de autenticar serviço, aceitar/persistir lead, emitir ACK, enfileirar entrega, repetir resultado desconhecido e abrir rota pública permanecem `false`. Uma cópia de metadados como binding no teste não constitui autorização independente em produção.

Nenhum dado pessoal, formulário, token, rede CRM, banco, API operacional, assinatura real, cofre, consentimento ou recibo é utilizado. O SHA está fixado para reprodutibilidade — **não demonstra compatibilidade com mudanças posteriores do CRM**. Revisão AppSec/DBA e os gates operacionais continuam bloqueados, inclusive OP04 #36 e 10/10 gates. Sem merge em dev/main, deploy ou custos.
