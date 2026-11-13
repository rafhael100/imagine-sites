# CRM-POSTBIND-T0-01 — Segunda verificação do prazo após consulta de vínculo

O mecanismo sintético do Imagine Sites calcula um prazo fixo de T0+432 horas, avalia Retry-After, compara destino e consulta o vínculo CRM novamente. Se essa consulta atravessar o vencimento, um resultado temporal anterior não pode continuar sendo tratado nem mesmo como candidato atual.

Foi adicionada uma nova etapa de análise, **depois** das leituras sintéticas de vínculo, que exige:

- Janela anterior com resultado temporal permitido **apenas como diagnóstico** e dois snapshots de vínculo estáveis no teste.
- Horário informado de início de leitura não anterior ao T0 nem à data de próximo retry; horário de fim posterior ou igual ao início.
- Normalização UTC e recusa de data de próxima tentativa fora da janela original.
- Expiração **exclusiva**: ao atingir exatamente T0+432h, o resultado é `ORIGINAL_T0_EXPIRED_DURING_BINDING_RECHECK`, mesmo que o teste de vínculo anterior tenha passado.
- Nenhuma extensão do T0, reenvio, confirmação, aceite, gravação ou reconhecimento de visitante é autorizada por essa classe.

Testes negativos incluem virada do segundo 432h, atraso, relógio retrocedido, origem indisponível, HTTP 202, espera de provedor pendente e diferenças de fuso horário. PHP 8.3 e 8.4 executam as regressões antigas e novas.

**Limite de segurança:** os horários e snapshots ainda são entradas sintéticas não autenticadas. A proteção real exigirá relógio confiável e operação atômica durável ao reservar o envio, revogação e identidade S2S. Os dez gates de produção continuam `BLOCKED`, e não houve deploy, CRM real, PII, API paga ou merge.