# F1-278 — Classificação segura das respostas técnicas do CRM

A integração futura do Imagine Sites não pode interpretar a resposta HTTP isolada como entrega comprovada de um lead. Esta entrega cria um avaliador puro de estados de protocolo para uso posterior num coordenador autenticado.

- **HTTP 202:** apenas aceitação para processamento. Resultado `PROVIDER_202_PENDING_EXTERNAL_OBSERVATION`; não confirma entrega, não permite reenvio automático e exige observação externa.
- **HTTP 200/201/204:** transporte aparentemente bem-sucedido, mas ainda não comprova entrega, release atual, vinculação ou aceite. Verificação independente continua necessária.
- **HTTP 409:** conflito de idempotência requer reconciliação; nunca pode ser tratado como sucesso ou oportunidade para mudar o identificador e duplicar a submissão.
- **HTTP 408/425/429/500/502/503/504:** uma nova tentativa pode ser considerada apenas temporalmente, com a autorização atual ainda pendente. Cabeçalho `Retry-After` é aceito somente como inteiro decimal estrito entre 0 e 86400 segundos; datas, caracteres estranhos e números excessivos negam o planejamento.
- **HTTP 400/401/403/404/410/422** exigem revisão técnica/autoridade; status desconhecidos falham fechados.
- Nenhuma classificação permite confirmar entrega, autorizar reenvio imediato, responder sucesso ao visitante, substituir T0 ou ignorar vínculo CRM.

Mais de cem verificações negativas e positivas rodam sem rede, PII, dados de clientes ou bancos. A avaliação não prova assinatura da resposta, consentimento, token ou observação real. Os dez gates operacionais permanecem `BLOCKED`.